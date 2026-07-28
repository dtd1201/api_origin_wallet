<?php

namespace App\Services\Nium;

use App\Support\SensitiveDataSanitizer;
use DateTimeInterface;

final class NiumSafeValueProjector
{
    public const UNKNOWN = 'unknown';

    private const PROVIDER_STATUSES = [
        'pending',
        'clear',
        'closed',
        'terminated',
        'suspended',
        'blocked',
        'rejected',
        'failed',
        'error',
    ];

    private const PROVIDER_SUB_STATUSES = [
        'under_review',
        'rfi_requested',
        'awaiting_kyc',
    ];

    private const COMPLIANCE_STATUSES = [
        'pending',
        'completed',
        'failed',
        'action_required',
    ];

    private const ODD_STATUSES = [
        'pending',
        'odd_due',
        'completed',
        'clear',
    ];

    private const RFI_STATUSES = [
        'requested',
        'cleared',
    ];

    private const RECONCILIATION_STATUSES = [
        'pending',
        'reconciled',
        'failed',
        'quarantined',
    ];

    private const INTERNAL_ACCOUNT_STATUSES = [
        'pending',
        'submitted',
        'under_review',
        'active',
        'rejected',
        'failed',
        'blocked',
    ];

    private const CUSTOMER_TYPES = [
        'individual',
        'corporate',
    ];

    private const REGIONS = [
        'SG',
        'UK',
        'EU',
        'NL',
        'US',
        'AU',
        'NZ',
        'CA',
        'HK',
        'JP',
        'MX',
        'BR',
        'ID',
    ];

    private const KYC_STATUSES = [
        'pending',
        'submitted',
        'under_review',
        'approved',
        'rejected',
        'failed',
        'completed',
    ];

    private const KYC_MODES = [
        'manual_kyc',
        'automated_kyc',
    ];

    private const ENTITY_TYPES = [
        'customer',
        'applicant',
        'stakeholder',
        'director',
        'shareholder',
        'beneficial_owner',
    ];

    private const ERROR_CODES = [
        'invalid_input',
        'validation_error',
        'customer_exists',
        'duplicate_external_id',
        'authentication_failed',
        'unauthorized',
        'forbidden',
        'not_found',
        'rate_limit_exceeded',
        'timeout',
        'internal_server_error',
    ];

    private const IDENTIFIER_CONFLICT_FIELDS = [
        'external_customer_id',
        'external_account_id',
        'external_reference',
    ];

    private const SOURCES = [
        'origin_wallet_nium_v5_submission',
        'nium_v5_customer_create_response',
        'nium_v5_customer_get_response',
        'nium_v5_customer_list_response',
        'nium_v5_duplicate_external_id_recovery',
        'nium_v5_customer_sync',
        'nium_v6_fixture_v2_customer_retry',
        'nium_webhook_notification:customer_status_webhook',
        'nium_webhook_notification:customer_entity_kyc_status',
        'nium_webhook_notification:customer_compliance_status',
        'nium_webhook_notification:customer_odd_status_webhook',
        'nium_webhook_notification:card_customer_registration_webhook',
    ];

    public function __construct(
        private readonly SensitiveDataSanitizer $sensitiveDataSanitizer,
    ) {}

    public function providerStatus(mixed $value): ?string
    {
        return $this->safeEnum($value, self::PROVIDER_STATUSES);
    }

    public function providerSubStatus(mixed $value): ?string
    {
        return $this->safeEnum($value, self::PROVIDER_SUB_STATUSES);
    }

    public function complianceStatus(mixed $value): ?string
    {
        return $this->safeEnum($value, self::COMPLIANCE_STATUSES);
    }

    public function oddStatus(mixed $value): ?string
    {
        return $this->safeEnum($value, self::ODD_STATUSES);
    }

    public function rfiStatus(mixed $value): ?string
    {
        return $this->safeEnum($value, self::RFI_STATUSES);
    }

    public function reconciliationStatus(mixed $value): ?string
    {
        return $this->safeEnum($value, self::RECONCILIATION_STATUSES);
    }

    public function internalAccountStatus(mixed $value): ?string
    {
        return $this->safeEnum($value, self::INTERNAL_ACCOUNT_STATUSES);
    }

    public function customerType(mixed $value): ?string
    {
        return $this->safeEnum($value, self::CUSTOMER_TYPES);
    }

    public function region(mixed $value): ?string
    {
        return $this->safeEnum($value, self::REGIONS, true);
    }

    public function kycStatus(mixed $value): ?string
    {
        return $this->safeEnum($value, self::KYC_STATUSES);
    }

    public function kycMode(mixed $value): ?string
    {
        return $this->safeEnum($value, self::KYC_MODES);
    }

    public function entityType(mixed $value): ?string
    {
        return $this->safeEnum($value, self::ENTITY_TYPES);
    }

    public function source(mixed $value): string
    {
        $normalized = $this->sanitizedString($value, 96);

        return $normalized !== null && in_array($normalized, self::SOURCES, true)
            ? $normalized
            : self::UNKNOWN;
    }

    public function integrationStatus(mixed $providerStatus, mixed $providerSubStatus): string
    {
        $status = $this->providerStatus($providerStatus) ?? self::UNKNOWN;
        $subStatus = $this->providerSubStatus($providerSubStatus);
        $value = 'nium_'.$status.($subStatus !== null ? '_'.$subStatus : '');

        return $this->sanitizedString($value, 64) === $value ? $value : 'nium_'.self::UNKNOWN;
    }

    public function requestId(mixed $value): ?string
    {
        $value = $this->sanitizedString($value, 36);

        return $value !== null
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1
                ? strtolower($value)
                : null;
    }

    public function fingerprint(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? substr(hash('sha256', $value), 0, 16) : null;
    }

    public function safeOpaqueFingerprint(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $sanitized = $this->sensitiveDataSanitizer->sanitize($value);

        return is_string($sanitized)
            && $sanitized === $value
            && ! str_contains($sanitized, '[REDACTED]')
                ? substr(hash('sha256', $value), 0, 16)
                : null;
    }

    public function identifierConflictField(mixed $value): ?string
    {
        $value = $this->sanitizedString($value, 32);

        return $value !== null && in_array($value, self::IDENTIFIER_CONFLICT_FIELDS, true)
            ? $value
            : null;
    }

    public function safeHttpStatus(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($value) && $value >= 100 && $value <= 599 ? $value : null;
    }

    public function apiRequestHeaders(mixed $requestId): array
    {
        return $this->finalizeFlatProjection(array_filter([
            'x-request-id' => $this->requestId($requestId),
        ], static fn ($value): bool => $value !== null), [
            'x-request-id' => 'string',
        ]);
    }

    public function apiRequestBody(array $payload): array
    {
        return $this->finalizeFlatProjection(array_filter([
            'external_id_fingerprint' => $this->fingerprint($payload['externalId'] ?? null),
            'customer_type' => $this->customerType($payload['type'] ?? null),
            'region' => $this->region($payload['region'] ?? null),
        ], static fn ($value): bool => $value !== null), [
            'external_id_fingerprint' => 'fingerprint',
            'customer_type' => 'string',
            'region' => 'string',
        ]);
    }

    public function apiResponseHeaders(mixed $requestId): array
    {
        return $this->apiRequestHeaders($requestId);
    }

    public function apiResponseBody(array $response, mixed $httpStatus = null): array
    {
        $error = $response['errors'][0] ?? [];
        $error = is_array($error) ? $error : [];
        $errorValue = $error['code']
            ?? $error['errorCode']
            ?? $response['errorCode']
            ?? $response['code']
            ?? null;
        $errorProjection = $this->errorProjection($errorValue);
        $customerId = $response['customerHashId'] ?? null;
        $walletId = $response['walletHashId'] ?? ($response['wallets'][0]['walletHashId'] ?? null);

        return $this->finalizeFlatProjection(array_filter([
            'http_status' => $this->safeHttpStatus($httpStatus),
            'status' => $this->providerStatus($response['status'] ?? null),
            'sub_status' => $this->providerSubStatus($response['subStatus'] ?? null),
            ...$errorProjection,
            'error_field_fingerprint' => $this->fingerprint($error['field'] ?? null),
            'error_path_fingerprint' => $this->fingerprint($error['path'] ?? null),
            'error_parameter_fingerprint' => $this->fingerprint($error['parameter'] ?? null),
            'customer_id_present' => $this->isPresent($customerId),
            'wallet_id_present' => $this->isPresent($walletId),
            'customer_id_fingerprint' => $this->fingerprint($customerId),
            'wallet_id_fingerprint' => $this->fingerprint($walletId),
        ], static fn ($value): bool => $value !== null), [
            'http_status' => 'int',
            'status' => 'string',
            'sub_status' => 'string',
            'error_code' => 'string',
            'error_category' => 'string',
            'error_fingerprint' => 'fingerprint',
            'error_field_fingerprint' => 'fingerprint',
            'error_path_fingerprint' => 'fingerprint',
            'error_parameter_fingerprint' => 'fingerprint',
            'customer_id_present' => 'bool',
            'wallet_id_present' => 'bool',
            'customer_id_fingerprint' => 'fingerprint',
            'wallet_id_fingerprint' => 'fingerprint',
        ]);
    }

    public function accountMetadata(
        mixed $providerStatus,
        mixed $providerSubStatus,
        mixed $source,
        mixed $lastStateAt,
        mixed $isResubmissionAllowed,
        array $entityStates = [],
    ): array {
        $metadata = [
            'integration_status' => $this->integrationStatus($providerStatus, $providerSubStatus),
            'nium_last_state_source' => $this->source($source),
            'nium_last_state_at' => $this->timestamp($lastStateAt),
            'is_resubmission_allowed' => $this->strictBoolean($isResubmissionAllowed),
            'nium_entity_kyc_states' => $this->entityStates($entityStates),
        ];

        return array_filter($metadata, static fn ($value): bool => $value !== null && $value !== []);
    }

    public function submissionMetadata(
        mixed $providerStatus,
        mixed $providerSubStatus,
        mixed $complianceStatus,
        mixed $rfiStatus,
        mixed $oddStatus,
    ): array {
        return array_filter([
            'provider_status' => $this->providerStatus($providerStatus),
            'provider_sub_status' => $this->providerSubStatus($providerSubStatus),
            'compliance_status' => $this->complianceStatus($complianceStatus),
            'rfi_status' => $this->rfiStatus($rfiStatus),
            'odd_status' => $this->oddStatus($oddStatus),
        ], static fn ($value): bool => $value !== null);
    }

    public function auditState(array $state): array
    {
        return $this->finalizeFlatProjection(array_filter([
            'external_customer_id_fingerprint' => $this->fingerprint($state['external_customer_id'] ?? null),
            'external_account_id_fingerprint' => $this->fingerprint($state['external_account_id'] ?? null),
            'external_reference_fingerprint' => $this->fingerprint($state['external_reference'] ?? null),
            'status' => $this->internalAccountStatus($state['status'] ?? null),
            'provider_status' => $this->providerStatus($state['provider_status'] ?? null),
            'provider_sub_status' => $this->providerSubStatus($state['provider_sub_status'] ?? null),
            'compliance_status' => $this->complianceStatus($state['compliance_status'] ?? null),
            'rfi_status' => $this->rfiStatus($state['rfi_status'] ?? null),
            'odd_status' => $this->oddStatus($state['odd_status'] ?? null),
            'customer_id_verified_at' => $this->timestamp($state['customer_id_verified_at'] ?? null),
            'wallet_id_verified_at' => $this->timestamp($state['wallet_id_verified_at'] ?? null),
            'provider_ids_verified_at' => $this->timestamp($state['provider_ids_verified_at'] ?? null),
            'security_conflict_at' => $this->timestamp($state['security_conflict_at'] ?? null),
            'security_conflict' => ($state['security_conflict_at'] ?? null) !== null,
            'reconciliation_status' => $this->reconciliationStatus($state['reconciliation_status'] ?? null),
            'integration_status' => $this->knownIntegrationStatus($state['integration_status'] ?? null),
        ], static fn ($value): bool => $value !== null), [
            'external_customer_id_fingerprint' => 'fingerprint',
            'external_account_id_fingerprint' => 'fingerprint',
            'external_reference_fingerprint' => 'fingerprint',
            'status' => 'string',
            'provider_status' => 'string',
            'provider_sub_status' => 'string',
            'compliance_status' => 'string',
            'rfi_status' => 'string',
            'odd_status' => 'string',
            'customer_id_verified_at' => 'timestamp',
            'wallet_id_verified_at' => 'timestamp',
            'provider_ids_verified_at' => 'timestamp',
            'security_conflict_at' => 'timestamp',
            'security_conflict' => 'bool',
            'reconciliation_status' => 'string',
            'integration_status' => 'string',
        ]);
    }

    public function auditSource(mixed $source): string
    {
        return $this->source($source);
    }

    private function safeEnum(mixed $value, array $allowed, bool $uppercase = false): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        $normalized = $uppercase ? strtoupper($normalized) : strtolower($normalized);
        $sanitized = $this->sanitizedString($normalized, 64);

        return $sanitized !== null && in_array($sanitized, $allowed, true)
            ? $sanitized
            : self::UNKNOWN;
    }

    private function knownIntegrationStatus(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        if (in_array($value, [
            'awaiting_nium_v5_submission',
            'nium_security_conflict',
        ], true)) {
            return $this->sanitizedString($value, 64) === $value ? $value : self::UNKNOWN;
        }

        if (preg_match('/^nium_([a-z_]+?)(?:_(under_review|rfi_requested|awaiting_kyc))?$/', $value, $matches) !== 1) {
            return self::UNKNOWN;
        }

        $status = $this->providerStatus($matches[1]);
        $subStatus = $this->providerSubStatus($matches[2] ?? null);

        return $this->integrationStatus($status, $subStatus);
    }

    private function errorProjection(mixed $value): array
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return [];
        }

        $raw = trim((string) $value);
        $normalized = strtolower($raw);
        $sanitized = $this->sanitizedString($normalized, 80);

        if ($sanitized !== null && in_array($sanitized, self::ERROR_CODES, true)) {
            return ['error_code' => $sanitized];
        }

        return [
            'error_category' => 'unclassified',
            'error_fingerprint' => $this->fingerprint($raw),
        ];
    }

    private function entityStates(array $states): array
    {
        $safeStates = [];

        foreach (array_slice($states, -20, null, true) as $key => $state) {
            if (! is_array($state)) {
                continue;
            }

            $fingerprint = str_starts_with((string) $key, 'ref_')
                ? substr((string) $key, 4)
                : $this->fingerprint($key);

            if ($fingerprint === null || preg_match('/^[a-f0-9]{16}$/', $fingerprint) !== 1) {
                continue;
            }

            $safeState = array_filter([
                'kyc_status' => $this->kycStatus($state['kyc_status'] ?? $state['kycStatus'] ?? null),
                'kyc_mode' => $this->kycMode($state['kyc_mode'] ?? $state['kycMode'] ?? null),
                'entity_type' => $this->entityType($state['entity_type'] ?? $state['entityType'] ?? null),
                'updated_at' => $this->timestamp($state['updated_at'] ?? $state['updatedAt'] ?? null),
            ], static fn ($value): bool => $value !== null);

            if ($safeState !== []) {
                $safeStates['ref_'.$fingerprint] = $safeState;
            }
        }

        $sanitized = $this->sensitiveDataSanitizer->sanitize($safeStates);

        return is_array($sanitized) && $sanitized === $safeStates ? $safeStates : [];
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d\TH:i:s.uP');
        }

        $value = $this->sanitizedString($value, 40);

        return $value !== null
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value) === 1
                ? $value
                : null;
    }

    private function strictBoolean(mixed $value): ?bool
    {
        return match ($value) {
            true, 'true' => true,
            false, 'false' => false,
            default => null,
        };
    }

    private function isPresent(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }

    private function sanitizedString(mixed $value, int $maximumLength): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > $maximumLength) {
            return null;
        }

        $sanitized = $this->sensitiveDataSanitizer->sanitize($value);

        return is_string($sanitized)
            && $sanitized === $value
            && ! str_contains($sanitized, '[REDACTED]')
                ? $sanitized
                : null;
    }

    private function finalizeFlatProjection(array $projection, array $shape): array
    {
        $safe = [];

        foreach ($shape as $key => $type) {
            if (! array_key_exists($key, $projection)) {
                continue;
            }

            $value = $projection[$key];
            $valid = match ($type) {
                'bool' => is_bool($value),
                'int' => is_int($value) && $value >= 100 && $value <= 599,
                'fingerprint' => is_string($value) && preg_match('/^[a-f0-9]{16}$/', $value) === 1,
                'timestamp' => is_string($value) && strlen($value) <= 40,
                default => is_string($value) && strlen($value) <= 96,
            };

            if ($valid) {
                $safe[$key] = $value;
            }
        }

        $sanitized = $this->sensitiveDataSanitizer->sanitize($safe);

        return is_array($sanitized) && $sanitized === $safe ? $safe : [];
    }
}
