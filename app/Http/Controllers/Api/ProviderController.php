<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Integrations\IntegrationProviderCatalog;
use Illuminate\Http\JsonResponse;

class ProviderController extends Controller
{
    public function index(IntegrationProviderCatalog $providerCatalog): JsonResponse
    {
        return response()->json([
            'data' => $providerCatalog->activePublicPayloads(),
        ]);
    }
}
