<?php

namespace App\Services\Nium;

use RuntimeException;

final class NiumVirtualAccountDetailRetry extends RuntimeException
{
    public function __construct(public readonly int $delay = 60, public readonly ?string $rateKey = null)
    {
        parent::__construct('Nium VA details are unavailable; retry the read-only GET.');
    }
}
