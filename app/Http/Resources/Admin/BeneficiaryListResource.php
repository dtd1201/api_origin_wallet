<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BeneficiaryListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'provider_id' => $this->provider_id,
            'external_beneficiary_id' => $this->external_beneficiary_id,
            'beneficiary_type' => $this->beneficiary_type,
            'full_name' => $this->full_name,
            'company_name' => $this->company_name,
            'country_code' => $this->country_code,
            'currency' => $this->currency,
            'bank_name' => $this->bank_name,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
