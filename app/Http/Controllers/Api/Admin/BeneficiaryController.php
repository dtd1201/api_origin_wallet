<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\BeneficiaryDetailResource;
use App\Http\Resources\Admin\BeneficiaryListResource;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Services\Integrations\BeneficiaryOperationConflict;
use App\Services\Integrations\BeneficiaryOperationService;
use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Support\PrimaryProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BeneficiaryController extends Controller
{
    public function index(): JsonResponse
    {
        return BeneficiaryListResource::collection(Beneficiary::latest('id')->paginate(15))->response();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'provider_id' => ['sometimes', 'nullable', 'exists:integration_providers,id'],
            'external_beneficiary_id' => ['nullable', 'string', 'max:255'],
            'beneficiary_type' => ['required', 'string', 'max:20'],
            'full_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'country_code' => ['required', 'string', 'size:2'],
            'currency' => ['required', 'string', 'size:3'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_code' => ['nullable', 'string', 'max:100'],
            'branch_code' => ['nullable', 'string', 'max:100'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'iban' => ['nullable', 'string', 'max:100'],
            'swift_bic' => ['nullable', 'string', 'max:50'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'string', 'max:30'],
            'raw_data' => ['nullable', 'array'],
        ]);

        $validated['provider_id'] = PrimaryProvider::resolveForRequest($validated['provider_id'] ?? null)->id;

        $beneficiary = OwnerFirst::users([(int) $validated['user_id']], function () use ($validated): Beneficiary {
            IntegrationProvider::query()->whereKey($validated['provider_id'])->lock('for key share')->firstOrFail();
            return Beneficiary::create($validated);
        });

        return response()->json((new BeneficiaryDetailResource($beneficiary))->resolve($request), 201);
    }

    public function show(Request $request, Beneficiary $beneficiary): JsonResponse
    {
        return response()->json((new BeneficiaryDetailResource($beneficiary))->resolve($request));
    }

    public function update(Request $request, Beneficiary $beneficiary): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['sometimes', 'exists:users,id'],
            'provider_id' => ['sometimes', 'nullable', 'exists:integration_providers,id'],
            'external_beneficiary_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'beneficiary_type' => ['sometimes', 'string', 'max:20'],
            'full_name' => ['sometimes', 'string', 'max:255'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'country_code' => ['sometimes', 'string', 'size:2'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'branch_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'account_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'iban' => ['sometimes', 'nullable', 'string', 'max:100'],
            'swift_bic' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'status' => ['sometimes', 'string', 'max:30'],
            'raw_data' => ['sometimes', 'nullable', 'array'],
        ]);

        if (array_key_exists('provider_id', $validated)) {
            $validated['provider_id'] = PrimaryProvider::resolveForRequest($validated['provider_id'])->id;
        }

        try {
            $beneficiary = app(BeneficiaryOperationService::class)->mutateLocally($beneficiary,
                function (Beneficiary $locked) use ($validated): Beneficiary {
                    if (! $locked->update($validated)) throw new \RuntimeException('Beneficiary edit was not persisted.');
                    return $locked->fresh();
                }, isset($validated['user_id']) ? [(int) $validated['user_id']] : [],
                isset($validated['provider_id']) ? [(int) $validated['provider_id']] : []);
        } catch (BeneficiaryOperationConflict) {
            return response()->json(['message' => 'The beneficiary has an unresolved provider operation.'], 409);
        }

        return response()->json((new BeneficiaryDetailResource($beneficiary))->resolve($request));
    }

    public function destroy(Beneficiary $beneficiary): JsonResponse
    {
        try {
            app(BeneficiaryOperationService::class)->mutateLocally($beneficiary, function (Beneficiary $locked): void {
                if (! $locked->delete()) throw new \RuntimeException('Beneficiary deletion was not persisted.');
            });
        } catch (BeneficiaryOperationConflict) {
            return response()->json(['message' => 'The beneficiary has an unresolved provider operation.'], 409);
        }

        return response()->json(status: 204);
    }
}
