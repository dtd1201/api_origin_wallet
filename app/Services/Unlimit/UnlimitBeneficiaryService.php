<?php

namespace App\Services\Unlimit;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Services\Integrations\Contracts\BeneficiaryProvider;
use App\Services\Integrations\BeneficiaryOperationService;
use App\Services\Integrations\CoordinatesBeneficiaryOperations;
use RuntimeException;

class UnlimitBeneficiaryService implements BeneficiaryProvider
{
    use CoordinatesBeneficiaryOperations;

    public function __construct(
        private readonly UnlimitPayoutPayloadFactory $payloadFactory,
    ) {
    }

    protected function performCreateBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        return $this->persistMappedBeneficiary($provider, $beneficiary, 'create');
    }

    protected function performUpdateBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): Beneficiary
    {
        return $this->persistMappedBeneficiary($provider, $beneficiary, 'update');
    }

    protected function performDeleteBeneficiary(IntegrationProvider $provider, Beneficiary $beneficiary): void
    {
        BeneficiaryOperationService::write($beneficiary, [
            'status' => 'inactive',
            'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                'unlimit' => array_merge((array) (($beneficiary->raw_data ?? [])['unlimit'] ?? []), [
                    'deleted_locally_at' => now()->toISOString(),
                ]),
            ]),
        ]);
    }

    private function persistMappedBeneficiary(
        IntegrationProvider $provider,
        Beneficiary $beneficiary,
        string $action,
    ): Beneficiary {
        $payoutAccount = $this->payloadFactory->payoutAccount($beneficiary);

        if (empty($payoutAccount['ewallet_account']) && empty($payoutAccount['card_account']) && empty($payoutAccount['payment_data'])) {
            BeneficiaryOperationService::write($beneficiary, [
                'status' => "{$action}_failed",
                'raw_data' => array_merge($beneficiary->raw_data ?? [], [
                    "{$action}_error" => [
                        'message' => 'Unlimit beneficiary requires ewallet_account, card_account, payment_data, or account details for payout.',
                    ],
                ]),
            ]);

            throw new RuntimeException('Unlimit beneficiary requires payout account details.');
        }

        $rawData = $beneficiary->raw_data ?? [];
        $unlimit = (array) ($rawData['unlimit'] ?? []);

        BeneficiaryOperationService::write($beneficiary, [
            'external_beneficiary_id' => $beneficiary->external_beneficiary_id ?: 'unlimit-bnf-'.$beneficiary->id,
            'status' => 'active',
            'raw_data' => array_merge($rawData, [
                'unlimit' => array_merge($unlimit, [
                    'payout_account' => $payoutAccount,
                    'mapped_for_provider' => $provider->code,
                    'mapped_at' => now()->toISOString(),
                ]),
            ]),
        ]);

        return BeneficiaryOperationService::current($beneficiary);
    }
}
