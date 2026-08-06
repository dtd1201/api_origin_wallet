<?php

namespace App\Services\Nium;

use RuntimeException;
use Throwable;

final class NiumEvidencePersistenceException extends RuntimeException
{
    public function __construct(public readonly array $safeEvidence, ?Throwable $previous = null)
    {
        parent::__construct('Nium operational evidence persistence failed; provider response processing was stopped.', 0, $previous);
    }
}
