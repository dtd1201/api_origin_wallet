<?php

namespace App\Services\Nium;

use RuntimeException;
use Throwable;

/** This invocation failed before committing its remittance submission claim. */
final class NiumSubmissionPreflightException extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
