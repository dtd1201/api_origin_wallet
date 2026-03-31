<?php

namespace App\Services\Wise;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Services\Integrations\Contracts\TransferProvider;
use RuntimeException;

class WiseTransferService implements TransferProvider
{
    public function submitTransfer(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        throw new RuntimeException('Wise transfer API is not configured yet.');
    }

    public function syncTransferStatus(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        throw new RuntimeException('Wise transfer status sync is not configured yet.');
    }
}
