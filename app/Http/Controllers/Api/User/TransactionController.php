<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\TransactionResource;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    public function index(User $user): JsonResponse
    {
        return response()->json(
            TransactionResource::collection(
                $user->transactions()->latest('id')->get()
            )->resolve()
        );
    }

    public function show(User $user, Transaction $transaction): JsonResponse
    {
        abort_unless($transaction->user_id === $user->id, 404);

        return response()->json(
            (new TransactionResource(
                $transaction->load(['bankAccount', 'transfer'])
            ))->resolve()
        );
    }
}
