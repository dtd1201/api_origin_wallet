<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\ProviderOnboardingManager;
use Illuminate\Http\JsonResponse;

class ProviderSyncController extends Controller
{
    public function syncUser(
        IntegrationProvider $provider,
        User $user,
        ProviderOnboardingManager $manager,
    ): JsonResponse {
        $user->load('profile', 'providerAccounts.provider');

        $providerAccount = $manager->syncUser($provider, $user);

        return response()->json([
            'message' => "{$provider->name} sync submitted successfully.",
            'provider' => $provider->only(['id', 'code', 'name', 'status']),
            'user_id' => $user->id,
            'provider_account' => $providerAccount,
        ]);
    }
}
