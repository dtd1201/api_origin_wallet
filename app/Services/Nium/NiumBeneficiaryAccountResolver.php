<?php

namespace App\Services\Nium;

use App\Models\User;
use App\Models\UserProviderAccount;
use RuntimeException;

final class NiumBeneficiaryAccountResolver
{
    public function __construct(private readonly NiumProviderAccountStateService $accountStateService) {}

    public function resolve(User $user, ?int $providerId = null, bool $requireWallet = true): UserProviderAccount
    {
        $accountSeven = UserProviderAccount::query()
            ->whereKey(7)
            ->where('user_id', $user->id)
            ->whereHas('provider', fn ($query) => $query->whereRaw('LOWER(code) = ?', ['nium']))
            ->first();

        if ($accountSeven === null) {
            return $this->accountStateService->assertEligible($user, $requireWallet);
        }

        if ($providerId !== null && $accountSeven->provider_id !== $providerId) {
            throw new RuntimeException('HOLD_EXACT_ACCOUNT_7_PROVIDER_MISMATCH');
        }

        $eligible = $accountSeven->status === 'active'
            && strtolower((string) $accountSeven->provider_status) === 'clear'
            && ! filled($accountSeven->provider_sub_status)
            && filled($accountSeven->external_customer_id)
            && $accountSeven->customer_id_verified_at !== null
            && (! $requireWallet || (filled($accountSeven->external_account_id) && $accountSeven->wallet_id_verified_at !== null))
            && $accountSeven->security_conflict_at === null;

        if (! $eligible) {
            throw new RuntimeException('Exact Account 7 is not eligible for Nium beneficiary execution.');
        }

        return $accountSeven;
    }
}
