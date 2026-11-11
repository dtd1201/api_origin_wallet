<?php

namespace App\Services\Nium;

use App\Support\SensitiveDataSanitizer;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

/** Invoked only by nium:smoke-test's early, isolated evidence branch. */
final class NiumEntitlementEvidenceCollector
{
    public function __construct(
        private readonly NiumEntitlementEvidenceTransport $transport,
        private readonly NiumEntitlementEvidenceProjector $projector,
    ) {}

    public function run(Command $command, InputInterface $input): int
    {
        try {
            if (! $command->option('entitlement-evidence') || $command->argument('userId') !== null) {
                throw new \RuntimeException;
            }
            foreach (['sync', 'quote', 'full', 'client-capabilities', 'compliance-callback'] as $option) {
                if ($command->option($option)) {
                    throw new \RuntimeException;
                }
            }
            foreach (['--source-currency', '--target-currency', '--amount'] as $option) {
                if ($input->hasParameterOption($option)) {
                    throw new \RuntimeException;
                }
            }
            $selected = $command->option('evidence-corridor');
            if (! is_array($selected) || count($selected) > 10) {
                throw new \RuntimeException;
            }
            $scope = NiumEntitlementEvidenceScope::resolve((string) $command->option('evidence-scope'),
                (string) $command->option('evidence-environment'), $selected);
            $document = ['format_version' => 1, 'mode' => $command->option('live') ? 'LIVE' : 'PLAN',
                'scope' => $scope->projection(), 'entitlement_decision' => 'UNKNOWN',
                'collection_started_at_utc' => now('UTC')->toISOString(),
                'source' => $command->option('live') ? 'authenticated_provider_response' : 'not_collected',
                'endpoint_allowlist' => NiumEntitlementEvidenceTransport::ENDPOINTS];
            if (! $command->option('live')) {
                $document['status'] = 'NOT_COLLECTED';
                $document['results'] = [];
            } else {
                // Validate isolation before the first request; never invoke the old smoke path.
                $this->transport->assertIsolated($scope);
                $document['results'] = $this->collect($scope);
                $document['status'] = array_filter($document['results'], fn ($entry) => $entry['status'] !== 'COMPLETE') === []
                    ? 'COMPLETE' : 'INCOMPLETE';
            }
            $document['collection_finished_at_utc'] = now('UTC')->toISOString();
            $document['sha256_basis'] = 'compact-json-without-sanitized_sha256';
            $document = (new SensitiveDataSanitizer)->sanitize($document);
            $document['sanitized_sha256'] = hash('sha256', json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $command->line(json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $document['status'] === 'INCOMPLETE' ? 2 : 0;
        } catch (Throwable) {
            // Never interpolate command inputs, credentials, scope identifiers or exception text.
            $command->error('Entitlement evidence rejected: incompatible mode, unverified scope, or unavailable isolation.');

            return 1;
        }
    }

    private function collect(NiumEntitlementEvidenceScope $scope): array
    {
        $results = [$this->single($scope, 'client_details')];
        if ($scope->corridors === []) {
            $results[] = ['endpoint' => 'supported_corridors', 'status' => 'UNKNOWN', 'reason' => 'no_selected_corridor_scope'];
        } else {
            foreach ($scope->corridors as $alias => $filters) {
                $results[] = $this->pages($scope, $alias, $filters);
            }
        }
        if ($scope->customerId() === null) {
            $results[] = ['endpoint' => 'customer_corridors', 'status' => 'UNKNOWN', 'reason' => 'no_verified_customer_scope'];
        } else {
            $results[] = $this->single($scope, 'customer_corridors');
        }
        foreach ($scope->corridors as $alias => $filters) {
            foreach (['beneficiary_schema', 'payout_schema'] as $endpoint) {
                $results[] = ['corridor_alias' => $alias, ...$this->single($scope, $endpoint, $filters)];
            }
        }
        $results[] = $this->single($scope, 'fee_plans');
        $results[] = $this->single($scope, 'purpose_codes');

        return $results;
    }

    private function single(NiumEntitlementEvidenceScope $scope, string $endpoint, array $filters = []): array
    {
        $read = $this->transport->read($scope, $endpoint, $filters);
        if ($read['failure'] !== null) {
            return ['endpoint' => $endpoint, 'status' => 'INCOMPLETE', 'manifest' => $read['manifest'], 'reason' => $read['failure']];
        }

        return ['endpoint' => $endpoint, 'manifest' => $read['manifest'],
            ...$this->projector->project($endpoint, $read['body'], $scope, $filters)];
    }

    private function pages(NiumEntitlementEvidenceScope $scope, string $alias, array $filters): array
    {
        $pages = [];
        $expectedPages = null;
        $expectedRows = null;
        $received = 0;
        $complete = false;
        $reason = null;
        for ($page = 0; $page < 100; $page++) {
            $read = $this->transport->read($scope, 'supported_corridors', $filters, $page);
            if ($read['failure'] !== null) {
                $pages[] = ['manifest' => $read['manifest'], 'status' => 'INCOMPLETE', 'reason' => $read['failure']];
                $reason = $read['failure'];
                break;
            }
            $projection = $this->projector->project('supported_corridors', $read['body'], $scope, $filters);
            $pages[] = ['manifest' => $read['manifest'], ...$projection];
            $data = $projection['data'];
            if ($projection['status'] === 'INCOMPLETE' || ! isset($data['total_pages'], $data['total_elements'])) {
                $reason = 'malformed_corridor_page';
                break;
            }
            $expectedPages ??= $data['total_pages'];
            $expectedRows ??= $data['total_elements'];
            if ($expectedPages !== $data['total_pages'] || $expectedRows !== $data['total_elements']
                || $expectedPages > 100 || ($expectedPages === 0 && $expectedRows !== 0)) {
                $reason = 'pagination_inconsistent_or_limit';
                break;
            }
            $received += count($data['rows']);
            if ($page + 1 >= max(1, $expectedPages)) {
                $complete = $received === $expectedRows;
                $reason = $complete ? null : 'pagination_row_count_mismatch';
                break;
            }
        }

        return ['endpoint' => 'supported_corridors', 'corridor_alias' => $alias,
            'status' => ! $complete ? 'INCOMPLETE' : (array_filter($pages, fn ($entry) => $entry['status'] !== 'COMPLETE') === [] ? 'COMPLETE' : 'UNKNOWN'),
            'pagination' => ['filters' => $filters, 'page_size' => 100, 'max_pages' => 100,
                'reported_total_pages' => $expectedPages, 'reported_total_elements' => $expectedRows,
                'retrieved_pages' => count($pages), 'received_rows' => $received, 'pagination_complete' => $complete,
                'snapshot_consistency' => 'UNKNOWN'],
            'reason' => $reason ?? (! $complete ? 'pagination_incomplete' : null), 'pages' => $pages];
    }
}
