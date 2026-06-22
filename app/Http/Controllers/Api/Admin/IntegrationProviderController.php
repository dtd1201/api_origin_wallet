<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\RecordsAdminAudit;
use App\Http\Controllers\Controller;
use App\Models\IntegrationProvider;
use App\Services\Integrations\IntegrationProviderCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IntegrationProviderController extends Controller
{
    use RecordsAdminAudit;

    public function __construct(private readonly IntegrationProviderCatalog $providerCatalog) {}

    public function index(): JsonResponse
    {
        return response()->json(IntegrationProvider::latest('id')->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeRequest($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/i', 'unique:integration_providers,code'],
            'name' => ['required', 'string', 'max:100'],
            'logo_url' => ['nullable', 'url', 'max:2048'],
            'status' => ['nullable', 'string', 'max:30'],
        ]);

        $validated = $this->normalizePayload($validated);

        $provider = DB::transaction(function () use ($request, $validated): IntegrationProvider {
            $provider = IntegrationProvider::create($validated);
            $this->recordAdminAudit($request, 'provider.created', 'integration_provider', $provider->id, null, $provider->toArray());

            return $provider;
        });
        $this->providerCatalog->flush();

        return response()->json($provider, 201);
    }

    public function show(IntegrationProvider $integrationProvider): JsonResponse
    {
        return response()->json($integrationProvider);
    }

    public function update(Request $request, IntegrationProvider $integrationProvider): JsonResponse
    {
        $this->normalizeRequest($request);

        $validated = $request->validate([
            'code' => [
                'sometimes',
                'string',
                'max:50',
                'regex:/^[a-z0-9_-]+$/i',
                Rule::unique('integration_providers', 'code')->ignore($integrationProvider->id),
            ],
            'name' => ['sometimes', 'string', 'max:100'],
            'logo_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'status' => ['sometimes', 'string', 'max:30'],
        ]);

        $validated = $this->normalizePayload($validated);

        $before = $integrationProvider->toArray();

        $integrationProvider = DB::transaction(function () use ($before, $integrationProvider, $request, $validated): IntegrationProvider {
            $integrationProvider->update($validated);

            $integrationProvider = $integrationProvider->fresh();
            $this->recordAdminAudit($request, 'provider.updated', 'integration_provider', $integrationProvider->id, $before, $integrationProvider->toArray());

            return $integrationProvider;
        });
        $this->providerCatalog->flush();

        return response()->json($integrationProvider);
    }

    public function destroy(Request $request, IntegrationProvider $integrationProvider): JsonResponse
    {
        $before = $integrationProvider->toArray();

        DB::transaction(function () use ($before, $integrationProvider, $request): void {
            $this->recordAdminAudit($request, 'provider.deleted', 'integration_provider', $integrationProvider->id, $before, null);
            $integrationProvider->delete();
        });
        $this->providerCatalog->flush();

        return response()->json(status: 204);
    }

    private function normalizePayload(array $payload): array
    {
        if (isset($payload['code'])) {
            $payload['code'] = strtolower(trim((string) $payload['code']));
        }

        if (array_key_exists('logo_url', $payload) && blank($payload['logo_url'])) {
            $payload['logo_url'] = null;
        }

        return $payload;
    }

    private function normalizeRequest(Request $request): void
    {
        if ($request->has('code')) {
            $request->merge([
                'code' => strtolower(trim((string) $request->input('code'))),
            ]);
        }

        if ($request->has('logo_url') && blank($request->input('logo_url'))) {
            $request->merge(['logo_url' => null]);
        }
    }
}
