<?php

namespace App\Services\Nium;

use App\Models\User;

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
            || strtolower((string) ($latest['kyc_status'] ?? '')) !== 'initiated') {
            return null;
        }

        $url = $latest['biometric_url'] ?? null;

        if (! is_string($url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return null;
        }

        $referenceId = $latest['provider_reference_id'] ?? null;

        return [
            'status' => 'initiated',
            'mode' => 'biometric_kyc',
            'url' => $url,
            'reference_id' => is_string($referenceId) && $referenceId !== '' ? $referenceId : null,
        ];
    }
}
