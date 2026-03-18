<?php

namespace App\Services\Integrations\Contracts;

use App\Models\IntegrationProvider;
use App\Models\Transfer;

interface TransferProvider
{
    public function submitTransfer(IntegrationProvider $provider, Transfer $transfer): Transfer;
}
