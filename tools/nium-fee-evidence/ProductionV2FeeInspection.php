<?php

declare(strict_types=1);

namespace NiumFeeEvidence;

use App\Services\Nium\NiumEntitlementEvidenceScope;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use RuntimeException;
use Throwable;

/** V2-only copy of the reviewed Production approval boundary; never authorizes V3. */
final class ProductionV2FeeInspection
{
    private function synchronizeControlDirectory(): void
    {
        // File fsync alone does not persist the new directory entry across a
        // host crash. A filesystem/runtime unable to sync it must fail closed.
        $directory = fopen($this->directory, 'r');
        if ($directory === false) {
            throw new RuntimeException('production_approval_directory_sync_failed');
        }
        try {
            if (!fsync($directory)) {
                throw new RuntimeException('production_approval_directory_sync_failed');
            }
        } finally {
            fclose($directory);
        }
    }

    public const CONTROL_DIRECTORY = '/var/lib/origin-wallet/nium-fee-inspection';

    public const OPERATION = 'nium.production.v2.fee_configuration.read.v1';

    public const PATH = '/api/v2/client/{clientHashId}/fees';

    public const ENVIRONMENT_NAMES = [
        'APP_ENV', 'NIUM_BASE_URL', 'NIUM_CLIENT_ID', 'NIUM_API_KEY',
        'NIUM_REGULATORY_REGION', 'NIUM_AUTH_MODE', 'NIUM_AUTH_HEADER_NAME',
        'NIUM_FEE_PRODUCTION_V2_APPROVAL_FILE',
    ];

    private bool $consumed = false;

    private function __construct(private readonly array $approval, private readonly string $directory) {}

    /** Pure policy check. Runtime identity/time are supplied by the trusted caller, never CLI options. */
    public static function validate(array $environment, array $approval, DateTimeImmutable $at, int $uid, string $hostname): void
    {
        $keys = ['version', 'operation', 'method', 'path_template', 'request_limit', 'app_environment',
            'provider_environment', 'origin', 'client_sha256', 'api_key_sha256', 'regulatory_region',
            'approval_id', 'issued_at_utc', 'expires_at_utc', 'operator_uid', 'web_runtime_uid',
            'host_sha256', 'approval_reference_sha256', 'outside_web_roots', 'manual_execution_only'];
        if (array_diff(array_keys($approval), $keys) !== [] || array_diff($keys, array_keys($approval)) !== []) {
            throw new RuntimeException('production_approval_rejected');
        }
        foreach (['APP_ENV', 'NIUM_BASE_URL', 'NIUM_CLIENT_ID', 'NIUM_API_KEY', 'NIUM_REGULATORY_REGION'] as $field) {
            if (! is_string($environment[$field] ?? null) || $environment[$field] === '') {
                throw new RuntimeException('production_identity_rejected');
            }
        }
        if ($environment['APP_ENV'] !== 'production' || $approval['version'] !== 1
            || $approval['operation'] !== self::OPERATION || $approval['method'] !== 'GET'
            || $approval['path_template'] !== self::PATH || $approval['request_limit'] !== 1
            || $approval['app_environment'] !== 'production' || $approval['provider_environment'] !== 'production'
            || $approval['outside_web_roots'] !== true || $approval['manual_execution_only'] !== true
            || ! is_int($approval['operator_uid']) || $approval['operator_uid'] !== $uid || $uid < 0
            || ! is_int($approval['web_runtime_uid']) || $approval['web_runtime_uid'] < 0
            || $approval['web_runtime_uid'] === $uid
            || ($environment['NIUM_AUTH_MODE'] ?? 'header') !== 'header'
            || ! is_string($environment['NIUM_AUTH_HEADER_NAME'] ?? 'x-api-key')
            || strtolower($environment['NIUM_AUTH_HEADER_NAME'] ?? 'x-api-key') !== 'x-api-key') {
            throw new RuntimeException('production_approval_rejected');
        }
        foreach (['client_sha256', 'api_key_sha256', 'host_sha256', 'approval_reference_sha256'] as $field) {
            if (! is_string($approval[$field]) || preg_match('/^[a-f0-9]{64}$/D', $approval[$field]) !== 1) {
                throw new RuntimeException('production_approval_rejected');
            }
        }
        $origin = $approval['origin'];
        $parts = is_string($origin) ? parse_url($origin) : false;
        if (! is_array($parts) || array_keys($parts) !== ['scheme', 'host'] || $parts['scheme'] !== 'https'
            || preg_match('/^(?:[a-z0-9-]+\.)*nium\.com$/D', $parts['host']) !== 1
            || ! in_array($environment['NIUM_BASE_URL'], [$origin, $origin.'/'], true)
            || preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', $environment['NIUM_CLIENT_ID']) !== 1
            || preg_match('/^[A-Z]{2}$/D', $environment['NIUM_REGULATORY_REGION']) !== 1
            || $approval['regulatory_region'] !== $environment['NIUM_REGULATORY_REGION']
            || strlen($environment['NIUM_API_KEY']) < 16 || strlen($environment['NIUM_API_KEY']) > 4096
            || preg_match('/[\x00-\x20\x7f]/', $environment['NIUM_API_KEY'])
            || ! hash_equals($approval['client_sha256'], hash('sha256', $environment['NIUM_CLIENT_ID']))
            || ! hash_equals($approval['api_key_sha256'], hash('sha256', $environment['NIUM_API_KEY']))
            || $hostname === '' || ! hash_equals($approval['host_sha256'], hash('sha256', $hostname))
            || ! is_string($approval['approval_id'])
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $approval['approval_id']) !== 1
            || strcasecmp($approval['approval_id'], $environment['NIUM_CLIENT_ID']) === 0
            || str_contains($approval['approval_id'], $environment['NIUM_API_KEY'])) {
            throw new RuntimeException('production_identity_rejected');
        }
        $issued = self::instant($approval['issued_at_utc']);
        $expires = self::instant($approval['expires_at_utc']);
        if ($issued > $at || $expires <= $at || $expires <= $issued
            || $expires->getTimestamp() - $issued->getTimestamp() > 900) {
            throw new RuntimeException('production_approval_expired_or_not_current');
        }
    }

    /** The directory argument is an offline-test seam; the CLI always uses the fixed default. */
    public static function open(array $environment, string $repository, string $directory = self::CONTROL_DIRECTORY): self
    {
        if (($environment['APP_ENV'] ?? null) !== 'production') {
            throw new RuntimeException('production_identity_rejected');
        }
        [$uid, $hostname] = self::runtime();
        $root = realpath($repository);
        $real = realpath($directory);
        if ($root === false || $real === false || $real !== $directory || is_link($directory)
            || $real === $root || str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('production_control_directory_rejected');
        }
        self::privatePath($real, $uid, true);
        $path = $environment['NIUM_FEE_PRODUCTION_V2_APPROVAL_FILE'] ?? null;
        if (! is_string($path) || dirname($path) !== $real || realpath($path) !== $path || is_link($path)) {
            throw new RuntimeException('production_approval_file_rejected');
        }
        self::privatePath($path, $uid, false);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('production_approval_file_rejected');
        }
        try {
            $stat = fstat($handle);
            if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
                || ($stat['mode'] & 0777) !== 0600 || $stat['uid'] !== $uid || $stat['size'] > 8192) {
                throw new RuntimeException('production_approval_file_rejected');
            }
            $raw = stream_get_contents($handle, 8193);
            if (! is_string($raw) || strlen($raw) > 8192) {
                throw new RuntimeException('production_approval_file_rejected');
            }
        } finally {
            fclose($handle);
        }
        $approval = get_object_vars(FeeConfigurationProjection::decodeObject($raw));
        self::validate($environment, $approval, self::now(), $uid, $hostname);
        if (basename($path) !== 'approval-'.$approval['approval_id'].'.json') {
            throw new RuntimeException('production_approval_file_rejected');
        }
        $inspection = new self($approval, $real);
        $inspection->assertUnused();

        return $inspection;
    }

    public function approvalId(): string
    {
        return $this->approval['approval_id'];
    }

    public function wasConsumed(): bool
    {
        return $this->consumed;
    }

    /** Claims are permanent, including HTTP failure, timeout or a crash before I/O. */
    private function consume(array $environment): void
    {
        [$uid, $hostname] = self::runtime();
        self::validate($environment, $this->approval, self::now(), $uid, $hostname);
        self::privatePath($this->directory, $uid, true);
        $this->assertUnused();
        $oldMask = umask(0077);
        try {
            // O_EXCL is the cross-process authority: existence checks alone are not.
            $handle = fopen($this->marker(), 'x+b');
        } finally {
            umask($oldMask);
        }
        if ($handle === false) {
            throw new RuntimeException('production_approval_claim_failed');
        }
        $this->consumed = true;
        try {
            $stat = fstat($handle);
            if (! is_array($stat) || ($stat['mode'] & 0777) !== 0600 || $stat['uid'] !== $uid) {
                throw new RuntimeException('production_approval_claim_failed');
            }
            $receipt = json_encode(['operation' => self::OPERATION, 'approval_id' => $this->approvalId(),
                'claimed_at_utc' => self::now()->format('Y-m-d\TH:i:s\Z'),
                'meaning' => 'one_provider_read_attempt_claimed_not_proof_of_http_dispatch'], JSON_THROW_ON_ERROR)."\n";
            if (fwrite($handle, $receipt) !== strlen($receipt) || ! fflush($handle)
                || ! function_exists('fsync') || ! fsync($handle)) {
                throw new RuntimeException('production_approval_claim_failed');
            }
        } finally {
            fclose($handle);
        }
    }

    /** Test transport injection is never exposed by the CLI. No ordinary NiumService or application bootstrap. */
    public function collect(array $environment, ?ProductionV2FeeTransport $transport = null): array
    {
        [$uid, $hostname] = self::runtime();
        self::validate($environment, $this->approval, self::now(), $uid, $hostname);
        $origin = $this->approval['origin'];
        $settings = ['app' => ['env' => 'production'], 'services' => ['nium' => [
            'base_url' => $origin, 'client_id' => $environment['NIUM_CLIENT_ID'],
            'regulatory_region' => $environment['NIUM_REGULATORY_REGION'],
            'auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => $environment['NIUM_API_KEY']],
        ]], 'nium_entitlement_evidence' => ['approved_origins' => ['sandbox' => [], 'production' => [$origin]],
            'scopes' => ['scope-00000001' => [
                'verified' => true, 'verification_sha256' => $this->approval['approval_reference_sha256'],
                'app_environment' => 'production', 'provider_environment' => 'production', 'origin' => $origin,
                'client_hash_id' => $environment['NIUM_CLIENT_ID'], 'client_alias' => 'client-00000001',
                'customer_hash_id' => null, 'customer_alias' => null,
                'regulatory_region' => $environment['NIUM_REGULATORY_REGION'], 'corridors' => [],
            ]]]];
        $previous = Container::getInstance();
        $private = new Container;
        $private->instance('config', new Repository($settings));
        Container::setInstance($private);
        try {
            $scope = NiumEntitlementEvidenceScope::resolve('scope-00000001', 'production', []);
            $transport ??= new ProductionV2FeeTransport;
            $transport->assertIsolated($scope);
            $this->consume($environment);
            $this->synchronizeControlDirectory();
            $parseFailed = false;
            $read = $transport->read($scope,
                static function (string $raw) use (&$parseFailed): array {
                    try {
                        return (new V2FeeConfigurationProjection)->project($raw);
                    } catch (Throwable $exception) {
                        $parseFailed = true;
                        throw $exception;
                    }
                });
            $result = $this->failure($parseFailed ? 'fee_response_schema_mismatch' : ($read['failure'] ?? ''));
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
                $result['status'] = $result['missing_fields'] === [] && $result['unsupported_fields'] === [] ? 'OBSERVED' : 'INCOMPLETE';
                $result['failure'] = null;
            }

            return StagingFeeInspection::redact($result, $environment);
        } finally {
            Container::setInstance($previous);
        }
    }

    public function failure(string $code): array
    {
        return ['schema_version' => 1, 'status' => 'FAILED', 'environment' => 'production',
            'endpoint_template' => self::PATH, 'approval_id' => $this->approvalId(),
            'approval_consumed' => $this->consumed, 'http_status' => null, 'request_id' => null,
            'retrieved_at_utc' => null, 'failure' => $code, 'fee_configuration' => [],
            'missing_fields' => [], 'unsupported_fields' => [], 'fee_collection' => ['status' => 'UNVERIFIED'],
            'financial_authority' => ['customer_applicability' => 'UNVERIFIED', 'transaction_fee' => 'UNVERIFIED',
                'fee_payer' => 'UNVERIFIED', 'fee_allocation' => 'UNVERIFIED',
                'nium_wallet_debit' => 'UNVERIFIED', 'origin_wallet_debit' => 'UNVERIFIED',
                'admission' => 'DENIED', 'dispatch' => 'DENIED']];
    }

    private function assertUnused(): void
    {
        if ($this->consumed || file_exists($this->marker()) || is_link($this->marker())) {
            throw new RuntimeException('production_approval_already_used');
        }
    }

    private function marker(): string
    {
        return $this->directory.'/used-'.$this->approvalId().'.json';
    }

    private static function privatePath(string $path, int $uid, bool $directory): void
    {
        clearstatcache(true, $path);
        $stat = lstat($path);
        if (! is_array($stat) || $stat['uid'] !== $uid
            || ($stat['mode'] & 0170000) !== ($directory ? 0040000 : 0100000)
            || ($stat['mode'] & 0777) !== ($directory ? 0700 : 0600)) {
            throw new RuntimeException('production_private_permissions_required');
        }
    }

    private static function runtime(): array
    {
        if (! function_exists('posix_geteuid')) {
            throw new RuntimeException('production_runtime_unavailable');
        }
        $host = gethostname();
        if (! is_string($host) || $host === '') {
            throw new RuntimeException('production_runtime_unavailable');
        }

        return [posix_geteuid(), $host];
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function instant(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            throw new RuntimeException('production_approval_time_invalid');
        }
        $date = new DateTimeImmutable($value);
        if ($date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new RuntimeException('production_approval_time_invalid');
        }

        return $date;
    }
}
