<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionListResource extends JsonResource
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
            'status' => $this->status,
            'booked_at' => $this->booked_at,
            'value_date' => $this->value_date,
            'compliance_review_required' => $this->compliance_review_required,
            'compliance_status' => $this->compliance_status,
            'compliance_reviewed_at' => $this->compliance_reviewed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
