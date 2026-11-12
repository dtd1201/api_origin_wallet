<?php

declare(strict_types=1);

namespace App\Services\Wallet\Concurrency;

use App\Models\User;
use Closure;
use Fiber;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Contracts\Events\Dispatcher;
use PDO;
use Throwable;
use WeakMap;

final class PostgresOwnerFirstTransactions implements OwnerFirstTransactions
{
    /** @var WeakMap<OwnerLockContext, object> */
    private WeakMap $contexts;

    public function __construct(private readonly DatabaseManager $database, Dispatcher $events)
    {
        $this->contexts = new WeakMap;
        $events->listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            foreach ($this->contexts as $state) {
                if ($state->connection === $event->connection) {
                    $state->rollbackOnly = true;
                }
            }
        });
    }

    public function run(Connection $connection, array $ownerIds, Closure $work, int $attempts = 1): mixed
    {
        $this->assertOutsideTransactions();
        if ($connection->getDriverName() !== 'pgsql' || $attempts < 1 || $attempts > 3
            || $this->database->connection($connection->getName()) !== $connection
            || $connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new OwnerLockContractViolation('Owner-first roots require PostgreSQL and one to three local attempts.');
        }
        $owners = $this->owners($ownerIds);
        for ($attempt = 1; ; $attempt++) {
            $context = null;
            try {
                // Laravel itself must not hide attempts or retry a provider orchestration.
                return $connection->transaction(function () use ($connection, $owners, $work, &$context): mixed {
                    foreach ($owners as $id) {
                        (new User)->setConnection($connection->getName())->newQuery()->whereKey($id)->lockForUpdate()->firstOrFail();
                    }
                    $context = new OwnerLockContext;
                    $this->contexts[$context] = (object) [
                        'connection' => $connection, 'pdo' => $connection->getPdo(),
                        'identity' => $this->identity($connection), 'owners' => $owners,
                        'execution' => $this->execution(), 'working' => true, 'rollbackOnly' => false, 'afterRollback' => [],
                    ];
                    $result = $this->join($context, $connection, $owners, $work);
                    $this->assertActive($context, $connection, $owners);
                    $this->contexts[$context]->working = false;

                    return $result;
                }, 1);
            } catch (Throwable $exception) {
                if ($attempt >= $attempts || ! in_array(self::sqlState($exception), ['40P01', '40001'], true)
                    || $connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
                    $callbacks = $context === null ? [] : ($this->contexts[$context]->afterRollback ?? []);
                    if ($context !== null) unset($this->contexts[$context]);
                    if ($connection->transactionLevel() === 0 && ! $connection->getPdo()->inTransaction()) {
                        foreach ($callbacks as $callback) $callback();
                    }
                    throw $exception;
                }
                if ($context !== null) unset($this->contexts[$context]);
                $this->assertOutsideTransactions();
            } finally {
                if ($context !== null) {
                    unset($this->contexts[$context]);
                }
            }
        }
    }

    public function join(OwnerLockContext $context, Connection $connection, array $ownerIds, Closure $work): mixed
    {
        try {
            $this->assertActive($context, $connection, $ownerIds);
            $result = $work($context);
            $this->assertActive($context, $connection, $ownerIds);

            return $result;
        } catch (Throwable $exception) {
            $this->poison($connection);
            throw $exception;
        }
    }

    public function assertActive(OwnerLockContext $context, Connection $connection, array $ownerIds): void
    {
        $state = $this->contexts[$context] ?? null;
        try {
            $required = $this->owners($ownerIds);
        } catch (Throwable $exception) {
            $this->poison($connection);
            throw $exception;
        }
        if ($state === null || ! $state->working || $state->connection !== $connection
            || $state->pdo !== $connection->getPdo() || $state->execution !== $this->execution()
            || $connection->transactionLevel() !== 1 || ! $state->pdo->inTransaction()
            || array_diff($required, $state->owners) !== []
            || $state->identity !== $this->identity($connection)) {
            $this->poison($connection);
            throw new OwnerLockContractViolation('An authenticated owner-first root on this exact connection is required.');
        }
        if ($state->rollbackOnly) {
            throw new OwnerTransactionRollbackOnly('A failed nested operation requires complete root rollback.');
        }
    }

    public function requireActive(Connection $connection, array $ownerIds): OwnerLockContext
    {
        foreach ($this->contexts as $context => $state) {
            if ($state->connection === $connection && $state->execution === $this->execution() && $state->working) {
                $this->assertActive($context, $connection, $ownerIds);

                return $context;
            }
        }
        $this->poison($connection);
        throw new OwnerLockContractViolation('Unsupported nested financial transaction.');
    }

    /** Failure receipts/quarantine run only after complete root rollback, never at a savepoint. */
    public function afterRollback(OwnerLockContext $context, Closure $work): void
    {
        $state = $this->contexts[$context] ?? null;
        if ($state === null || ! $state->working || $state->execution !== $this->execution()
            || $state->connection->getPdo() !== $state->pdo || ! $state->pdo->inTransaction()
            || $state->connection->transactionLevel() !== 1 || $state->identity !== $this->identity($state->connection)) {
            foreach ($this->contexts as $active) {
                if ($active->execution === $this->execution()) $active->rollbackOnly = true;
            }
            throw new OwnerLockContractViolation('Rollback work requires the live root context.');
        }
        $state->afterRollback[] = $work;
    }

    public function assertOutsideTransactions(): void
    {
        foreach ($this->contexts as $state) {
            if ($state->working && $state->execution === $this->execution()) {
                $state->rollbackOnly = true;
                throw new OwnerLockContractViolation('The owner-first callback has not finished.');
            }
        }
        foreach ($this->database->getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                $this->poison($connection);
                throw new OwnerLockContractViolation('Provider I/O and new roots require no active application transaction.');
            }
            foreach ([$connection->getRawPdo(), $connection->getRawReadPdo()] as $pdo) {
                if ($pdo instanceof PDO && $pdo->inTransaction()) {
                    $this->poison($connection);
                    throw new OwnerLockContractViolation('An application-managed PDO transaction is active.');
                }
            }
        }
    }

    public static function sqlState(Throwable $exception): ?string
    {
        do {
            if ($exception instanceof \PDOException && isset($exception->errorInfo[0])) {
                return (string) $exception->errorInfo[0];
            }
            if (preg_match('/^[0-9A-Z]{5}$/D', (string) $exception->getCode()) === 1) {
                return (string) $exception->getCode();
            }
        } while ($exception = $exception->getPrevious());

        return null;
    }

    private function owners(array $ids): array
    {
        $owners = [];
        foreach ($ids as $id) {
            if ((! is_int($id) && ! is_string($id)) || ! ctype_digit((string) $id)
                || (int) $id < 1 || (string) (int) $id !== (string) $id) {
                throw new OwnerLockContractViolation('Owner IDs must be positive representable integers.');
            }
            $owners[] = (int) $id;
        }
        $owners = array_values(array_unique($owners));
        sort($owners, SORT_NUMERIC);
        if ($owners === []) {
            throw new OwnerLockContractViolation('At least one owner is required.');
        }

        return $owners;
    }

    private function identity(Connection $connection): array
    {
        $row = $connection->selectOne('SELECT pg_backend_pid() AS backend, txid_current()::text AS xid', [], false);

        return [(string) $row->backend, (string) $row->xid];
    }

    private function execution(): string
    {
        $fiber = Fiber::getCurrent();

        return getmypid().':'.($fiber === null ? 'main' : spl_object_id($fiber));
    }

    private function poison(Connection $connection): void
    {
        foreach ($this->contexts as $state) {
            if ($state->connection === $connection || $state->execution === $this->execution()) {
                $state->rollbackOnly = true;
            }
        }
    }
}
