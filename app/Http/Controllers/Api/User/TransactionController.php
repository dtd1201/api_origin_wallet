<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    public function index(User $user): JsonResponse
    {
        return response()->json(
            $user->transactions()->latest('id')->get()
        );
    }

    public function show(User $user, Transaction $transaction): JsonResponse
    {
        abort_unless($transaction->user_id === $user->id, 404);

        return response()->json(
            $transaction->load(['bankAccount', 'transfer'])
        );
    }
}
