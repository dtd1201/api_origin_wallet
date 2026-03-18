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
        $providerConfig = (array) config('integrations.providers.'.strtolower($provider->code), []);

        $supportsOnboarding = isset($providerConfig['onboarding']);
        $supportsBeneficiaries = isset($providerConfig['beneficiary']);
        $supportsDataSync = isset($providerConfig['data_sync']);
        $supportsQuotes = isset($providerConfig['quote']);
        $supportsTransfers = isset($providerConfig['transfer']);
        $supportsWebhooks = isset($providerConfig['webhook']);

        return [
            'id' => $provider->id,
            'code' => $provider->code,
            'name' => $provider->name,
            'status' => $provider->status,
            'is_available_for_onboarding' => $provider->status === 'active' && $supportsOnboarding,
            'supports_beneficiaries' => $supportsBeneficiaries,
            'supports_data_sync' => $supportsDataSync,
            'supports_quotes' => $supportsQuotes,
            'supports_transfers' => $supportsTransfers,
            'supports_webhooks' => $supportsWebhooks,
        ];
    }
}
