<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;

class TransferDetailResource extends TransferListResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'beneficiary' => $this->whenLoaded('beneficiary', fn () => $this->beneficiary ? [
                'id' => $this->beneficiary->id,
                'full_name' => $this->beneficiary->full_name,
                'company_name' => $this->beneficiary->company_name,
                'country_code' => $this->beneficiary->country_code,
                'currency' => $this->beneficiary->currency,
                'bank_name' => $this->beneficiary->bank_name,
                'status' => $this->beneficiary->status,
            ] : null),
            'source_bank_account' => $this->whenLoaded('sourceBankAccount', fn () => $this->sourceBankAccount
                ? (new BankAccountListResource($this->sourceBankAccount))->resolve($request)
                : null),
            'transactions' => $this->whenLoaded('transactions', fn () => TransactionListResource::collection($this->transactions)->resolve($request)),
        ];
    }
}
