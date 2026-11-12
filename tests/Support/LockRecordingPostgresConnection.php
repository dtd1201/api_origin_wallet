<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Wallet\Concurrency\PostgresOwnerFirstTransactions;
use Closure;
use Illuminate\Database\PostgresConnection;
use Throwable;

/** DB::listen sees successful queries only; capture failed attempts before retry handling too. */
final class LockRecordingPostgresConnection extends PostgresConnection
{
    public static ?string $sink = null;
    public static ?Closure $afterQuery = null;
    private int $attempt = 0;
    private ?\WeakMap $recordedFailures = null;

    public function beginTransaction()
    {
        if ($this->transactionLevel() === 0) $this->attempt++;
        parent::beginTransaction();
    }

    public function transaction(Closure $callback, $attempts = 1)
    {
        try {
            return parent::transaction($callback, $attempts);
        } catch (Throwable $exception) {
            // Root commit errors are PDO calls rather than runQueryCallback calls.
            if (! isset($this->recordedFailures[$exception])) {
                $this->record(['sql' => 'transaction boundary',
                    'sqlstate' => PostgresOwnerFirstTransactions::sqlState($exception)]);
            }
            throw $exception;
        }
    }

    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        try {
            $result = parent::runQueryCallback($query, $bindings, $callback);
        } catch (Throwable $exception) {
            $this->recordedFailures ??= new \WeakMap;
            $this->recordedFailures[$exception] = true;
            $this->record(['sql' => $query, 'sqlstate' => PostgresOwnerFirstTransactions::sqlState($exception)]);
            throw $exception;
        }
        $identity = str_contains($query, 'txid_current()') ? (array) ($result[0] ?? []) : [];
        $this->record(['sql' => $query, 'sqlstate' => null, 'identity' => $identity]);
        if (self::$afterQuery !== null) (self::$afterQuery)($query, $this);

        return $result;
    }

    private function record(array $event): void
    {
        if (self::$sink !== null) {
            file_put_contents(self::$sink, json_encode($event + ['attempt' => $this->attempt,
                'depth' => $this->transactionLevel(), 'process' => getmypid()], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        }
    }
}
