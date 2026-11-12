<?php

declare(strict_types=1);

namespace App\Services\Wallet\Concurrency;

/** Authority comes from the coordinator's private object registry, never from this token alone. */
final class OwnerLockContext
{
    private function __clone() {}

    public function __serialize(): array
    {
        throw new OwnerLockContractViolation('Owner lock contexts cannot leave their transaction.');
    }

    public function __unserialize(array $data): void
    {
        throw new OwnerLockContractViolation('Owner lock contexts cannot be restored.');
    }
}
