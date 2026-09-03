<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'account_type' => $this->account_type,
            'currency' => $this->currency,
            'country_code' => $this->country_code,
            'bank_name' => $this->bank_name,
            'bank_code' => $this->bank_code,
            'branch_code' => $this->branch_code,
            'account_name' => $this->account_name,
            'account_number' => $this->account_number,
            'iban' => $this->iban,
            'swift_bic' => $this->swift_bic,
            'routing_number' => $this->routing_number,
            'status' => $this->status,
            'is_default' => $this->is_default,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
