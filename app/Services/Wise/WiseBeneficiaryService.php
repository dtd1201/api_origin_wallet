<?php

namespace App\Services\Wise;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Services\Integrations\Contracts\BeneficiaryProvider;
use RuntimeException;

class WiseBeneficiaryService implements BeneficiaryProvider
{
    public function createBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        throw new RuntimeException('Wise beneficiary API is not configured yet.');
    }

    public function updateBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        throw new RuntimeException('Wise beneficiary API is not configured yet.');
    }

    public function deleteBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): void
    {
        throw new RuntimeException('Wise beneficiary API is not configured yet.');
    }
}
