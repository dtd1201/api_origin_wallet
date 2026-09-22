<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;

class BeneficiaryDetailResource extends BeneficiaryListResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'email' => $this->email,
            'phone' => $this->phone,
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
        ];
    }
}
