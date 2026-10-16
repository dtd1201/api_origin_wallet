<?php

namespace App\Services\Nium;

use App\Models\NiumRfiCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class NiumCorporateRfiAuthoritativeTransport
{
    public function __construct(
        private readonly NiumCorporateRfiAuthoritativeSubmissionOrchestrator $orchestrator,
    ) {}

    /** Contract-test harness only; Production activation requires a separately verified response contract. */
    public function sendForContractTest(NiumRfiCase $case): array
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Authoritative Corporate RFI transport is not activated.');
        }
        if (! Http::preventingStrayRequests()) {
            throw new RuntimeException('Authoritative Corporate RFI contract transport requires stray HTTP requests to be prevented.');
        }

        $baseUrl = rtrim((string) config('services.nium.base_url'), '/');
        $parts = parse_url($baseUrl);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'gateway.test.invalid'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '')) {
            throw new RuntimeException('Authoritative Corporate RFI contract transport requires the reserved test gateway.');
        }

        $auth = (array) config('services.nium.auth', []);
        $headerName = trim((string) ($auth['header_name'] ?? ''));
        $credential = (string) ($auth['header_value'] ?? '');
        if (($auth['mode'] ?? null) !== 'header'
            || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $headerName) !== 1
            || in_array(strtolower($headerName), ['authorization', 'cookie', 'host', 'content-length'], true)
            || ! str_starts_with($credential, 'test-')
            || strlen($credential) < 9 || strlen($credential) > 128
            || preg_match('/[\x00-\x20\x7f]/', $credential) === 1) {
            throw new RuntimeException('Authoritative Corporate RFI contract transport requires a safe synthetic test credential.');
        }

        $plan = $this->orchestrator->dryRun($case);
        if (($plan['executable'] ?? null) !== false) {
            throw new RuntimeException('Authoritative Corporate RFI transport requires a non-executable candidate.');
        }

        $requestId = (string) data_get($plan, 'request.headers.x-request-id');
        $url = $baseUrl.data_get($plan, 'request.endpoint');
        $headers = [
            ...data_get($plan, 'request.headers'),
            $headerName => $credential,
        ];

        try {
            $response = Http::timeout((int) config('services.nium.timeout', 30))
                ->withHeaders($headers)
                ->post($url, data_get($plan, 'request.body'));
        } catch (ConnectionException) {
            return $this->outcome($requestId, null, 'UNKNOWN', true, 'network_or_transport_uncertainty');
        }

        $status = $response->status();
        if (in_array($status, [400, 422], true)) {
            return $this->outcome($requestId, $status, 'REJECTED', false, 'deterministic_validation_rejection');
        }
        if (in_array($status, [408, 429], true) || $status >= 500) {
            return $this->outcome($requestId, $status, 'UNKNOWN', true, 'provider_outcome_uncertain');
        }

        // HTTP success alone cannot establish RFI_RESPONDED until the Legacy response schema is verified.
        return $this->outcome($requestId, $status, 'UNKNOWN', true, 'legacy_response_contract_unverified');
    }

    private function outcome(string $requestId, ?int $status, string $outcome, bool $reconcile, string $reason): array
    {
        return array_filter([
            'x_request_id' => $requestId,
            'request_correlation_fingerprint' => hash('sha256', $requestId),
            'http_status' => $status,
            'outcome' => $outcome,
            'manual_reconciliation_required' => $reconcile,
            'reason' => $reason,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
