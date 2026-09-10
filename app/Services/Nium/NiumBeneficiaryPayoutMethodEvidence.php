<?php

namespace App\Services\Nium;

final readonly class NiumBeneficiaryPayoutMethodEvidence
{
    public function __construct(
        public int $apiRequestLogId,
        public string $payoutMethod,
        public string $externalBeneficiaryId,
    ) {}
}
