<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class BalanceController extends Controller
{
    public function index(User $user): JsonResponse
    {
        return response()->json(
            $user->balances()->latest('id')->get()
        );
    }
}
