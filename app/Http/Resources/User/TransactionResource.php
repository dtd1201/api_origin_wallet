<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
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
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
