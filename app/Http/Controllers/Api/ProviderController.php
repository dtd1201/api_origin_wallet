<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationProvider;
use Illuminate\Http\JsonResponse;

class ProviderController extends Controller
{
    public function index(): JsonResponse
    {
        $providers = IntegrationProvider::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'status']);

        return response()->json([
            'data' => $providers->map(fn (IntegrationProvider $provider) => $this->transformProvider($provider))->values(),
        ]);
    }

    private function transformProvider(IntegrationProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'code' => $provider->code,
            'name' => $provider->name,
            'status' => $provider->status,
            'is_available_for_onboarding' => $provider->isAvailableForOnboarding(),
            'supports_beneficiaries' => $provider->supportsBeneficiaries(),
            'supports_data_sync' => $provider->supportsDataSync(),
            'supports_quotes' => $provider->supportsQuotes(),
            'supports_transfers' => $provider->supportsTransfers(),
            'supports_webhooks' => $provider->supportsWebhooks(),
            'is_configured' => $provider->isConfigured(),
        ];
    }
}
