<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntegrationProviderController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(IntegrationProvider::latest('id')->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:integration_providers,code'],
            'name' => ['required', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:30'],
        ]);

        $provider = DB::transaction(fn () => IntegrationProvider::create($validated));

        return response()->json($provider, 201);
    }

    public function show(IntegrationProvider $integrationProvider): JsonResponse
    {
        return response()->json($integrationProvider);
    }

    public function update(Request $request, IntegrationProvider $integrationProvider): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:50', 'unique:integration_providers,code,'.$integrationProvider->id],
            'name' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'max:30'],
        ]);

        $integrationProvider = DB::transaction(function () use ($integrationProvider, $validated): IntegrationProvider {
            $integrationProvider->update($validated);

            return $integrationProvider->fresh();
        });

        return response()->json($integrationProvider);
    }

    public function destroy(IntegrationProvider $integrationProvider): JsonResponse
    {
        DB::transaction(fn () => $integrationProvider->delete());

        return response()->json(status: 204);
    }
}
