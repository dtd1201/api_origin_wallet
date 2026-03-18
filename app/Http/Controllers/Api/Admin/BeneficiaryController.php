<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Beneficiary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BeneficiaryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Beneficiary::latest('id')->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'provider_id' => ['required', 'exists:integration_providers,id'],
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

        $beneficiary = DB::transaction(fn () => Beneficiary::create($validated));

        return response()->json($beneficiary, 201);
    }

    public function show(Beneficiary $beneficiary): JsonResponse
    {
        return response()->json($beneficiary);
    }

    public function update(Request $request, Beneficiary $beneficiary): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['sometimes', 'exists:users,id'],
            'provider_id' => ['sometimes', 'exists:integration_providers,id'],
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

        $beneficiary = DB::transaction(function () use ($beneficiary, $validated): Beneficiary {
            $beneficiary->update($validated);

            return $beneficiary->fresh();
        });

        return response()->json($beneficiary);
    }

    public function destroy(Beneficiary $beneficiary): JsonResponse
    {
        DB::transaction(fn () => $beneficiary->delete());

        return response()->json(status: 204);
    }
}
