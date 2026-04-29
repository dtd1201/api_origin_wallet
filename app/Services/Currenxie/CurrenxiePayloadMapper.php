<?php

namespace App\Services\Currenxie;

use App\Models\User;
use App\Services\Integrations\Contracts\ProviderPayloadMapper;
use App\Services\Kyc\KycProfileSnapshot;

class CurrenxiePayloadMapper implements ProviderPayloadMapper
{
    public function buildCustomerPayload(User $user): array
    {
        return [
            'user_reference' => (string) $user->id,
            'email' => $user->email,
            'phone' => $user->phone,
            'full_name' => $user->full_name,
            'status' => $user->status,
            'kyc_status' => $user->kyc_status,
            'profile' => [
                'user_type' => $user->profile?->user_type,
                'country_code' => $user->profile?->country_code,
                'company_name' => $user->profile?->company_name,
                'company_reg_no' => $user->profile?->company_reg_no,
                'tax_id' => $user->profile?->tax_id,
                'address_line1' => $user->profile?->address_line1,
                'address_line2' => $user->profile?->address_line2,
                'city' => $user->profile?->city,
                'state' => $user->profile?->state,
                'postal_code' => $user->profile?->postal_code,
            ],
            'internal_kyc' => app(KycProfileSnapshot::class)->forUser($user),
        ];
    }

    public function buildAccountPayload(User $user, array $customerResponse): array
    {
        return [
            'customer_id' => $this->extractCustomerId($customerResponse),
            'email' => $user->email,
            'full_name' => $user->full_name,
            'country_code' => $user->profile?->country_code,
            'internal_kyc' => app(KycProfileSnapshot::class)->forUser($user),
        ];
    }

    public function extractCustomerId(array $customerResponse): ?string
    {
        return $customerResponse['id'] ?? $customerResponse['customer_id'] ?? null;
    }

    public function extractAccountId(array $accountResponse): ?string
    {
        return $accountResponse['id'] ?? $accountResponse['account_id'] ?? null;
    }

    public function extractAccountName(User $user, array $accountResponse): ?string
    {
        return $accountResponse['account_name'] ?? $user->full_name;
    }
}
