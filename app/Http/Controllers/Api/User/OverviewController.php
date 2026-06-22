<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class OverviewController extends Controller
{
    public function show(User $user): JsonResponse
    {
        $user->loadCount(['bankAccounts', 'beneficiaries', 'transfers', 'transactions']);

        return response()->json([
            'user' => $user->only(['id', 'email', 'full_name', 'status', 'kyc_status']),
            'summary' => [
                'bank_accounts_count' => $user->bank_accounts_count,
                'beneficiaries_count' => $user->beneficiaries_count,
                'transfers_count' => $user->transfers_count,
                'transactions_count' => $user->transactions_count,
            ],
        ]);
    }
}
