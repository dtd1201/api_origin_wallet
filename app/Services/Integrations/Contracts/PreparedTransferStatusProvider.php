<?php

namespace App\Services\Integrations\Contracts;

use App\Models\IntegrationProvider;
use App\Models\Transfer;

interface PreparedTransferStatusProvider extends TransferProvider
{
    public function prepareTransferStatusSync(IntegrationProvider $provider, Transfer $transfer): array;

    public function applyPreparedTransferStatus(
        IntegrationProvider $provider,
        Transfer $transfer,
        array $prepared,
    ): Transfer;
}
