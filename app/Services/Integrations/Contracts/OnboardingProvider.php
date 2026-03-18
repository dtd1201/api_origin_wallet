<?php

namespace App\Services\Integrations\Contracts;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;

interface OnboardingProvider
{
    public function syncUser(IntegrationProvider $provider, User $user): UserProviderAccount;
}
