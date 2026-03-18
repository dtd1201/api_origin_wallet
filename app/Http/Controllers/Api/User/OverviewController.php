<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class OverviewController extends Controller
{
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'user' => $user->only(['id', 'email', 'full_name', 'status', 'kyc_status']),
            'summary' => [
                'bank_accounts_count' => $user->bankAccounts()->count(),
                'beneficiaries_count' => $user->beneficiaries()->count(),
                'transfers_count' => $user->transfers()->count(),
                'transactions_count' => $user->transactions()->count(),
            ],
        ]);
    }
}
