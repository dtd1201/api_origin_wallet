<?php

namespace App\Services\Integrations;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\DataObjects\ProviderOnboardingResult;
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

    public function linkUser(IntegrationProvider $provider, User $user, bool $force = false): ProviderOnboardingResult
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
            return new ProviderOnboardingResult(
                providerAccount: $existingProviderAccount->fresh('provider'),
                status: (string) $existingProviderAccount->status,
                nextAction: match (strtolower((string) $existingProviderAccount->status)) {
                    'active' => 'provider_onboarding_completed',
                    default => 'wait_for_provider_review',
                },
                message: match (strtolower((string) $existingProviderAccount->status)) {
                    'active' => "{$provider->name} is already connected.",
                    default => "{$provider->name} onboarding is already in progress.",
                },
                metadata: [
                    'provider_code' => $provider->code,
                    'provider_account_status' => $existingProviderAccount->status,
                    'reused_existing_account' => true,
                ],
            );
        }

        return $this->registry
            ->resolveOnboardingProvider($provider)
            ->beginOnboarding($provider, $user, $existingProviderAccount);
    }

    public function completeUserOnboarding(
        IntegrationProvider $provider,
        User $user,
        array $payload,
    ): ProviderOnboardingResult {
        if ($provider->status !== 'active') {
            throw new RuntimeException("Provider [{$provider->code}] is not active.");
        }

        if ($user->profile === null) {
            throw new RuntimeException('User profile is required before completing provider onboarding.');
        }

        $providerAccount = $user->providerAccounts()
            ->where('provider_id', $provider->id)
            ->latest('id')
            ->first();

        if ($providerAccount === null) {
            throw new RuntimeException('No provider onboarding session found for this user.');
        }

        return $this->registry
            ->resolveOnboardingProvider($provider)
            ->completeOnboarding($provider, $user, $providerAccount, $payload);
    }
}
