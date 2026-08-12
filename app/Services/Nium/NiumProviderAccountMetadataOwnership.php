<?php

namespace App\Services\Nium;

final class NiumProviderAccountMetadataOwnership
{
    private const ATTEMPT_FIELDS = [
        'state',
        'kyc_mode',
        'provider_http_status',
        'redirect_url_fingerprint',
        'submit_kyc_log_id',
        'submit_kyc_log_at',
        'webhook_id',
        'webhook_processed_at',
        'updated_at',
    ];

    private const SUBMISSION_FIELDS = [
        'submission_marker',
        'submission_state',
        'payload_fingerprint',
        'reconciliation_status',
        'reconciliation_error',
        'is_resubmission_allowed',
        'provider_response_status',
        'provider_error_field_fingerprint',
    ];

    public function merge(array $existing, array $providerProjection): array
    {
        return array_replace($this->localProjection($existing), $providerProjection);
    }

    private function localProjection(array $metadata): array
    {
        return array_filter([
            'nium_submit_kyc_attempts' => $this->attempts($metadata['nium_submit_kyc_attempts'] ?? null),
            'nium_sandbox_simulation_submit_kyc_attempt' => $this->simulationAttempt(
                $metadata['nium_sandbox_simulation_submit_kyc_attempt'] ?? null,
            ),
            'nium_stakeholder_submit_kyc_retry_generation_2' => $this->stakeholderRetry(
                $metadata['nium_stakeholder_submit_kyc_retry_generation_2'] ?? null,
                106,
                'entityType',
                'b4753588f3f6ef2b',
            ),
            'nium_stakeholder_submit_kyc_retry_generation_3' => $this->stakeholderRetry(
                $metadata['nium_stakeholder_submit_kyc_retry_generation_3'] ?? null,
                113,
                'proofOfAddressDocument',
                'a5b7a48f01932655',
            ),
            'nium_stakeholder_submit_kyc_retry_generation_4' => $this->stakeholderGenerationFour(
                $metadata['nium_stakeholder_submit_kyc_retry_generation_4'] ?? null,
            ),
            'customer_v5_submission_marker' => $this->safeString($metadata['customer_v5_submission_marker'] ?? null, 128),
            'customer_v5_submission_state' => $this->safeString($metadata['customer_v5_submission_state'] ?? null, 64),
            'customer_v5_payload_fingerprint' => $this->fingerprint($metadata['customer_v5_payload_fingerprint'] ?? null, 64),
            'customer_v5_submission_history' => $this->submissionHistory($metadata['customer_v5_submission_history'] ?? null),
            'customer_v5_previous_submission' => $this->submission($metadata['customer_v5_previous_submission'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function attempts(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            return null;
        }

        $safe = [];
        foreach (array_slice($value, -20, null, true) as $key => $attempt) {
            if (! is_string($key)
                || preg_match('/^ref_[a-f0-9]{16}$/', $key) !== 1
                || ! is_array($attempt)
                || array_is_list($attempt)) {
                continue;
            }

            $projected = $this->attempt($attempt);
            if ($projected !== []) {
                $safe[$key] = $projected;
            }
        }

        return $safe !== [] ? $safe : null;
    }

    private function attempt(array $attempt): array
    {
        $attempt = array_intersect_key($attempt, array_flip(self::ATTEMPT_FIELDS));

        return array_filter([
            'state' => $this->safeString($attempt['state'] ?? null, 64),
            'kyc_mode' => ($attempt['kyc_mode'] ?? null) === 'biometric_kyc' ? 'biometric_kyc' : null,
            'provider_http_status' => $this->httpStatus($attempt['provider_http_status'] ?? null),
            'redirect_url_fingerprint' => $this->fingerprint($attempt['redirect_url_fingerprint'] ?? null, 16),
            'submit_kyc_log_id' => $this->positiveInt($attempt['submit_kyc_log_id'] ?? null),
            'submit_kyc_log_at' => $this->timestamp($attempt['submit_kyc_log_at'] ?? null),
            'webhook_id' => $this->positiveInt($attempt['webhook_id'] ?? null),
            'webhook_processed_at' => $this->timestamp($attempt['webhook_processed_at'] ?? null),
            'updated_at' => $this->timestamp($attempt['updated_at'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function simulationAttempt(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            return null;
        }

        $safe = array_filter([
            'state' => $this->safeString($value['state'] ?? null, 64),
            'updated_at' => $this->timestamp($value['updated_at'] ?? null),
        ], static fn (mixed $item): bool => $item !== null);

        return $safe !== [] ? $safe : null;
    }

    private function stakeholderRetry(mixed $value, int $logId, string $field, string $fingerprint): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            return null;
        }

        $safe = array_filter([
            'state' => $this->safeString($value['state'] ?? null, 64),
            'previous_log_id' => ($value['previous_log_id'] ?? null) === $logId ? $logId : null,
            'previous_http_status' => ($value['previous_http_status'] ?? null) === 400 ? 400 : null,
            'previous_error_code' => ($value['previous_error_code'] ?? null) === 'invalid_input' ? 'invalid_input' : null,
            'previous_error_field' => ($value['previous_error_field'] ?? null) === $field ? $field : null,
            'previous_error_field_fingerprint' => ($value['previous_error_field_fingerprint'] ?? null) === $fingerprint
                ? $fingerprint
                : null,
            'confirmed_entity_type' => in_array($value['confirmed_entity_type'] ?? null, [
                'individual_stakeholder',
                'applicant',
                'individual_customer',
            ], true) ? $value['confirmed_entity_type'] : null,
            'confirmed_kyc_mode' => in_array($value['confirmed_kyc_mode'] ?? null, [
                'e_kyc',
                'biometric_kyc',
                'manual_kyc',
            ], true) ? $value['confirmed_kyc_mode'] : null,
            'updated_at' => $this->timestamp($value['updated_at'] ?? null),
        ], static fn (mixed $item): bool => $item !== null);

        return $safe !== [] ? $safe : null;
    }

    private function stakeholderGenerationFour(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            return null;
        }

        $safe = array_filter([
            'state' => $this->safeString($value['state'] ?? null, 64),
            'previous_log_id' => ($value['previous_log_id'] ?? null) === 114 ? 114 : null,
            'previous_http_status' => ($value['previous_http_status'] ?? null) === 400 ? 400 : null,
            'previous_error_field_count' => ($value['previous_error_field_count'] ?? null) === 3 ? 3 : null,
            'updated_at' => $this->timestamp($value['updated_at'] ?? null),
        ], static fn (mixed $item): bool => $item !== null);

        return $safe !== [] ? $safe : null;
    }

    private function submissionHistory(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $safe = [];
        foreach (array_slice($value, -10) as $submission) {
            $projected = $this->submission($submission);
            if ($projected !== null) {
                $safe[] = $projected;
            }
        }

        return $safe !== [] ? $safe : null;
    }

    private function submission(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            return null;
        }

        $value = array_intersect_key($value, array_flip(self::SUBMISSION_FIELDS));
        $safe = array_filter([
            'submission_marker' => $this->safeString($value['submission_marker'] ?? null, 128),
            'submission_state' => $this->safeString($value['submission_state'] ?? null, 64),
            'payload_fingerprint' => $this->fingerprint($value['payload_fingerprint'] ?? null, 64),
            'reconciliation_status' => $this->safeString($value['reconciliation_status'] ?? null, 32),
            'reconciliation_error' => $this->safeString($value['reconciliation_error'] ?? null, 64),
            'is_resubmission_allowed' => is_bool($value['is_resubmission_allowed'] ?? null)
                ? $value['is_resubmission_allowed']
                : null,
            'provider_response_status' => $this->httpStatus($value['provider_response_status'] ?? null),
            'provider_error_field_fingerprint' => $this->fingerprint(
                $value['provider_error_field_fingerprint'] ?? null,
                16,
            ),
        ], static fn (mixed $item): bool => $item !== null);

        return $safe !== [] ? $safe : null;
    }

    private function safeString(mixed $value, int $maximumLength): ?string
    {
        return is_string($value)
            && strlen($value) <= $maximumLength
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value) === 1
                ? $value
                : null;
    }

    private function fingerprint(mixed $value, int $length): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{'.$length.'}$/', $value) === 1 ? $value : null;
    }

    private function timestamp(mixed $value): ?string
    {
        return is_string($value)
            && strlen($value) <= 40
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value) === 1
                ? $value
                : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    private function httpStatus(mixed $value): ?int
    {
        return is_int($value) && $value >= 100 && $value <= 599 ? $value : null;
    }
}
