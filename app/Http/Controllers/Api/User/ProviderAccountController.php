<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\ProviderOnboardingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ProviderAccountController extends Controller
{
    public function index(User $user): JsonResponse
    {
        return response()->json(
            $user->providerAccounts()
                ->with('provider')
                ->latest('id')
                ->get()
        );
    }

    public function show(User $user, IntegrationProvider $provider): JsonResponse
    {
        $providerAccount = $user->providerAccounts()
            ->with('provider')
            ->where('provider_id', $provider->id)
            ->first();

        if ($providerAccount === null) {
            return response()->json([
                'provider' => $provider->only(['id', 'code', 'name', 'status']),
                'provider_account' => null,
                'link_available' => $provider->status === 'active',
            ]);
        }

        return response()->json($providerAccount);
    }

    public function link(
        Request $request,
        User $user,
        IntegrationProvider $provider,
        ProviderOnboardingManager $manager,
    ): JsonResponse {
        try {
            $providerAccount = $manager->linkUser(
                provider: $provider,
                user: $user->load('profile', 'providerAccounts.provider'),
                force: (bool) $request->boolean('force', false),
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => "{$provider->name} account link request processed successfully.",
            'provider' => $provider->only(['id', 'code', 'name', 'status']),
            'provider_account' => $providerAccount,
        ]);
    }
}
