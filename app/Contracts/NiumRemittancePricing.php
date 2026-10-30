<?php

namespace App\Contracts;

use App\Models\Transfer;

/** Internal pricing adapter contract, never a NIUM response schema or customer input. */
interface NiumRemittancePricing
{
    /** Explicit read-only refresh outside database transactions. No payment or FX acquisition. */
    public function refresh(Transfer $transfer): void;

    /**
     * Local verified configuration only; must never perform I/O while authorizing funds.
     * A confirmed result must bind intent, version, absolute expiry, complete source-wallet
     * fees and exact total debit. Return unavailable whenever provider facts are incomplete.
     */
    public function quote(Transfer $transfer): array;
}
