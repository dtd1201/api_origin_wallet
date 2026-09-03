<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\TransactionDetailResource;
use App\Http\Resources\Admin\TransactionListResource;
use App\Models\Transaction;
use App\Support\PrimaryProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    public function index(): JsonResponse
    {
        return TransactionListResource::collection(Transaction::latest('id')->paginate(15))->response();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'provider_id' => ['sometimes', 'nullable', 'exists:integration_providers,id'],
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

        $validated['provider_id'] = PrimaryProvider::resolveForRequest($validated['provider_id'] ?? null)->id;

        $transaction = DB::transaction(fn () => Transaction::create($validated));

        return response()->json((new TransactionDetailResource($transaction))->resolve($request), 201);
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        return response()->json((new TransactionDetailResource($transaction))->resolve($request));
    }

    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['sometimes', 'exists:users,id'],
            'provider_id' => ['sometimes', 'nullable', 'exists:integration_providers,id'],
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

        if (array_key_exists('provider_id', $validated)) {
            $validated['provider_id'] = PrimaryProvider::resolveForRequest($validated['provider_id'])->id;
        }

        $transaction = DB::transaction(function () use ($transaction, $validated): Transaction {
            $transaction->update($validated);

            return $transaction->fresh();
        });

        return response()->json((new TransactionDetailResource($transaction))->resolve($request));
    }

    public function destroy(Transaction $transaction): JsonResponse
    {
        DB::transaction(fn () => $transaction->delete());

        return response()->json(status: 204);
    }
}
