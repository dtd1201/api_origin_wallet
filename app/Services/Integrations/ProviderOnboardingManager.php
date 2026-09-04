<?php

namespace App\Services\Integrations;

use App\Models\IntegrationProvider;
use App\Models\KycProviderSubmission;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\DataObjects\ProviderOnboardingResult;
use RuntimeException;

class ProviderOnboardingManager
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ProviderOnboardingReadinessService $readinessService,
    ) {}

    public function syncUser(IntegrationProvider $provider, User $user): UserProviderAccount
    {
        $this->readinessService->assertReady($provider, $user);

        $providerAccount = $this->registry
            ->resolveOnboardingProvider($provider)
            ->syncUser($provider, $user);

        $this->markProviderSubmissionSubmitted($provider, $user, $providerAccount);

        return $providerAccount;
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
            in_array($existingProviderAccount->status, ['submitted', 'under_review', 'active'], true) &&
            (
                strtolower((string) $provider->code) !== 'nium' ||
                $existingProviderAccount->status === 'active'
            )
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

        $this->readinessService->assertReady($provider, $user);

        $onboarding = $this->registry
            ->resolveOnboardingProvider($provider)
            ->beginOnboarding($provider, $user, $existingProviderAccount);

        $this->markProviderSubmissionSubmitted($provider, $user, $onboarding->providerAccount);

        return $onboarding;
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

        $this->readinessService->assertReady($provider, $user);

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

    private function markProviderSubmissionSubmitted(
        IntegrationProvider $provider,
        User $user,
        UserProviderAccount $providerAccount,
    ): void {
        KycProviderSubmission::query()
            ->where('user_id', $user->id)
            ->where('provider_id', $provider->id)
            ->whereIn('status', ['approved', 'submitted'])
            ->update([
                'status' => 'submitted',
                'provider_account_id' => $providerAccount->id,
                'submitted_at' => now(),
                'failure_reason' => null,
            ]);
    }
}
