<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Transaction::latest('id')->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'provider_id' => ['required', 'exists:integration_providers,id'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'transfer_id' => ['nullable', 'exists:transfers,id'],
            'external_transaction_id' => ['required', 'string', 'max:255'],
            'transaction_type' => ['nullable', 'string', 'max:50'],
            'direction' => ['nullable', 'string', 'max:10'],
            'currency' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'numeric'],
            'fee_amount' => ['nullable', 'numeric'],
            'description' => ['nullable', 'string'],
            'reference_text' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:30'],
            'booked_at' => ['nullable', 'date'],
            'value_date' => ['nullable', 'date'],
            'raw_data' => ['nullable', 'array'],
        ]);

        $transaction = DB::transaction(fn () => Transaction::create($validated));

        return response()->json($transaction, 201);
    }

    public function show(Transaction $transaction): JsonResponse
    {
        return response()->json($transaction->load(['bankAccount', 'transfer']));
    }

    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['sometimes', 'exists:users,id'],
            'provider_id' => ['sometimes', 'exists:integration_providers,id'],
            'bank_account_id' => ['sometimes', 'nullable', 'exists:bank_accounts,id'],
            'transfer_id' => ['sometimes', 'nullable', 'exists:transfers,id'],
            'external_transaction_id' => ['sometimes', 'string', 'max:255'],
            'transaction_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'direction' => ['sometimes', 'nullable', 'string', 'max:10'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'amount' => ['sometimes', 'numeric'],
            'fee_amount' => ['sometimes', 'numeric'],
            'description' => ['sometimes', 'nullable', 'string'],
            'reference_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', 'max:30'],
            'booked_at' => ['sometimes', 'nullable', 'date'],
            'value_date' => ['sometimes', 'nullable', 'date'],
            'raw_data' => ['sometimes', 'nullable', 'array'],
        ]);

        $transaction = DB::transaction(function () use ($transaction, $validated): Transaction {
            $transaction->update($validated);

            return $transaction->fresh();
        });

        return response()->json($transaction);
    }

    public function destroy(Transaction $transaction): JsonResponse
    {
        DB::transaction(fn () => $transaction->delete());

        return response()->json(status: 204);
    }
}
