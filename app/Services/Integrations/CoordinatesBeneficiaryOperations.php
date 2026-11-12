<?php

namespace App\Services\Integrations;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;

/** Also fences direct adapter callers; internal create-on-update shares the same claim. */
trait CoordinatesBeneficiaryOperations
{
    public function createBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        return app(BeneficiaryOperationService::class)->execute($provider, $beneficiary, 'create',
            fn (Beneficiary $snapshot) => $this->performCreateBeneficiary($provider, $snapshot));
    }

    public function updateBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        $this->assertLocalBeneficiaryOperation($beneficiary, 'update');
        return app(BeneficiaryOperationService::class)->execute($provider, $beneficiary, 'update',
            fn (Beneficiary $snapshot) => $this->performUpdateBeneficiary($provider, $snapshot));
    }

    public function deleteBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): void
    {
        $this->assertLocalBeneficiaryOperation($beneficiary, 'delete');
        app(BeneficiaryOperationService::class)->execute($provider, $beneficiary, 'delete',
            fn (Beneficiary $snapshot) => $this->performDeleteBeneficiary($provider, $snapshot));
    }

    public function assertLocalBeneficiaryOperation(Beneficiary $beneficiary, string $action): void {}
}
