<?php

namespace App\Services\Integrations\Contracts;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;

interface BeneficiaryProvider
{
    public function createBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary;

    public function updateBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary;

    public function deleteBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): void;
}
