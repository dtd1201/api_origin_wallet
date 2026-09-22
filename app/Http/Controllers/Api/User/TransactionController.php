<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\TransactionResource;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Wallet\TransactionWalletContextService;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    public function index(
        User $user,
        TransactionWalletContextService $walletContextService,
    ): JsonResponse {
        $transactions = $user->transactions()->latest('id')->get();

        foreach ($transactions as $transaction) {
            $transaction->setAttribute(
                'wallet_context_projection',
                $walletContextService->context($transaction)
            );
        }

        return response()->json(
            TransactionResource::collection($transactions)->resolve()
        );
    }

    public function show(
        User $user,
        Transaction $transaction,
        TransactionWalletContextService $walletContextService,
    ): JsonResponse {
        abort_unless($transaction->user_id === $user->id, 404);

        $transaction->load(['bankAccount', 'transfer']);

        $transaction->setAttribute(
            'wallet_context_projection',
            $walletContextService->context($transaction)
        );

        return response()->json(
            (new TransactionResource($transaction))->resolve()
        );
    }
}
