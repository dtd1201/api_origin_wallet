<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LedgerEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'entry_type' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3'],
            'provider_id' => ['nullable', 'integer', 'exists:integration_providers,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $entries = LedgerEntry::query()
            ->with(['balance.provider', 'user', 'provider'])
            ->when(
                filled($validated['status'] ?? null) && $validated['status'] !== 'all',
                fn ($query) => $query->where('status', $validated['status'])
            )
            ->when(filled($validated['entry_type'] ?? null), fn ($query) => $query->where('entry_type', $validated['entry_type']))
            ->when(filled($validated['currency'] ?? null) && $validated['currency'] !== 'all', fn ($query) => $query->where('currency', strtoupper((string) $validated['currency'])))
            ->when(filled($validated['provider_id'] ?? null), fn ($query) => $query->where('provider_id', $validated['provider_id']))
            ->when(filled($validated['user_id'] ?? null), fn ($query) => $query->where('user_id', $validated['user_id']))
            ->when(filled($validated['search'] ?? null), function ($query) use ($validated): void {
                $search = (string) $validated['search'];

                $query->where(function ($query) use ($search): void {
                    $query->where('reference', 'like', "%{$search}%")
                        ->orWhere('source_type', 'like', "%{$search}%")
                        ->orWhere('source_id', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($query) use ($search): void {
                            $query->where('email', 'like', "%{$search}%")
                                ->orWhere('full_name', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('id')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json($entries->through(fn (LedgerEntry $entry) => $this->payload($entry)));
    }

    public function show(LedgerEntry $ledgerEntry): JsonResponse
    {
        return response()->json($this->payload($ledgerEntry->load(['balance.provider', 'user', 'provider'])));
    }

    private function payload(LedgerEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'wallet_id' => $entry->balance_id,
            'wallet' => $entry->balance ? [
                'id' => $entry->balance->id,
                'user_id' => $entry->balance->user_id,
                'provider_id' => $entry->balance->provider_id,
                'provider' => $entry->balance->provider?->summaryPayload(),
                'provider_code' => $entry->balance->provider?->code,
                'account_reference' => $entry->balance->external_account_id,
                'currency' => $entry->balance->currency,
                'available_balance' => $entry->balance->available_balance,
                'ledger_balance' => $entry->balance->ledger_balance,
                'hold_balance' => $entry->balance->reserved_balance,
                'status' => 'active',
                'last_reconciled_at' => $entry->balance->as_of,
                'created_at' => $entry->balance->created_at,
                'updated_at' => $entry->balance->updated_at,
            ] : null,
            'user_id' => $entry->user_id,
            'user' => $entry->user ? [
                'id' => $entry->user->id,
                'email' => $entry->user->email,
                'full_name' => $entry->user->full_name,
                'status' => $entry->user->status,
                'kyc_status' => $entry->user->kyc_status,
            ] : null,
            'provider_id' => $entry->provider_id,
            'provider' => $entry->provider?->summaryPayload(),
            'reference' => $entry->reference,
            'entry_type' => $entry->entry_type,
            'status' => $entry->status,
            'currency' => $entry->currency,
            'amount' => $entry->amount,
            'balance_after' => $entry->balance_after,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'description' => $entry->description,
            'posted_at' => $entry->posted_at,
            'created_at' => $entry->created_at,
        ];
    }
}
