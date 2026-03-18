<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankAccountController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(BankAccount::latest('id')->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'provider_id' => ['required', 'exists:integration_providers,id'],
            'external_account_id' => ['nullable', 'string', 'max:255'],
            'account_type' => ['nullable', 'string', 'max:50'],
            'currency' => ['required', 'string', 'size:3'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_code' => ['nullable', 'string', 'max:100'],
            'branch_code' => ['nullable', 'string', 'max:100'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'iban' => ['nullable', 'string', 'max:100'],
            'swift_bic' => ['nullable', 'string', 'max:50'],
            'routing_number' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:30'],
            'is_default' => ['nullable', 'boolean'],
            'raw_data' => ['nullable', 'array'],
        ]);

        $bankAccount = DB::transaction(fn () => BankAccount::create($validated));

        return response()->json($bankAccount, 201);
    }

    public function show(BankAccount $bankAccount): JsonResponse
    {
        return response()->json($bankAccount);
    }

    public function update(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['sometimes', 'exists:users,id'],
            'provider_id' => ['sometimes', 'exists:integration_providers,id'],
            'external_account_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'account_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'branch_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'account_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'account_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'iban' => ['sometimes', 'nullable', 'string', 'max:100'],
            'swift_bic' => ['sometimes', 'nullable', 'string', 'max:50'],
            'routing_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'max:30'],
            'is_default' => ['sometimes', 'boolean'],
            'raw_data' => ['sometimes', 'nullable', 'array'],
        ]);

        $bankAccount = DB::transaction(function () use ($bankAccount, $validated): BankAccount {
            $bankAccount->update($validated);

            return $bankAccount->fresh();
        });

        return response()->json($bankAccount);
    }

    public function destroy(BankAccount $bankAccount): JsonResponse
    {
        DB::transaction(fn () => $bankAccount->delete());

        return response()->json(status: 204);
    }
}
