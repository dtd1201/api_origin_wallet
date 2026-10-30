<?php

namespace App\Http\Resources\User;

use App\Services\Nium\NiumSgdLocalPayoutGate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class BeneficiaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'is_registered' => filled($this->external_beneficiary_id),
            'beneficiary_type' => $this->beneficiary_type,
            'full_name' => $this->full_name,
            'company_name' => $this->company_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'country_code' => $this->country_code,
            'currency' => $this->currency,
            'payout_method' => $this->payout_method,
            'payout_admission' => $this->when(
                $this->country_code === 'SG' && $this->currency === 'SGD'
                    && strtolower((string) $this->provider?->code) === 'nium',
                fn () => NiumSgdLocalPayoutGate::admission($this->resource),
            ),
            'bank_name' => $this->bank_name,
            'bank_code' => $this->bank_code,
            'branch_code' => $this->branch_code,
            'account_number' => $this->account_number,
            'iban' => $this->iban,
            'swift_bic' => $this->swift_bic,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'status' => $this->status,
            'raw_data' => [
                'nium' => Arr::only((array) data_get($this->raw_data, 'nium', []), [
                    'payoutMethod', 'bankAccountType', 'beneficiaryBankAccountType', 'beneficiaryContactCountryCode',
                    'remitterBeneficiaryRelationship', 'bankCodeType', 'branchCodeType', 'routingInfo', 'additionalFields',
                ]),
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
