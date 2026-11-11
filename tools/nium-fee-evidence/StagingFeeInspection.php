<?php

declare(strict_types=1);

namespace NiumFeeEvidence;

use App\Services\Nium\NiumEntitlementEvidenceScope;
use App\Services\Nium\NiumEntitlementEvidenceTransport;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use RuntimeException;
use Throwable;

/** Temporary CLI support. Never boots Laravel or reads deployed config/.env/DB. */
final class StagingFeeInspection
{
    public const ENVIRONMENT_NAMES = [
        'APP_ENV', 'NIUM_BASE_URL', 'NIUM_CLIENT_ID', 'NIUM_API_KEY',
        'NIUM_REGULATORY_REGION', 'NIUM_AUTH_MODE', 'NIUM_AUTH_HEADER_NAME',
        'NIUM_FEE_STAGING_APPROVAL_FILE',
    ];

    public static function readApproval(string $path, string $repository): array
    {
        $real = realpath($path);
        $root = realpath($repository);
        if ($real === false || $root === false || is_link($path) || ! is_file($real)
            || ! is_readable($real) || ($real === $root || str_starts_with($real, $root.DIRECTORY_SEPARATOR))
            || (fileperms($real) & 0077) !== 0 || filesize($real) > 8192) {
            throw new RuntimeException('staging_approval_file_rejected');
        }
        $raw = file_get_contents($real);
        if (! is_string($raw) || strlen($raw) > 8192) {
            throw new RuntimeException('staging_approval_file_rejected');
        }
        $approval = FeeConfigurationProjection::decodeObject($raw);

        return get_object_vars($approval);
    }

    /** Validate BEFORE constructing a transport or installing a process-local container. */
    public static function configuration(array $environment, array $approval): array
    {
        $required = ['APP_ENV', 'NIUM_BASE_URL', 'NIUM_CLIENT_ID', 'NIUM_API_KEY', 'NIUM_REGULATORY_REGION'];
        foreach ($required as $name) {
            if (! is_string($environment[$name] ?? null) || $environment[$name] === '') {
                throw new RuntimeException('staging_configuration_rejected');
            }
        }
        $keys = ['version', 'app_environment', 'provider_environment', 'origin', 'client_sha256',
            'api_key_sha256', 'regulatory_region', 'approval_reference_sha256'];
        if (array_diff(array_keys($approval), $keys) !== [] || array_diff($keys, array_keys($approval)) !== []
            || $environment['APP_ENV'] !== 'staging' || $approval['version'] !== 1
            || $approval['app_environment'] !== 'staging' || $approval['provider_environment'] !== 'sandbox'
            || ($environment['NIUM_AUTH_MODE'] ?? 'header') !== 'header'
            || ! is_string($environment['NIUM_AUTH_HEADER_NAME'] ?? 'x-api-key')
            || strtolower($environment['NIUM_AUTH_HEADER_NAME'] ?? 'x-api-key') !== 'x-api-key') {
            throw new RuntimeException('staging_configuration_rejected');
        }
        foreach (['client_sha256', 'api_key_sha256', 'approval_reference_sha256'] as $field) {
            if (! is_string($approval[$field]) || preg_match('/^[a-f0-9]{64}$/D', $approval[$field]) !== 1) {
                throw new RuntimeException('staging_configuration_rejected');
            }
        }
        $origin = $approval['origin'];
        $parts = is_string($origin) ? parse_url($origin) : false;
        if (! is_array($parts) || array_keys($parts) !== ['scheme', 'host'] || $parts['scheme'] !== 'https'
            || preg_match('/^(?:[a-z0-9-]+\.)*nium\.com$/D', $parts['host']) !== 1
            || ! in_array($environment['NIUM_BASE_URL'], [$origin, $origin.'/'], true)
            || preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', $environment['NIUM_CLIENT_ID']) !== 1
            || preg_match('/^[A-Z]{2}$/D', $environment['NIUM_REGULATORY_REGION']) !== 1
            || $environment['NIUM_REGULATORY_REGION'] !== $approval['regulatory_region']
            || strlen($environment['NIUM_API_KEY']) < 16 || strlen($environment['NIUM_API_KEY']) > 4096
            || preg_match('/[\x00-\x20\x7f]/', $environment['NIUM_API_KEY'])
            || ! hash_equals($approval['client_sha256'], hash('sha256', $environment['NIUM_CLIENT_ID']))
            || ! hash_equals($approval['api_key_sha256'], hash('sha256', $environment['NIUM_API_KEY']))) {
            throw new RuntimeException('staging_configuration_rejected');
        }

        // New private repository only. No application configuration is read/set.
        return [
            'app' => ['env' => 'staging'],
            'services' => ['nium' => ['base_url' => $origin, 'client_id' => $environment['NIUM_CLIENT_ID'],
                'regulatory_region' => $environment['NIUM_REGULATORY_REGION'],
                'auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => $environment['NIUM_API_KEY']]]],
            'nium_entitlement_evidence' => [
                'approved_origins' => ['sandbox' => [$origin], 'production' => []],
                'scopes' => ['scope-00000001' => [
                    'verified' => true, 'verification_sha256' => $approval['approval_reference_sha256'],
                    'app_environment' => 'staging', 'provider_environment' => 'sandbox', 'origin' => $origin,
                    'client_hash_id' => $environment['NIUM_CLIENT_ID'], 'client_alias' => 'client-00000001',
                    'customer_hash_id' => null, 'customer_alias' => null,
                    'regulatory_region' => $environment['NIUM_REGULATORY_REGION'], 'corridors' => [],
                ]],
            ],
        ];
    }

    /** A transport may be supplied only by offline tests; the CLI constructs the real isolated transport. */
    public static function collect(array $environment, array $approval, ?NiumEntitlementEvidenceTransport $transport = null): array
    {
        $settings = self::configuration($environment, $approval);
        $previous = Container::getInstance();
        $private = new Container;
        $private->instance('config', new Repository($settings));
        Container::setInstance($private);
        try {
            $scope = NiumEntitlementEvidenceScope::resolve('scope-00000001', 'sandbox', []);
            $projectionFailed = false;
            $read = ($transport ?? new NiumEntitlementEvidenceTransport)->read($scope, 'fee_plans', [], 0, 'GET',
                static function (string $raw) use (&$projectionFailed): array {
                    try {
                        return (new FeeConfigurationProjection)->project($raw);
                    } catch (Throwable $exception) {
                        $projectionFailed = true;
                        throw $exception;
                    }
                });
            $result = self::failure($projectionFailed ? 'fee_response_invalid' : ($read['failure'] ?? ''));
            $status = $read['manifest']['http_status'] ?? null;
            $result['http_status'] = is_int($status) ? $status : null;
            $result['request_id'] = $read['manifest']['request_id'];
            $result['retrieved_at_utc'] = $read['manifest']['retrieved_at_utc'] ?? null;
            if (is_int($status) && $status >= 300 && $status < 400) {
                $result['failure'] = 'unexpected_redirect';
            }
            if ($read['failure'] === null) {
                $result['fee_configuration'] = $read['body']['plans'];
                $result['missing_fields'] = $read['body']['missing_fields'];
                $result['unsupported_fields'] = $read['body']['unsupported_fields'];
                $result['status'] = $result['missing_fields'] === [] && $result['unsupported_fields'] === []
                    ? 'OBSERVED' : 'INCOMPLETE';
                $result['failure'] = null;
            }

            return self::redact($result, $environment);
        } finally {
            Container::setInstance($previous);
        }
    }

    public static function failure(string $code): array
    {
        return [
            'schema_version' => 1, 'status' => 'FAILED', 'environment' => 'staging',
            'endpoint_template' => '/api/v3/client/{clientHashId}/fees',
            'http_status' => null, 'request_id' => null, 'retrieved_at_utc' => null, 'failure' => $code,
            'fee_configuration' => [], 'missing_fields' => [], 'unsupported_fields' => [],
            // No collection field is defined by the verified v3 schema. Unknown
            // response extensions are reported, never interpreted as collection.
            'fee_collection' => ['status' => 'UNVERIFIED'],
            'financial_authority' => ['customer_applicability' => 'UNKNOWN', 'transaction_fee' => 'UNKNOWN',
                'nium_wallet_debit' => 'UNKNOWN', 'origin_wallet_debit' => 'UNKNOWN',
                'admission' => 'DENIED', 'dispatch' => 'DENIED'],
        ];
    }

    /** Final defense even if a secret happens to look like a decimal or enum. */
    public static function redact(array $output, array $environment): array
    {
        $secrets = array_values(array_filter([
            $environment['NIUM_API_KEY'] ?? null, $environment['NIUM_CLIENT_ID'] ?? null,
        ], static fn ($value) => is_string($value) && $value !== ''));
        $redacted = false;
        array_walk_recursive($output, static function (&$value) use ($secrets, &$redacted): void {
            if (is_string($value)) {
                $safe = str_replace($secrets, '[REDACTED]', $value);
                $redacted = $redacted || $safe !== $value;
                $value = $safe;
            }
        });
        if ($redacted && ($output['status'] ?? null) === 'OBSERVED') {
            $output['status'] = 'INCOMPLETE';
            $output['unsupported_fields'][] = ['path' => 'output', 'reason' => 'secret_value_redacted'];
        }

        return $output;
    }
}
