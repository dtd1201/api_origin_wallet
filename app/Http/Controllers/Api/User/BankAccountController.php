<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class BankAccountController extends Controller
{
    public function index(User $user): JsonResponse
    {
        return response()->json(
            $user->bankAccounts()->latest('id')->get()
        );
    }

    public function show(User $user, BankAccount $bankAccount): JsonResponse
    {
        abort_unless($bankAccount->user_id === $user->id, 404);

        return response()->json($bankAccount);
    }
}
