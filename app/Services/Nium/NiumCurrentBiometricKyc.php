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
            || strtolower((string) ($latest['kyc_mode'] ?? '')) !== 'biometric_kyc'
            || strtolower((string) ($latest['entity_type'] ?? '')) !== 'applicant') {
            return null;
        }

        $externalId = trim((string) ($latest['external_id'] ?? ''));
        $referenceId = trim((string) ($latest['provider_reference_id'] ?? ''));
        if ($externalId === '' || $referenceId === '') {
            return null;
        }

        $status = strtolower((string) (
            $latest['entity_kyc_status']
            ?? $latest['kyc_status']
            ?? ''
        ));

        $url = $latest['biometric_url'] ?? null;
        if ($this->isValidUrl($url) && in_array($status, ['initiated', 'retry'], true)) {
            return [
                'status' => $status,
                'mode' => 'biometric_kyc',
                'url' => $url,
                'reference_id' => $referenceId,
            ];
        }

        $customerGet = $this->customerGet($account, $externalId, $referenceId);
        if ($customerGet === null) {
            return null;
        }

        return [
            'status' => $customerGet['status'],
            'mode' => 'biometric_kyc',
            'url' => $customerGet['url'],
            'reference_id' => $referenceId,
        ];
    }

    /**
     * @return array{status: string, url: string}|null
     */
    private function customerGet(
        UserProviderAccount $account,
        string $externalId,
        string $referenceId,
    ): ?array {
        $logs = ApiRequestLog::query()
            ->with('niumExchangeEvidence')
            ->where('provider_id', $account->provider_id)
            ->where('user_id', $account->user_id)
            ->where('request_method', 'GET')
            ->where('is_success', true)
            ->whereHas('niumExchangeEvidence', fn ($query) => $query->where('response_received', true))
            ->latest('id')
            ->get();

        foreach ($logs as $log) {
            $raw = $log->niumExchangeEvidence?->raw_response_body;
            if (! is_string($raw) || $raw === '') {
                continue;
            }

            $customer = json_decode($raw, true);
            $applicant = is_array($customer) ? ($customer['applicant'] ?? null) : null;
            if (! is_array($applicant)) {
                continue;
            }

            $entityType = strtolower((string) ($applicant['entityType'] ?? 'applicant'));
            $status = strtolower((string) ($applicant['kycStatus'] ?? ''));

            if (hash_equals((string) $account->external_customer_id, trim((string) ($customer['customerHashId'] ?? '')))
                && $entityType === 'applicant'
                && hash_equals($externalId, trim((string) ($applicant['externalId'] ?? '')))
                && hash_equals($referenceId, trim((string) ($applicant['referenceId'] ?? '')))
                && strtolower((string) ($applicant['kycMode'] ?? '')) === 'biometric_kyc'
                && in_array($status, ['initiated', 'retry'], true)
                && $this->isValidUrl($applicant['biometricUrl'] ?? null)) {
                return [
                    'status' => $status,
                    'url' => $applicant['biometricUrl'],
                ];
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
