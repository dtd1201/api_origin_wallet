<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\ProviderQuoteManager;
use App\Services\Transfers\TransferEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class FxQuoteController extends Controller
{
    public function index(User $user): JsonResponse
    {
        return response()->json(
            $user->fxQuotes()->latest('id')->get()
        );
    }

    public function show(User $user, FxQuote $fxQuote): JsonResponse
    {
        abort_unless($fxQuote->user_id === $user->id, 404);

        return response()->json($fxQuote);
    }

    public function store(
        Request $request,
        User $user,
        ProviderQuoteManager $manager,
        TransferEligibilityService $eligibilityService,
    ): JsonResponse {
        $validated = $request->validate([
            'provider_id' => ['required', 'exists:integration_providers,id'],
            'source_currency' => ['required', 'string', 'size:3'],
            'target_currency' => ['required', 'string', 'size:3'],
            'source_amount' => ['required', 'numeric'],
            'target_amount' => ['nullable', 'numeric'],
        ]);

        $provider = IntegrationProvider::query()->findOrFail($validated['provider_id']);

        try {
            $provider->assertSupportsCapability('quote');
            $eligibilityService->ensureUserCanCreateForProvider($user, $provider);
            $quote = $manager->createQuote($provider, $user, $validated);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json($quote, 201);
    }
}
