<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Support\PrimaryProvider;
use Illuminate\Http\JsonResponse;

class ProviderSyncController extends Controller
{
    public function syncUser(
        IntegrationProvider $provider,
        User $user,
    ): JsonResponse {
        abort_unless(PrimaryProvider::isPrimary($provider), 404);

        return response()->json([
            'message' => 'Nium onboarding is handled through KYC/KYB approval and Nium account setup, not the legacy provider sync route.',
            'provider' => $provider->summaryPayload(),
            'user_id' => $user->id,
        ], 422);
    }
}
