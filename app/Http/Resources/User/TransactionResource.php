<?php

namespace App\Http\Resources\User;

use App\Models\Balance;
use App\Models\UserProviderAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'provider_id' => $this->provider_id,
            'bank_account_id' => $this->bank_account_id,
            'transfer_id' => $this->transfer_id,
            'external_transaction_id' => $this->external_transaction_id,
            'transaction_type' => $this->transaction_type,
            'direction' => $this->direction,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'fee_amount' => $this->fee_amount,
            'description' => $this->description,
            'reference_text' => $this->reference_text,
            'status' => $this->status,
            'booked_at' => $this->booked_at,
            'value_date' => $this->value_date,
            'compliance_review_required' => $this->compliance_review_required,
            'compliance_status' => $this->compliance_status,
            'compliance_reviewed_at' => $this->compliance_reviewed_at,
            'wallet_context' => $this->walletContext(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function walletContext(): ?array
    {
        $rawData = (array) ($this->raw_data ?? []);
        $walletHashId = $rawData['wallet_hash_id'] ?? null;

        if (! filled($walletHashId)) {
            return null;
        }

        $providerAccount = UserProviderAccount::query()
            ->where('user_id', $this->user_id)
            ->where('provider_id', $this->provider_id)
            ->where('external_account_id', $walletHashId)
            ->first();

        if (! $providerAccount) {
            return null;
        }

        $currency = strtoupper((string) $this->currency);

        $virtualAccounts = $providerAccount->niumVirtualAccounts()
            ->where('currency', $currency)
            ->get();

        // Never guess when more than one VA exists for the same wallet/currency.
        $virtualAccount = $virtualAccounts->count() === 1
            ? $virtualAccounts->first()
            : null;

        $balance = Balance::query()
            ->where('user_id', $this->user_id)
            ->where('provider_id', $this->provider_id)
            ->where('external_account_id', $walletHashId)
            ->where('currency', $currency)
            ->first();

        return [
            'provider_account_id' => $providerAccount->id,

            'virtual_account_id' => $virtualAccount?->id,
            'virtual_account_reference' => $virtualAccount?->virtual_account_reference,
            'provider_payment_id' => $virtualAccount?->provider_payment_id,
            'virtual_account_currency' => $virtualAccount?->currency,
            'account_category' => $virtualAccount?->account_category,
            'account_type' => $virtualAccount?->account_type,
            'virtual_account_status' => $virtualAccount?->status,

            'currency' => $balance?->currency ?? $currency,
            'available_balance' => $balance?->available_balance,
            'reserved_balance' => $balance?->reserved_balance,
            'ledger_balance' => $balance?->ledger_balance,
        ];
    }
}
