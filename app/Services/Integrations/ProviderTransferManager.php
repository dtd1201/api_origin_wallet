<?php

namespace App\Services\Integrations;

use App\Models\IntegrationProvider;
use App\Models\Transfer;

class ProviderTransferManager
{
    public function __construct(
        private readonly ProviderRegistry $registry,
    ) {
    }

    public function submitTransfer(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        return $this->registry
            ->resolveTransferProvider($provider)
            ->submitTransfer($provider, $transfer);
    }

    public function syncTransferStatus(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        return $this->registry
            ->resolveTransferProvider($provider)
            ->syncTransferStatus($provider, $transfer);
    }
}
