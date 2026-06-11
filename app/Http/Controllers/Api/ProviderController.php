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
            ->get(['id', 'code', 'name', 'logo_url', 'status']);

        return response()->json([
            'data' => $providers->map(fn (IntegrationProvider $provider) => $this->transformProvider($provider))->values(),
        ]);
    }

    private function transformProvider(IntegrationProvider $provider): array
    {
        return $provider->publicPayload();
    }
}
