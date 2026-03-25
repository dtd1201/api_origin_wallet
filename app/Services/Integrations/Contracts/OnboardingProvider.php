<?php

namespace App\Services\Integrations\Contracts;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\DataObjects\ProviderOnboardingResult;

interface OnboardingProvider
{
    public function syncUser(IntegrationProvider $provider, User $user): UserProviderAccount;

    public function beginOnboarding(
        IntegrationProvider $provider,
        User $user,
        ?UserProviderAccount $existingProviderAccount = null,
    ): ProviderOnboardingResult;

    public function completeOnboarding(
        IntegrationProvider $provider,
        User $user,
        UserProviderAccount $providerAccount,
        array $payload,
    ): ProviderOnboardingResult;
}
