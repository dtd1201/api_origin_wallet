<?php

namespace App\Services\Nium;

use App\Models\Transfer;
use RuntimeException;

class NiumSgdLocalPricing
{
    public const BLOCK_REASON = 'SGD LOCAL requires verified NIUM fees and total USD wallet debit. No verified pricing adapter or authorized capped pilot policy is available.';

    /**
     * Integration boundary for a verified provider pricing contract. Neither the
     * legacy rate lock nor the internal fee_amount=0 supplies that contract.
     * Do not replace this hold with a flag, client acknowledgement or zero fallback.
     */
    public function forTransfer(Transfer $transfer): array
    {
        throw new RuntimeException(self::BLOCK_REASON);
    }
}
