<?php

namespace App\Services\Nium;

use RuntimeException;

class NiumProviderIdConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $field,
        public readonly string $currentFingerprint,
        public readonly string $incomingFingerprint,
    ) {
        parent::__construct("Authenticated Nium state conflicts with the verified {$field}.");
    }
}
