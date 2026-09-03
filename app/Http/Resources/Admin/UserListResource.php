<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'phone' => $this->phone,
            'full_name' => $this->full_name,
            'status' => $this->status,
            'kyc_status' => $this->kyc_status,
            'profile' => $this->whenLoaded('profile', fn () => $this->profilePayload()),
            'kyc_profile' => $this->whenLoaded('kycProfile', fn () => $this->kycProfilePayload()),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => [
                'id' => $role->id,
                'user_id' => $role->user_id,
                'role_code' => $role->role_code,
                'created_at' => $role->created_at,
            ])->values()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    protected function profilePayload(): ?array
    {
        if ($this->profile === null) {
            return null;
        }

        return [
            'user_id' => $this->profile->user_id,
            'user_type' => $this->profile->user_type,
            'country_code' => $this->profile->country_code,
            'company_name' => $this->profile->company_name,
            'company_reg_no' => $this->profile->company_reg_no,
        ];
    }

    protected function kycProfilePayload(): ?array
    {
        if ($this->kycProfile === null) {
            return null;
        }

        return [
            'id' => $this->kycProfile->id,
            'user_id' => $this->kycProfile->user_id,
            'status' => $this->kycProfile->status,
            'applicant_type' => $this->kycProfile->applicant_type,
            'legal_name' => $this->kycProfile->legal_name,
            'nationality_country_code' => $this->kycProfile->nationality_country_code,
            'residence_country_code' => $this->kycProfile->residence_country_code,
            'business_name' => $this->kycProfile->business_name,
            'registered_country_code' => $this->kycProfile->registered_country_code,
            'country_code' => $this->kycProfile->country_code,
            'submitted_at' => $this->kycProfile->submitted_at,
            'reviewed_at' => $this->kycProfile->reviewed_at,
            'created_at' => $this->kycProfile->created_at,
            'updated_at' => $this->kycProfile->updated_at,
        ];
    }
}
