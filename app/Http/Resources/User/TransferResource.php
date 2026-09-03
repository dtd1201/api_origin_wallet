<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transfer_no' => $this->transfer_no,
            'provider_id' => $this->provider_id,
            'source_bank_account_id' => $this->source_bank_account_id,
            'beneficiary_id' => $this->beneficiary_id,
            'fx_quote_id' => $this->fx_quote_id,
            'transfer_type' => $this->transfer_type,
            'source_currency' => $this->source_currency,
            'target_currency' => $this->target_currency,
            'source_amount' => $this->source_amount,
            'target_amount' => $this->target_amount,
            'fx_rate' => $this->fx_rate,
            'fee_amount' => $this->fee_amount,
            'fee_currency' => $this->fee_currency,
            'purpose_code' => $this->purpose_code,
            'reference_text' => $this->reference_text,
            'client_reference' => $this->client_reference,
            'status' => $this->status,
            'failure_reason' => $this->customerFailureMessage(),
            'compliance_review_required' => $this->compliance_review_required,
            'compliance_status' => $this->compliance_status,
            'compliance_reviewed_at' => $this->compliance_reviewed_at,
            'submitted_at' => $this->submitted_at,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'beneficiary' => $this->whenLoaded('beneficiary', fn () => $this->beneficiary
                ? (new BeneficiaryResource($this->beneficiary))->resolve($request)
                : null),
            'source_bank_account' => $this->whenLoaded('sourceBankAccount', fn () => $this->sourceBankAccount
                ? (new BankAccountResource($this->sourceBankAccount))->resolve($request)
                : null),
            'transactions' => $this->whenLoaded('transactions', fn () => TransactionResource::collection($this->transactions)->resolve($request)),
        ];
    }

    private function customerFailureMessage(): ?string
    {
        return match ((string) $this->status) {
            'submission_unknown' => 'The provider submission outcome is unknown. Do not submit this transfer again; sync its status or contact support.',
            'rejected' => 'This transfer was rejected.',
            'failed' => 'This transfer could not be completed.',
            'cancelled' => 'This transfer was cancelled.',
            default => null,
        };
    }
}
