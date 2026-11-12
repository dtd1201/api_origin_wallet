<?php

namespace App\Services\Integrations;

use RuntimeException;

final class BeneficiaryOperationConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This beneficiary operation requires reconciliation before another provider attempt.');
    }
}
