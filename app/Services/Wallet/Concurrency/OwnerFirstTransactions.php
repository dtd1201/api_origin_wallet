<?php

declare(strict_types=1);

namespace App\Services\Wallet\Concurrency;

use Closure;
use Illuminate\Database\Connection;

interface OwnerFirstTransactions
{
    /** @param list<int|string> $ownerIds Callback receives the authenticated context. */
    public function run(Connection $connection, array $ownerIds, Closure $work, int $attempts = 1): mixed;

    public function join(OwnerLockContext $context, Connection $connection, array $ownerIds, Closure $work): mixed;

    public function assertActive(OwnerLockContext $context, Connection $connection, array $ownerIds): void;

    public function requireActive(Connection $connection, array $ownerIds): OwnerLockContext;

    public function afterRollback(OwnerLockContext $context, Closure $work): void;

    public function assertOutsideTransactions(): void;
}
