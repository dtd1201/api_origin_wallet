<?php

namespace App\Services\Integrations;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\User;
use Closure;

class ProviderBeneficiaryManager
{
    public function __construct(
        private readonly ProviderRegistry $registry,
    ) {
    }

    public function createForUser(IntegrationProvider $provider, User $user, array $attributes): Beneficiary
    {
        $provider->assertSupportsCapability('beneficiary');
        $adapter = $this->registry->resolveBeneficiaryProvider($provider);
        return app(BeneficiaryOperationService::class)->create($user, $provider, $attributes,
            fn (Beneficiary $snapshot) => $adapter->createBeneficiary($provider, $snapshot));
    }

    public function updateForUser(IntegrationProvider $provider, Beneficiary $beneficiary, Closure $prepare, ?array $request = null): Beneficiary
    {
        $provider->assertSupportsCapability('beneficiary');
        $adapter = $this->registry->resolveBeneficiaryProvider($provider);
        return app(BeneficiaryOperationService::class)->execute($provider, $beneficiary, 'update',
            fn (Beneficiary $snapshot) => $adapter->updateBeneficiary($provider, $snapshot),
            function (Beneficiary $snapshot) use ($prepare, $adapter): void {
                // Run business validation on the owner-locked candidate before the
                // local entitlement check and before persisting any dispatch claim.
                $prepare($snapshot);
                if (method_exists($adapter, 'assertLocalBeneficiaryOperation')) {
                    $adapter->assertLocalBeneficiaryOperation($snapshot, 'update');
                }
            }, $request);
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
