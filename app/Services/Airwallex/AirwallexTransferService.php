<?php

namespace App\Services\Airwallex;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Services\Integrations\Contracts\TransferProvider;
use RuntimeException;

class AirwallexTransferService implements TransferProvider
{
    public function submitTransfer(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        throw new RuntimeException('Airwallex transfer API is not configured yet.');
    }

    public function syncTransferStatus(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        throw new RuntimeException('Airwallex transfer status sync is not configured yet.');
    }
}
