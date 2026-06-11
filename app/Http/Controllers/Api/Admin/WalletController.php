<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Balance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_id' => ['nullable', 'integer', 'exists:integration_providers,id'],
            'provider_code' => ['nullable', 'string', 'max:50'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $balances = Balance::query()
            ->with(['user', 'provider', 'bankAccount'])
            ->when(filled($validated['provider_id'] ?? null), fn ($query) => $query->where('provider_id', $validated['provider_id']))
            ->when(filled($validated['provider_code'] ?? null), function ($query) use ($validated): void {
                $query->whereHas('provider', fn ($query) => $query->where('code', $validated['provider_code']));
            })
            ->when(filled($validated['user_id'] ?? null), fn ($query) => $query->where('user_id', $validated['user_id']))
            ->when(filled($validated['currency'] ?? null), fn ($query) => $query->where('currency', strtoupper((string) $validated['currency'])))
            ->when(filled($validated['search'] ?? null), function ($query) use ($validated): void {
                $search = (string) $validated['search'];

                $query->where(function ($query) use ($search): void {
                    $query->where('external_account_id', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($query) use ($search): void {
                            $query->where('email', 'like', "%{$search}%")
                                ->orWhere('full_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('provider', function ($query) use ($search): void {
                            $query->where('code', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('id')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json($balances->through(fn (Balance $balance) => $this->payload($balance)));
    }

    public function show(Balance $wallet): JsonResponse
    {
        return response()->json($this->payload($wallet->load(['user', 'provider', 'bankAccount'])));
    }

    private function payload(Balance $balance): array
    {
        return [
            'id' => $balance->id,
            'user_id' => $balance->user_id,
            'user' => $balance->user ? [
                'id' => $balance->user->id,
                'email' => $balance->user->email,
                'full_name' => $balance->user->full_name,
                'status' => $balance->user->status,
                'kyc_status' => $balance->user->kyc_status,
            ] : null,
            'provider_id' => $balance->provider_id,
            'provider' => $balance->provider?->summaryPayload(),
            'provider_code' => $balance->provider?->code,
            'account_reference' => $balance->external_account_id,
            'currency' => $balance->currency,
            'available_balance' => $balance->available_balance,
            'ledger_balance' => $balance->ledger_balance,
            'hold_balance' => $balance->reserved_balance,
            'status' => 'active',
            'last_reconciled_at' => $balance->as_of,
            'created_at' => $balance->created_at,
            'updated_at' => $balance->updated_at,
        ];
    }
}
