<?php

namespace Tests\Fixtures;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\Contracts\OnboardingProvider;
use App\Services\Integrations\DataObjects\ProviderOnboardingResult;
use App\Services\Integrations\ProviderAccountStatusManager;

class RedirectOnboardingProvider implements OnboardingProvider
{
    public function syncUser(IntegrationProvider $provider, User $user): UserProviderAccount
    {
        return $user->providerAccounts()->updateOrCreate(
            [
                'provider_id' => $provider->id,
                'external_account_id' => null,
            ],
            [
                'status' => 'pending',
                'metadata' => [
                    'integration_status' => 'redirect_required',
                ],
            ]
        );
    }

    public function beginOnboarding(
        IntegrationProvider $provider,
        User $user,
        ?UserProviderAccount $existingProviderAccount = null,
    ): ProviderOnboardingResult {
        $providerAccount = $existingProviderAccount ?? $this->syncUser($provider, $user);

        return new ProviderOnboardingResult(
            providerAccount: $providerAccount->fresh('provider'),
            status: 'pending',
            nextAction: 'redirect_to_provider',
            message: "{$provider->name} requires the user to finish onboarding on the provider site.",
            redirectUrl: "https://connect.example.test/{$provider->code}/{$user->id}",
            actionType: 'redirect',
            metadata: [
                'provider_code' => $provider->code,
            ],
        );
    }

    public function completeOnboarding(
        IntegrationProvider $provider,
        User $user,
        UserProviderAccount $providerAccount,
        array $payload,
    ): ProviderOnboardingResult {
        $statusManager = app(ProviderAccountStatusManager::class);
        $status = $statusManager->normalizeProviderAccountSubmissionStatus((string) ($payload['status'] ?? 'active'));

        $providerAccount->update([
            'external_customer_id' => $payload['external_customer_id'] ?? $providerAccount->external_customer_id,
            'external_account_id' => $payload['external_account_id'] ?? $providerAccount->external_account_id,
            'account_name' => $payload['account_name'] ?? $providerAccount->account_name ?? $user->full_name,
            'status' => $status,
            'metadata' => array_merge($providerAccount->metadata ?? [], [
                'integration_status' => 'callback_completed',
                'callback_payload' => $payload,
            ]),
        ]);

        $providerAccount = $providerAccount->fresh('user', 'provider');
        $statusManager->syncUserStatusFromProviderAccount($providerAccount);

        return new ProviderOnboardingResult(
            providerAccount: $providerAccount->fresh('provider'),
            status: (string) $providerAccount->status,
            nextAction: $providerAccount->status === 'active'
                ? 'provider_onboarding_completed'
                : 'wait_for_provider_review',
            message: $providerAccount->status === 'active'
                ? "{$provider->name} onboarding completed successfully."
                : "{$provider->name} onboarding callback received successfully.",
            actionType: 'callback',
            metadata: [
                'provider_code' => $provider->code,
                'provider_account_status' => $providerAccount->status,
            ],
        );
    }
}
