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
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
        ];
    }
}
