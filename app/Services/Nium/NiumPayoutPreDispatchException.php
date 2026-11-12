<?php

namespace App\Services\Nium;

use RuntimeException;

/** The final local check prevented dispatch; no remittance request was sent. */
final class NiumPayoutPreDispatchException extends RuntimeException
{
    /** Set only by the submitting service after this transport proves no wire. */
    public ?string $noWireOperationKey = null;
}
