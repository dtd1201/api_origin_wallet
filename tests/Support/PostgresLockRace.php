<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Wallet\Concurrency\PostgresOwnerFirstTransactions;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/** Two fresh worker sessions, a third observer and barriers outside the tested transactions. */
final class PostgresLockRace
{
    private string $directory;
    private ?int $worker = null;
    private string $phase = 'starting';
    private array $children = [];
    private array $exits = [];
    private ?Connection $observer = null;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir().'/patch268c-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
    }

    public function signal(string $name, mixed $value = true): void
    {
        $path = $this->directory.'/'.$name;
        $temporary = $path.'.tmp.'.getmypid();
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        if (file_put_contents($temporary, $json) !== strlen($json) || ! rename($temporary, $path)) {
            throw new RuntimeException('Could not publish race artifact: '.$name);
        }
    }

    public function artifactDirectory(): string
    {
        return $this->directory;
    }

    public function phase(string $phase): void
    {
        $this->phase = $phase;
        if ($this->worker !== null) {
            $event = ['worker' => $this->worker, 'pid' => getmypid(), 'phase' => $phase, 'at' => microtime(true)];
            $line = json_encode($event, JSON_THROW_ON_ERROR)."\n";
            if (file_put_contents($this->directory.'/phases-'.$this->worker, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
                throw new RuntimeException('Could not record worker phase.');
            }
        }
    }

    /** A named producer cannot satisfy a missing barrier after publishing its terminal result. */
    public function await(string $name, ?int $producer = null): mixed
    {
        $this->phase('await:'.$name);
        $until = microtime(true) + 15;
        while (! is_file($this->directory.'/'.$name)) {
            $this->pollExits();
            if ($producer !== null && is_file($this->directory.'/result-'.$producer)
                && ! is_file($this->directory.'/'.$name)) {
                throw new RuntimeException('Worker '.$producer.' finished before barrier '.$name.': '.
                    file_get_contents($this->directory.'/result-'.$producer));
            }
            if (microtime(true) > $until) throw new RuntimeException('Barrier timeout: '.$name);
            usleep(2000);
        }

        $this->phase('received:'.$name);
        return json_decode(file_get_contents($this->directory.'/'.$name), true, flags: JSON_THROW_ON_ERROR);
    }

    public function pauseAfterLock(string $table, string $ready = 'locked', string $release = 'continue'): void
    {
        $paused = false;
        LockRecordingPostgresConnection::$afterQuery = function (string $sql) use ($table, $ready, $release, &$paused): void {
            if (! $paused && str_contains($sql, '"'.$table.'"') && str_contains(strtolower($sql), 'for update')) {
                $paused = true;
                $this->signal($ready);
                $this->await($release);
            }
        };
    }

    public function waitForBlock(Connection $observer, int $waiter, int $blocker): array
    {
        $until = microtime(true) + 10;
        do {
            $this->pollExits();
            foreach (['pid-0', 'pid-1'] as $worker => $artifact) {
                if (is_file($this->directory.'/'.$artifact)
                    && in_array((int) file_get_contents($this->directory.'/'.$artifact), [$waiter, $blocker], true)
                    && is_file($this->directory.'/result-'.$worker)) {
                    throw new RuntimeException('Worker '.$worker.' finished before the expected lock wait: '.
                        file_get_contents($this->directory.'/result-'.$worker));
                }
            }
            $observer->selectOne('SELECT pg_stat_clear_snapshot()', [], false);
            $row = $observer->selectOne("SELECT pid, query, wait_event_type, wait_event,
                pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity
                WHERE pid = ? AND ? = ANY(pg_blocking_pids(pid)) AND wait_event_type = 'Lock'", [$waiter, $blocker], false);
            if ($row !== null) {
                $snapshot = (array) $row;
                $this->signal('observed-wait', $snapshot);
                return $snapshot;
            }
            usleep(2000);
        } while (microtime(true) < $until);
        throw new RuntimeException('Expected PostgreSQL lock wait was not observed.');
    }

    public function recordWire(string $operationKey): void
    {
        $line = json_encode(['key' => $operationKey,
            'pid' => getmypid(), 'depth' => DB::transactionLevel(),
            'pdo_transaction' => DB::connection()->getPdo()->inTransaction()], JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents($this->directory.'/wire', $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException('Could not record provider POST attempt.');
        }
    }

    public function run(Closure $left, Closure $right, Closure $observe): array
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            throw new RuntimeException('PostgreSQL races require pcntl and posix.');
        }
        Connection::resolverFor('pgsql', static fn ($pdo, $database, $prefix, $config) =>
            new LockRecordingPostgresConnection($pdo, $database, $prefix, $config));
        DB::purge();
        $failure = null;
        $payload = null;
        $locks = [];
        $cleanupFailures = [];
        try {
            foreach ([$left, $right] as $worker => $work) {
                $process = pcntl_fork();
                if ($process < 0) throw new RuntimeException('Could not fork worker.');
                if ($process === 0) {
                    $this->runWorker($worker, $work);
                }
                $this->children[$worker] = $process;
            }
            $pids = [$this->await('pid-0', 0), $this->await('pid-1', 1)];
            $observer = $this->observer = DB::connection();
            $observerPid = (int) $observer->selectOne('SELECT pg_backend_pid() AS pid', [], false)->pid;
            if (count(array_unique([...$pids, $observerPid])) !== 3) throw new RuntimeException('Race sessions are not independent.');
            $observe($this, $observer, $pids);
            $results = $this->collectResults();
            $logs = [];
            foreach ([0, 1] as $worker) {
                $lines = is_file($this->directory.'/sql-'.$worker)
                    ? file($this->directory.'/sql-'.$worker, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
                $logs[$worker] = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
            }

            $payload = ['workers' => $results, 'sql' => $logs, 'pids' => [...$pids, $observerPid],
                'wire' => is_file($this->directory.'/wire') ? array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->directory.'/wire', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [],
                'wait' => is_file($this->directory.'/observed-wait') ? $this->await('observed-wait') : null];
        } catch (Throwable $exception) {
            $failure = $exception;
            $locks = $this->lockDiagnostics();
        } finally {
            foreach ($this->children as $worker => $process) {
                if (isset($this->exits[$worker])) continue;
                try {
                    $until = microtime(true) + 2;
                    do {
                        $done = pcntl_waitpid($process, $status, WNOHANG);
                        if ($done !== 0) break;
                        usleep(2000);
                    } while (microtime(true) < $until);
                    $terminated = $done === 0;
                    if ($terminated) {
                        if (! posix_kill($process, SIGTERM)) {
                            throw new RuntimeException('Could not terminate worker '.$worker.': '.posix_get_last_error());
                        }
                        $done = pcntl_waitpid($process, $status);
                    }
                    $this->exits[$worker] = ($done === -1 ? ['waitpid_error' => pcntl_get_last_error()] : $this->exitStatus($status))
                        + ['terminated_by_parent' => $terminated];
                } catch (Throwable $exception) {
                    $failure ??= $exception;
                    $cleanupFailures[] = ['worker' => $worker] + $this->failure($exception);
                }
            }
            LockRecordingPostgresConnection::$sink = null;
            LockRecordingPostgresConnection::$afterQuery = null;
            try {
                DB::purge();
            } catch (Throwable $exception) {
                $failure ??= $exception;
                $cleanupFailures[] = $this->failure($exception);
            }
            Connection::resolverFor('pgsql', static fn ($pdo, $database, $prefix, $config) =>
                new PostgresConnection($pdo, $database, $prefix, $config));
        }
        $diagnostics = ['exits' => $this->exits, 'locks' => $locks, 'artifacts' => $this->directory,
            'cleanup_failures' => $cleanupFailures] + $this->workerDiagnostics();
        if ($failure !== null) {
            // Keep SQL, results, phases and lock evidence after timeout/exit failures.
            $diagnostics['failure'] = $this->failure($failure);
            $this->saveDiagnostics($diagnostics);
            throw new RuntimeException($failure->getMessage().' Race diagnostics: '.
                json_encode($diagnostics, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), 0, $failure);
        }
        $payload['exits'] = $this->exits;
        foreach ($this->exits as $worker => $exit) {
            if (($exit['exit_code'] ?? null) !== 0 || ($exit['terminated_by_parent'] ?? false)) {
                $this->saveDiagnostics($diagnostics);
                throw new RuntimeException('Worker '.$worker.' did not exit cleanly. Race diagnostics: '.json_encode($diagnostics));
            }
        }
        if (in_array('error', array_column($payload['workers'], 'status'), true)) {
            // Some race controls deliberately expect worker errors. Return them unchanged,
            // but retain their evidence so the caller can report an unexpected failure.
            $payload['artifacts'] = $this->directory;
            $this->saveDiagnostics($diagnostics);
            if (isset($diagnostics['report_write_error'])) {
                throw new RuntimeException('Race diagnostic publication failed: '.json_encode($diagnostics, JSON_INVALID_UTF8_SUBSTITUTE));
            }
        } else {
            $payload['artifacts'] = null;
            foreach (glob($this->directory.'/*') as $file) unlink($file);
            rmdir($this->directory);
        }

        return $payload;
    }

    private function runWorker(int $worker, Closure $work): never
    {
        $this->worker = $worker;
        $connection = null;
        LockRecordingPostgresConnection::$sink = $this->directory.'/sql-'.$worker;
        LockRecordingPostgresConnection::$afterQuery = null;
        try {
            $this->phase('connecting');
            DB::purge();
            $connection = DB::connection();
            $connection->statement("SET lock_timeout = '10s'");
            $connection->statement("SET statement_timeout = '12s'");
            $this->signal('pid-'.$worker, (int) $connection->selectOne('SELECT pg_backend_pid() AS pid', [], false)->pid);
            $this->phase('work');
            $result = ['status' => 'ok', 'value' => $work($this, $connection), 'phase' => $this->phase];
        } catch (Throwable $exception) {
            $result = $this->failure($exception);
        }
        $result += ['worker' => $worker, 'pid' => getmypid(), 'depth' => null, 'pdo_transaction' => null];
        try {
            if ($connection === null) throw new RuntimeException('Worker connection was not initialized.');
            $result['depth'] = $connection->transactionLevel();
            $pdo = $connection->getRawPdo();
            if (! $pdo instanceof PDO) throw new RuntimeException('Worker write PDO is unavailable.');
            $result['pdo_transaction'] = $pdo->inTransaction();
            DB::disconnect();
        } catch (Throwable $exception) {
            $result = $this->withReportingFailure($result, $exception);
        }
        try {
            $this->signal('result-'.$worker, $result);
        } catch (Throwable $exception) {
            // Serialization is also part of reporting: retain the original failure,
            // discard an unserializable return value and make one error-only attempt.
            $result = $this->withReportingFailure($result, $exception);
            unset($result['value']);
            try {
                $this->signal('result-'.$worker, $result);
            } catch (Throwable $publicationFailure) {
                fwrite(STDERR, 'Worker '.$worker.' result publication failed: '.$publicationFailure->getMessage()."\n");
                exit(70);
            }
        }
        exit(0); // Envelope status, not process exit zero, determines work success.
    }

    private function withReportingFailure(array $result, Throwable $exception): array
    {
        if ($result['status'] === 'ok') {
            return $this->failure($exception) + array_intersect_key($result, array_flip(['worker', 'pid', 'depth', 'pdo_transaction']));
        }
        $result['reporting_failures'][] = $this->failure($exception);
        return $result;
    }

    private function failure(Throwable $exception): array
    {
        $chain = [];
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            $chain[] = ['class' => $cause::class, 'message' => $cause->getMessage(),
                'file' => $cause->getFile(), 'line' => $cause->getLine(),
                'sqlstate' => PostgresOwnerFirstTransactions::sqlState($cause)];
        }
        return ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage(),
            'sqlstate' => PostgresOwnerFirstTransactions::sqlState($exception), 'phase' => $this->phase, 'chain' => $chain];
    }

    private function collectResults(): array
    {
        $results = [];
        $until = microtime(true) + 15;
        while (count($results) !== 2) {
            $this->pollExits();
            foreach ([0, 1] as $worker) {
                if (! isset($results[$worker]) && is_file($this->directory.'/result-'.$worker)) {
                    $results[$worker] = json_decode(file_get_contents($this->directory.'/result-'.$worker), true, flags: JSON_THROW_ON_ERROR);
                }
            }
            if (count($results) === 2) break;
            if (microtime(true) > $until) {
                throw new RuntimeException('Worker result timeout; received: '.json_encode($results));
            }
            usleep(2000);
        }
        return [$results[0], $results[1]];
    }

    private function pollExits(): void
    {
        if ($this->worker !== null) return; // Only the parent can reap these children.
        foreach ($this->children as $worker => $process) {
            if (! isset($this->exits[$worker])) {
                $done = pcntl_waitpid($process, $status, WNOHANG);
                if ($done === 0) continue;
                $this->exits[$worker] = $done === -1 ? ['waitpid_error' => pcntl_get_last_error()] : $this->exitStatus($status);
            }
            if (! is_file($this->directory.'/result-'.$worker)) {
                throw new RuntimeException('Worker '.$worker.' exited before publishing result: '.json_encode($this->exits[$worker]));
            }
            if (($this->exits[$worker]['exit_code'] ?? null) !== 0) {
                throw new RuntimeException('Worker '.$worker.' exited abnormally: '.json_encode($this->exits[$worker]));
            }
        }
    }

    private function exitStatus(int $status): array
    {
        return ['raw' => $status, 'exit_code' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : null,
            'signal' => pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : null];
    }

    private function saveDiagnostics(array &$diagnostics): void
    {
        try {
            $this->signal('diagnostics', $diagnostics);
        } catch (Throwable $exception) {
            // The original work/exit failure must remain the reported cause.
            $diagnostics['report_write_error'] = $exception->getMessage();
        }
    }

    private function workerDiagnostics(): array
    {
        $data = ['worker_results' => [], 'phases' => [], 'failed_queries' => []];
        foreach ([0, 1] as $worker) {
            foreach (['result' => 'worker_results', 'phases' => 'phases', 'sql' => 'failed_queries'] as $prefix => $key) {
                $path = $this->directory.'/'.$prefix.'-'.$worker;
                if (! is_file($path)) continue;
                try {
                    $raw = file_get_contents($path);
                    if ($prefix === 'result') {
                        $data[$key][$worker] = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                    } else {
                        $events = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                            array_filter(explode("\n", $raw), fn ($line) => $line !== ''));
                        $data[$key][$worker] = $prefix === 'sql'
                            ? array_values(array_filter($events, fn ($event) => ($event['sqlstate'] ?? null) !== null)) : $events;
                    }
                } catch (Throwable $exception) {
                    $data[$key][$worker] = ['read_error' => $exception->getMessage(), 'path' => $path];
                }
            }
        }
        return $data;
    }

    private function lockDiagnostics(): array
    {
        try {
            if ($this->observer === null) return [];
            $pids = [];
            foreach ([0, 1] as $worker) {
                if (is_file($this->directory.'/pid-'.$worker)) $pids[] = (int) file_get_contents($this->directory.'/pid-'.$worker);
            }
            if ($pids === []) return [];
            $marks = implode(',', array_fill(0, count($pids), '?'));
            $this->observer->selectOne('SELECT pg_stat_clear_snapshot()', [], false);
            return ['activity' => $this->observer->select('SELECT pid, state, query, wait_event_type, wait_event,
                pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE pid IN ('.$marks.')', $pids, false),
                'locks' => $this->observer->select('SELECT pid, locktype, mode, granted, relation::regclass::text AS relation,
                    transactionid::text FROM pg_locks WHERE pid IN ('.$marks.')', $pids, false)];
        } catch (Throwable $exception) {
            return ['diagnostic_error' => $exception->getMessage()];
        }
    }
}
