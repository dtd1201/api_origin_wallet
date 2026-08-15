<?php

namespace App\Services\Nium;

use App\Models\UserProviderAccount;
use RuntimeException;

final class NiumHkFundingReadinessRunner
{
    private const ACCOUNT_ID = 7;

    private const PROTECTED_ACCOUNT_ID = 4;

    private const USER_ID = 9;

    public function audit(): array
    {
        $protected = $this->fingerprint(UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID));
        $account = UserProviderAccount::query()->with('user', 'provider')->whereKey(self::ACCOUNT_ID)
            ->where('user_id', self::USER_ID)->firstOrFail();

        if ($account->provider?->code !== 'nium' || ! filled($account->external_customer_id)
            || ! filled($account->external_account_id)) {
            throw new RuntimeException('HOLD_ACCOUNT_7_NIUM_BINDING_INVALID');
        }

        $this->path((string) config('services.nium.client_details_endpoint', ''), ['clientHashId']);
        $this->path((string) config('services.nium.virtual_account_details_endpoint', ''), [
            'clientHashId', 'customerHashId', 'walletHashId',
        ]);

        if ($this->fingerprint(UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID)) !== $protected) {
            throw new RuntimeException('Protected Account 4 changed.');
        }

        $kycClear = $account->user->kyc_status === 'verified'
            && strtolower(trim((string) $account->provider_status)) === 'clear'
            && trim((string) $account->provider_sub_status) === '';

        return [
            'terminal' => $kycClear ? 'HOLD_PROVIDER_FACTS_NOT_REVIEWED' : 'HOLD_KYC_BLOCKED',
            'account_id' => self::ACCOUNT_ID,
            'protected_account_id' => self::PROTECTED_ACCOUNT_ID,
            'kyc_bypass' => false,
            'provider_get_count' => 0,
            'db_write_count' => 0,
        ];
    }

    private function path(string $endpoint, array $requiredPlaceholders): void
    {
        if ($endpoint === '' || ! str_starts_with($endpoint, '/') || str_starts_with($endpoint, '//')
            || preg_match('/[\x00-\x20]/', $endpoint) === 1 || preg_match('#^https?://#i', $endpoint) === 1) {
            throw new RuntimeException('HOLD_NIUM_FUNDING_ENDPOINT_CONFIG_INVALID');
        }

        foreach ($requiredPlaceholders as $placeholder) {
            if (! str_contains($endpoint, '{'.$placeholder.'}')) {
                throw new RuntimeException('HOLD_NIUM_FUNDING_ENDPOINT_CONFIG_INVALID');
            }
        }
    }

    private function fingerprint(UserProviderAccount $account): string
    {
        $attributes = $account->getRawOriginal();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }
}
