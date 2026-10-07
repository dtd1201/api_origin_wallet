<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\User;
use App\Models\UserProviderAccount;

final class NiumCurrentBiometricKyc
{
    /**
     * @return array{status: string, mode: string, url: string, reference_id: string|null}|null
     */
    public function forUser(User $user): ?array
    {
        $account = $user->providerAccounts()
            ->whereHas('provider', fn ($query) => $query->whereRaw('LOWER(code) = ?', ['nium']))
            ->latest('id')
            ->first();

        $attempts = $account?->metadata['nium_submit_kyc_attempts'] ?? null;

        if (! is_array($attempts) || $attempts === []) {
            return null;
        }

        $latest = collect(array_values($attempts))
            ->filter(fn ($attempt) => is_array($attempt))
            ->values()
            ->sortBy(fn (array $attempt, int $index) => sprintf(
                '%020d-%020d',
                strtotime((string) ($attempt['updated_at'] ?? $attempt['created_at'] ?? '')) ?: 0,
                $index,
            ))
            ->last();

        if (! is_array($latest)
            || strtolower((string) ($latest['state'] ?? '')) !== 'accepted'
            || strtolower((string) ($latest['kyc_mode'] ?? '')) !== 'biometric_kyc') {
            return null;
        }

        $externalId = trim((string) ($latest['external_id'] ?? ''));
        $referenceId = trim((string) ($latest['provider_reference_id'] ?? ''));
        if ($externalId === '' || $referenceId === '') {
            return null;
        }

        $entity = $this->matchingEntity($account, $externalId, $referenceId);
        if ($entity === []) {
            return null;
        }

        $status = strtolower((string) (
            $entity['kyc_status']
            ?? $latest['kyc_status']
            ?? $latest['entity_kyc_status']
            ?? ''
        ));

        if (! in_array($status, ['initiated', 'retry'], true)) {
            return null;
        }

        $url = $latest['biometric_url']
            ?? $this->customerGetUrl($account, $externalId, $referenceId, $status);

        if (! $this->isValidUrl($url)) {
            return null;
        }

        return [
            'status' => $status,
            'mode' => 'biometric_kyc',
            'url' => $url,
            'reference_id' => $referenceId,
        ];
    }

    private function matchingEntity(UserProviderAccount $account, string $externalId, string $referenceId): array
    {
        $matches = collect((array) ($account->metadata['nium_entity_kyc_states'] ?? []))
            ->filter(fn ($entity) => is_array($entity)
                && strtolower((string) ($entity['entity_type'] ?? '')) === 'applicant'
                && hash_equals($externalId, trim((string) ($entity['external_id'] ?? '')))
                && hash_equals($referenceId, trim((string) ($entity['provider_reference_id'] ?? ''))))
            ->values();

        return $matches->count() === 1 ? $matches->first() : [];
    }

    private function customerGetUrl(
        UserProviderAccount $account,
        string $externalId,
        string $referenceId,
        string $status,
    ): ?string {
        $logs = ApiRequestLog::query()
            ->with('niumExchangeEvidence')
            ->where('provider_id', $account->provider_id)
            ->where('user_id', $account->user_id)
            ->where('request_method', 'GET')
            ->where('is_success', true)
            ->latest('id')
            ->limit(20)
            ->get();

        foreach ($logs as $log) {
            $raw = $log->niumExchangeEvidence?->raw_response_body;
            if (! is_string($raw) || $raw === '') {
                continue;
            }

            $customer = json_decode($raw, true);
            $applicant = is_array($customer) ? ($customer['applicant'] ?? null) : null;

            if (is_array($applicant)
                && hash_equals((string) $account->external_customer_id, trim((string) ($customer['customerHashId'] ?? '')))
                && hash_equals($externalId, trim((string) ($applicant['externalId'] ?? '')))
                && hash_equals($referenceId, trim((string) ($applicant['referenceId'] ?? '')))
                && strtolower((string) ($applicant['kycMode'] ?? '')) === 'biometric_kyc'
                && strtolower((string) ($applicant['kycStatus'] ?? '')) === $status
                && $this->isValidUrl($applicant['biometricUrl'] ?? null)) {
                return $applicant['biometricUrl'];
            }
        }

        return null;
    }

    private function isValidUrl(mixed $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
