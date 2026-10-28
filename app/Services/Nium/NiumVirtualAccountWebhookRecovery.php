<?php

namespace App\Services\Nium;

use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** One processor per protected receipt across independent ledger transactions. */
final class NiumVirtualAccountWebhookRecovery
{
    private const LOCK_NAMESPACE = 1313428801;

    private static array $active = [];

    public function process(WebhookEvent $receipt, callable $process): WebhookEvent
    {
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('VA webhook recovery requires a top-level connection.');
        }
        $key = $this->key($receipt);
        if (isset(self::$active[$key])) {
            return $receipt->fresh('provider');
        }
        $driver = DB::getDriverName();
        $file = null;
        if ($driver === 'pgsql') {
            $locked = DB::selectOne('SELECT pg_try_advisory_lock(?, ?) AS acquired', [self::LOCK_NAMESPACE, $key], false)->acquired;
        } elseif ($driver === 'sqlite') {
            // Local SQLite test/development connections only; PostgreSQL owns distributed exclusion.
            $file = fopen(sys_get_temp_dir().'/nium-va-receipt-'.$key.'.lock', 'c');
            $locked = $file !== false && flock($file, LOCK_EX | LOCK_NB);
        } else {
            throw new RuntimeException('VA webhook recovery requires PostgreSQL or SQLite.');
        }
        if (! $locked) {
            if (is_resource($file)) {
                fclose($file);
            }

            return $receipt->fresh('provider');
        }
        try {
            $token = (string) Str::uuid();
            self::$active[$key] = $token;
            $event = DB::transaction(function () use ($receipt, $token): WebhookEvent {
                $event = WebhookEvent::query()->lockForUpdate()->findOrFail($receipt->id);
                if ($event->nium_va_evidence === null || $event->processing_status === 'processed') {
                    return $event;
                }
                $metadata = $event->nium_va_recovery ?? [];
                $event->update([
                    'nium_va_recovery' => array_replace($metadata, [
                        'token' => $token, 'attempts' => ($metadata['attempts'] ?? 0) + 1,
                        'claimed_at' => now()->toISOString(),
                    ]),
                    'processing_status' => 'processing', 'error_message' => null,
                ]);

                return $event;
            });
            if ($event->nium_va_evidence === null || $event->processing_status === 'processed') {
                return $event->fresh('provider');
            }
            try {
                $process($event);
                $this->finish($event, $event->processing_status === 'observed' ? 'observed' : 'processed', $event->error_message);
            } catch (Throwable $exception) {
                // A lost session/claim cannot acknowledge, or overwrite, another worker's result.
                $this->finish($event, 'failed', 'Nium VA webhook recovery failed; original evidence retained.');
                throw new RuntimeException('Nium VA webhook recovery failed; original evidence retained.', previous: $exception);
            }

            return $event->fresh('provider');
        } finally {
            unset(self::$active[$key]);
            if ($driver === 'pgsql') {
                DB::select('SELECT pg_advisory_unlock(?, ?)', [self::LOCK_NAMESPACE, $key], false);
            } elseif (is_resource($file)) {
                flock($file, LOCK_UN);
                fclose($file);
            }
        }
    }

    /** Called after account/scope/request/operation locks, before authoritative writes. */
    public function assertClaim(WebhookEvent $expected): WebhookEvent
    {
        $key = $this->key($expected);
        $token = $expected->nium_va_recovery['token'] ?? null;
        if ($token === null || (self::$active[$key] ?? null) !== $token) {
            throw new RuntimeException('VA webhook processor has no active claim.');
        }
        if (DB::getDriverName() === 'pgsql' && ! DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()
                AND classid = ? AND objid = ? AND objsubid = 2 AND granted) AS owned",
            [self::LOCK_NAMESPACE, $key],
        )->owned) {
            throw new RuntimeException('VA webhook processor lost its database session lock.');
        }
        $event = WebhookEvent::query()->lockForUpdate()->findOrFail($expected->id);
        if (($event->nium_va_recovery['token'] ?? null) !== $token || $event->processing_status !== 'processing') {
            throw new RuntimeException('VA webhook processor lost its durable claim.');
        }

        return $event;
    }

    private function finish(WebhookEvent $event, string $status, ?string $error): void
    {
        DB::transaction(function () use ($event, $status, $error): void {
            $this->assertClaim($event)->update([
                'processing_status' => $status, 'processed_at' => now(),
                'error_message' => $status === 'processed' ? null : $error,
            ]);
        });
    }

    private function key(WebhookEvent $event): int
    {
        // Hash collisions only serialize unrelated receipts; they cannot grant a claim.
        return (int) hexdec(substr(hash('sha256', DB::connection()->getDatabaseName().':'.$event->id), 0, 7));
    }
}
