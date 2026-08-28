<?php

namespace App\Services\Nium;

use RuntimeException;
use Throwable;

final class NiumTransactionRfiManualReconciliationException extends RuntimeException
{
    public function __construct(public readonly array $safeEvidence, ?Throwable $previous = null)
    {
        parent::__construct(
            'Nium Transaction RFI final outcome could not be persisted reliably; manual reconciliation is required and provider submission must not be repeated.',
            0,
            $previous,
        );
    }
}
