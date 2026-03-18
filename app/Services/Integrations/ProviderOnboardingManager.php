<?php

namespace App\Services\Integrations;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use RuntimeException;

class ProviderOnboardingManager
{
    public function __construct(
        private readonly ProviderRegistry $registry,
    ) {
    }

    public function syncUser(IntegrationProvider $provider, User $user): UserProviderAccount
    {
        return $this->registry
            ->resolveOnboardingProvider($provider)
            ->syncUser($provider, $user);
    }

    public function linkUser(IntegrationProvider $provider, User $user, bool $force = false): UserProviderAccount
    {
        if ($provider->status !== 'active') {
            throw new RuntimeException("Provider [{$provider->code}] is not active.");
        }

        if ($user->profile === null) {
            throw new RuntimeException('User profile is required before linking provider account.');
        }

        $existingProviderAccount = $user->providerAccounts()
            ->where('provider_id', $provider->id)
            ->first();

        if (
            ! $force &&
            $existingProviderAccount !== null &&
            in_array($existingProviderAccount->status, ['submitted', 'under_review', 'active'], true)
        ) {
            return $existingProviderAccount->fresh('provider');
        }

        return $this->syncUser($provider, $user);
    }
}
