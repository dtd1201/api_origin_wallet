<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankAccountListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'provider_id' => $this->provider_id,
            'account_type' => $this->account_type,
            'currency' => $this->currency,
            'country_code' => $this->country_code,
            'bank_name' => $this->bank_name,
            'account_name' => $this->account_name,
            'status' => $this->status,
            'is_default' => $this->is_default,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
