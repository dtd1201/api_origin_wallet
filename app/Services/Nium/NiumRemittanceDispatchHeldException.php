<?php

namespace App\Services\Nium;

use RuntimeException;

/** Transport rejected remittance before creating or sending its HTTP request. */
final class NiumRemittanceDispatchHeldException extends RuntimeException
{
    /** Set only by the submitting service after this transport proves no wire. */
    public ?string $noWireOperationKey = null;
}
