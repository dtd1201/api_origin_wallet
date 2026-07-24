<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\ProviderOnboardingManager;
use App\Services\Nium\NiumProviderRequestException;
use App\Support\PrimaryProvider;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ProviderSyncController extends Controller
{
    public function syncUser(
        IntegrationProvider $provider,
        User $user,
        ProviderOnboardingManager $manager,
    ): JsonResponse {
        abort_unless(PrimaryProvider::isPrimary($provider), 404);

        try {
            $provider->assertSupportsCapability('onboarding');
            $providerAccount = $manager->syncUser($provider, $user->load('profile'));
        } catch (NiumProviderRequestException $exception) {
            return response()->json(array_filter([
                'message' => $exception->getMessage(),
                'code' => $exception->providerCode,
                'field' => $exception->providerField,
                'path' => $exception->providerPath,
            ], static fn ($value): bool => $value !== null), 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Nium customer state synchronized successfully.',
            'provider' => $provider->summaryPayload(),
            'user_id' => $user->id,
            'provider_account' => $providerAccount,
        ]);
    }
}
