<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransferController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Transfer::latest('id')->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'transfer_no' => ['required', 'string', 'max:50', 'unique:transfers,transfer_no'],
            'user_id' => ['required', 'exists:users,id'],
            'provider_id' => ['required', 'exists:integration_providers,id'],
            'source_bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'beneficiary_id' => ['nullable', 'exists:beneficiaries,id'],
            'external_transfer_id' => ['nullable', 'string', 'max:255'],
            'external_payment_id' => ['nullable', 'string', 'max:255'],
            'transfer_type' => ['required', 'string', 'max:30'],
            'source_currency' => ['required', 'string', 'size:3'],
            'target_currency' => ['required', 'string', 'size:3'],
            'source_amount' => ['required', 'numeric'],
            'target_amount' => ['nullable', 'numeric'],
            'fx_rate' => ['nullable', 'numeric'],
            'fee_amount' => ['nullable', 'numeric'],
            'fee_currency' => ['nullable', 'string', 'size:3'],
            'purpose_code' => ['nullable', 'string', 'max:100'],
            'reference_text' => ['nullable', 'string', 'max:255'],
            'client_reference' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:30'],
            'failure_code' => ['nullable', 'string', 'max:100'],
            'failure_reason' => ['nullable', 'string'],
            'submitted_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
            'raw_data' => ['nullable', 'array'],
        ]);

        $transfer = DB::transaction(fn () => Transfer::create($validated));

        return response()->json($transfer, 201);
    }

    public function show(Transfer $transfer): JsonResponse
    {
        return response()->json($transfer->load(['beneficiary', 'sourceBankAccount', 'approvals', 'transactions']));
    }

    public function update(Request $request, Transfer $transfer): JsonResponse
    {
        $validated = $request->validate([
            'transfer_no' => ['sometimes', 'string', 'max:50', 'unique:transfers,transfer_no,'.$transfer->id],
            'user_id' => ['sometimes', 'exists:users,id'],
            'provider_id' => ['sometimes', 'exists:integration_providers,id'],
            'source_bank_account_id' => ['sometimes', 'nullable', 'exists:bank_accounts,id'],
            'beneficiary_id' => ['sometimes', 'nullable', 'exists:beneficiaries,id'],
            'external_transfer_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'external_payment_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'transfer_type' => ['sometimes', 'string', 'max:30'],
            'source_currency' => ['sometimes', 'string', 'size:3'],
            'target_currency' => ['sometimes', 'string', 'size:3'],
            'source_amount' => ['sometimes', 'numeric'],
            'target_amount' => ['sometimes', 'nullable', 'numeric'],
            'fx_rate' => ['sometimes', 'nullable', 'numeric'],
            'fee_amount' => ['sometimes', 'numeric'],
            'fee_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'purpose_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'reference_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'client_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'max:30'],
            'failure_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'failure_reason' => ['sometimes', 'nullable', 'string'],
            'submitted_at' => ['sometimes', 'nullable', 'date'],
            'completed_at' => ['sometimes', 'nullable', 'date'],
            'raw_data' => ['sometimes', 'nullable', 'array'],
        ]);

        $transfer = DB::transaction(function () use ($transfer, $validated): Transfer {
            $transfer->update($validated);

            return $transfer->fresh();
        });

        return response()->json($transfer);
    }

    public function destroy(Transfer $transfer): JsonResponse
    {
        DB::transaction(fn () => $transfer->delete());

        return response()->json(status: 204);
    }
}
