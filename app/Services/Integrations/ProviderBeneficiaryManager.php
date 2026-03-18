<?php

namespace App\Services\Integrations;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;

class ProviderBeneficiaryManager
{
    public function __construct(
        private readonly ProviderRegistry $registry,
    ) {
    }

    public function createBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        return $this->registry
            ->resolveBeneficiaryProvider($provider)
            ->createBeneficiary($provider, $beneficiary);
    }

    public function updateBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        return $this->registry
            ->resolveBeneficiaryProvider($provider)
            ->updateBeneficiary($provider, $beneficiary);
    }

    public function deleteBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): void
    {
        $this->registry
            ->resolveBeneficiaryProvider($provider)
            ->deleteBeneficiary($provider, $beneficiary);
    }
}
