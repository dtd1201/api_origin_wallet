<?php

namespace App\Services\Nium;

use RuntimeException;

final class NiumBeneficiarySchemaException extends RuntimeException
{
    // Only application-defined codes and structural paths belong here, never provider values.
    public function __construct(public readonly string $reason, public readonly string $location = 'schema')
    {
        parent::__construct("Nium beneficiary schema cannot be normalized ({$reason} at {$location}).");
    }
}
