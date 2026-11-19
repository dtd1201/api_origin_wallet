<?php

namespace App\Services\Nium;

use App\Models\WebhookEvent;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use WeakMap;

/** Session exclusion across short transactions; a token fences every write, including nested services. */
final class NiumWebhookProcessingClaim
{
    private const LOCK_NAMESPACE = 1313428802;
    private static ?WeakMap $registered = null;
    private static array $active = [];
    private bool $checking = false;

    public function process(WebhookEvent $receipt, callable $work): WebhookEvent
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new RuntimeException('Nium webhook processing requires a top-level connection.');
        }
        if ($receipt->nium_va_evidence !== null) {
            throw new RuntimeException('VA receipts require their dedicated recovery processor.');
        }
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Nium webhook processing requires PostgreSQL session locks.');
        }
        $id = spl_object_id($connection);
        // PostgreSQL session locks are reentrant; do not admit a nested processor on this session.
        if (isset(self::$active[$id])) return $receipt->fresh('provider');
        $key = (int) hexdec(substr(hash('sha256', $connection->getDatabaseName().':'.$receipt->id), 0, 7));
        if (! $connection->selectOne('SELECT pg_try_advisory_lock(?, ?) AS acquired', [self::LOCK_NAMESPACE, $key], false)->acquired) {
            return $receipt->fresh('provider');
        }
        $pdo = $connection->getPdo();
        $token = (string) Str::uuid();
        try {
            self::$registered ??= new WeakMap;
            if (! isset(self::$registered[$connection])) {
                self::$registered[$connection] = true;
                $connection->beforeExecuting(static function ($sql, $bindings, Connection $connection): void {
                    $claim = self::$active[spl_object_id($connection)] ?? null;
                    if ($claim !== null && preg_match('/^\s*(insert|update|delete|with|alter|truncate)\b/i', $sql)) {
                        $claim['processor']->assertClaim($connection, $claim);
                    }
                });
                // Laravel may replay a failed SQL statement after reconnecting. A new session
                // has no advisory lock, so reconnect must fail closed for the entire processor.
                $connection->setReconnector(static function (Connection $connection): void {
                    if (isset(self::$active[spl_object_id($connection)])) {
                        throw new RuntimeException('Nium webhook processor lost its database session.');
                    }
                    $connection->setPdo(DB::reconnect($connection->getNameWithReadWriteType())->getRawPdo());
                });
            }
            self::$active[$id] = ['processor' => $this, 'event' => null, 'token' => $token, 'pdo' => $pdo, 'key' => $key];
            $event = $connection->transaction(function () use ($receipt, $token): WebhookEvent {
                $event = WebhookEvent::query()->lockForUpdate()->findOrFail($receipt->id);
                if ((string) $event->provider_id !== (string) $receipt->provider_id || $event->nium_va_evidence !== null) {
                    throw new RuntimeException('Nium webhook receipt binding changed.');
                }
                if ($event->processing_status !== 'processed') {
                    $event->update(['nium_processing_claim' => array_replace($event->nium_processing_claim ?? [], ['token' => $token,
                        'claimed_at' => now()->toISOString(),
                        'attempts' => ($event->nium_processing_claim['attempts'] ?? 0) + 1]),
                        'processing_status' => 'processing', 'error_message' => null]);
                }
                return $event;
            });
            if ($event->processing_status === 'processed') return $event->fresh('provider');
            self::$active[$id] = ['processor' => $this, 'event' => $event, 'token' => $token, 'pdo' => $pdo, 'key' => $key];
            try {
                $work($event);
                $this->finish($event, $event->processing_status === 'observed' ? 'observed' : 'processed', $event->error_message);
            } catch (Throwable $exception) {
                // A lost claim may neither acknowledge nor replace another processor's result.
                $this->finish($event, 'failed', 'Nium webhook processing failed; receipt retained for recovery.');
                throw $exception;
            }
        } finally {
            unset(self::$active[$id]);
            // Unlock only the original session; a dead process/session releases it automatically.
            if ($connection->getRawPdo() === $pdo) {
                try {
                    $statement = $pdo->prepare('SELECT pg_advisory_unlock(?, ?)');
                    $statement->execute([self::LOCK_NAMESPACE, $key]);
                } catch (Throwable) {
                    // Never keep a broken, possibly still locked session in a long-lived worker.
                    $connection->disconnect();
                }
            }
        }
        return $event->fresh('provider');
    }

    private function assertClaim(Connection $connection, array $claim): void
    {
        if ($this->checking) return;
        $this->checking = true;
        try {
            if ($connection->getRawPdo() !== $claim['pdo'] || ! $connection->selectOne(
                "SELECT EXISTS (SELECT 1 FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()
                    AND classid = ? AND objid = ? AND objsubid = 2 AND granted) AS owned",
                [self::LOCK_NAMESPACE, $claim['key']], false,
            )->owned) throw new RuntimeException('Nium webhook processor lost its session claim.');
            // Initial claim creation is fenced by the session lock before a token exists.
            if ($claim['event'] === null) return;
            $current = WebhookEvent::query()->useWritePdo()->whereKey($claim['event']->id)
                ->when($connection->transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->firstOrFail();
            if (($current->nium_processing_claim['token'] ?? null) !== $claim['token']
                || $current->processing_status !== 'processing'
                || (string) $current->provider_id !== (string) $claim['event']->provider_id) {
                throw new RuntimeException('Nium webhook processor lost its durable claim.');
            }
        } finally {
            $this->checking = false;
        }
    }

    private function finish(WebhookEvent $event, string $status, ?string $error): void
    {
        DB::transaction(function () use ($event, $status, $error): void {
            // The scoped SQL fence runs before this write too.
            WebhookEvent::query()->whereKey($event->id)->update(['processing_status' => $status,
                'processed_at' => now(), 'error_message' => $status === 'processed' ? null : $error]);
        });
    }
}
