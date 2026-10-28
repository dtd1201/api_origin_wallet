<?php

namespace App\Services\Nium;

use App\Jobs\Nium\EnrichNiumVirtualAccountDetails;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountDetail;
use App\Models\NiumVirtualAccountDetailEnrichment as Enrichment;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\NiumVirtualAccountRequest;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Durable read-only continuation of the safety ledger, never an assignment dispatcher. */
class NiumVirtualAccountDetailEnrichmentService
{
    public const QUEUE = 'nium-va-details';

    public const MAX_ATTEMPTS = 6;

    public const LEASE_SECONDS = 120;

    private const BACKOFF = [60, 300, 900, 3600, 14400, 14400];

    /** Called ONLY inside the ledger's terminal attachment transaction. */
    public function attached(NiumVirtualAccountRequest $request, NiumVirtualAccount $va): void
    {
        if (DB::transactionLevel() === 0 || $request->operation_state !== Operation::ASSIGNED
            || $request->operation()->value('nium_virtual_account_id') !== $va->id) {
            throw new RuntimeException('Enrichment requires a terminal ledger attachment transaction.');
        }
        try {
            // A savepoint isolates continuation-storage failure from assignment.
            // Recovery can reconstruct a missing intent from terminal ledger rows.
            $task = DB::transaction(fn () => Enrichment::query()->firstOrCreate([
                'operation_id' => $request->operation_id, 'nium_virtual_account_id' => $va->id,
            ], ['available_at' => now()]));
        } catch (Throwable) {
            return;
        }
        // The durable intent commits with attachment; the callback only publishes work.
        DB::afterCommit(fn () => $this->enqueue((int) $task->id));
    }

    /** Explicit database queue on the SAME DB: no sync driver or external broker gap. */
    public function enqueue(int $id): bool
    {
        try {
            $this->assertTesting();
            $this->assertOutsideTransaction();

            return DB::transaction(function () use ($id): bool {
                $task = Enrichment::query()->lockForUpdate()->findOrFail($id);
                if (in_array($task->state, ['complete', 'exhausted'], true)
                    || ($task->lease_until?->isFuture() ?? false)
                    || ($task->delivery_until?->isFuture() ?? false)) {
                    return false;
                }
                $delay = max(0, $task->available_at->getTimestamp() - now()->getTimestamp());
                $connection = DB::connection();
                $queue = new DatabaseQueue($connection, 'jobs', self::QUEUE, 90, false);
                $queue->setContainer(app());
                $job = new EnrichNiumVirtualAccountDetails($id);
                $job->beforeCommit();
                // Both inserts use this connection/transaction. Recovery cannot lose
                // a delivery between broker publication and updating durable state.
                $queue->later($delay, $job, '', self::QUEUE);
                $task->update(['delivery_until' => now()->addSeconds($delay + self::LEASE_SECONDS)]);

                return true;
            });
        } catch (Throwable) {
            // Queue publication can never escape the post-commit callback. The
            // committed pending task remains discoverable by the recovery command.
            return false;
        }
    }

    /** Bounded replay of lost callbacks, lost delivery and expired worker leases. */
    public function recover(int $limit = 100, ?int $operationId = null): int
    {
        $this->assertTesting();
        $this->assertOutsideTransaction();
        $limit = max(1, min(1000, $limit));
        // Backfill only committed enrolled attachments. GET's existing proof reader
        // still verifies the original evidence before any HTTP or banking write.
        $missing = Operation::query()->where('status', Operation::ASSIGNED)->whereNotNull('nium_virtual_account_id')
            ->when($operationId !== null, fn ($q) => $q->whereKey($operationId))
            ->whereIn('id', NiumVirtualAccountRequest::query()->select('operation_id')->where('operation_state', Operation::ASSIGNED))
            ->whereNotIn('id', Enrichment::query()->select('operation_id'))->orderBy('id')->limit($limit)->get();
        foreach ($missing as $operation) {
            DB::transaction(fn () => Enrichment::query()->firstOrCreate([
                'operation_id' => $operation->id, 'nium_virtual_account_id' => $operation->nium_virtual_account_id,
            ], ['available_at' => now()]));
        }
        $ids = Enrichment::query()->whereIn('state', ['pending', 'running'])
            ->when($operationId !== null, fn ($q) => $q->where('operation_id', $operationId))
            ->where('available_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
            ->where(fn ($q) => $q->whereNull('delivery_until')->orWhere('delivery_until', '<=', now()))
            ->orderBy('available_at')->orderBy('id')->limit($limit)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $count += (int) $this->enqueue((int) $id);
        }

        return $count;
    }

    public function run(int $id): void
    {
        $this->assertTesting();
        $this->assertOutsideTransaction();
        $task = DB::transaction(function () use ($id): ?Enrichment {
            $task = Enrichment::query()->lockForUpdate()->findOrFail($id);
            if (in_array($task->state, ['complete', 'exhausted'], true)
                || $task->available_at->isFuture() || ($task->lease_until?->isFuture() ?? false)) {
                return null;
            }
            if ($task->attempts >= self::MAX_ATTEMPTS) {
                $task->update(['state' => 'exhausted', 'claim_token' => null, 'lease_until' => null,
                    'delivery_until' => null, 'last_result' => 'attempts_exhausted']);

                return null;
            }
            $task->update(['state' => 'running', 'attempts' => $task->attempts + 1,
                'claim_token' => (string) Str::uuid(), 'lease_until' => now()->addSeconds(self::LEASE_SECONDS),
                'delivery_until' => null]);

            return $task;
        });
        if ($task === null) {
            return;
        }
        $delay = self::BACKOFF[$task->attempts - 1];
        $result = 'read_failed';
        $complete = false;
        try {
            $va = NiumVirtualAccount::query()->findOrFail($task->nium_virtual_account_id);
            $detail = app(NiumVirtualAccountDetailService::class)->syncEnrolled(
                $va, $task->operation_id,
                fn () => $this->assertClaim($task),
                fn (array $target) => $this->permitGet($task, $target),
            );
            $complete = $this->complete($detail);
            $result = $complete ? 'enriched' : 'incomplete';
        } catch (NiumVirtualAccountDetailRetry $exception) {
            $delay = max($delay, $exception->delay);
            $result = 'read_retryable';
            if ($exception->rateKey !== null) {
                DB::transaction(function () use ($exception): void {
                    $row = DB::table('nium_va_detail_rate_limits')->where('target_key', $exception->rateKey)->lockForUpdate()->first();
                    $until = now()->addSeconds($exception->delay);
                    if ($row !== null && ($row->blocked_until === null
                        || Carbon::parse($row->blocked_until)->lessThan($until))) {
                        DB::table('nium_va_detail_rate_limits')->where('target_key', $exception->rateKey)->update(['blocked_until' => $until]);
                    }
                });
            }
        } catch (Throwable) {
            // No exception message, response, bank facts or credentials enter job logs.
            // Original assignment and evidence are never mutated by this continuation.
        }
        $owned = DB::transaction(function () use ($task, $complete, $result, $delay): bool {
            $current = Enrichment::query()->lockForUpdate()->findOrFail($task->id);
            if ($current->claim_token !== $task->claim_token) {
                return false;
            }
            $current->update([
                'state' => $complete ? 'complete' : ($current->attempts >= self::MAX_ATTEMPTS ? 'exhausted' : 'pending'),
                'available_at' => now()->addSeconds($delay), 'claim_token' => null,
                'lease_until' => null, 'delivery_until' => null, 'last_result' => $result,
            ]);

            return true;
        });
        if ($owned && ! $complete) {
            $this->enqueue($id);
        }
    }

    /** Also checked under detail-persistence locks, fencing an expired worker. */
    private function assertClaim(Enrichment $task): void
    {
        $current = Enrichment::query()->lockForUpdate()->findOrFail($task->id);
        if ($current->state !== 'running' || $current->claim_token !== $task->claim_token
            || ! ($current->lease_until?->isFuture() ?? false)
            || $current->operation_id !== $task->operation_id
            || $current->nium_virtual_account_id !== $task->nium_virtual_account_id) {
            throw new RuntimeException('VA detail worker lost its durable claim.');
        }
    }

    /** Fixed one-minute budget per frozen origin/client; each page consumes a slot. */
    private function permitGet(Enrichment $task, array $target): void
    {
        $delay = DB::transaction(function () use ($task, $target): int {
            $this->assertClaim($task);
            $key = hash('sha256', $target['provider_origin'].'|'.$target['client_id']);
            DB::table('nium_va_detail_rate_limits')->insertOrIgnore([
                'target_key' => $key, 'window_started_at' => now(), 'requests' => 0,
            ]);
            $row = DB::table('nium_va_detail_rate_limits')->where('target_key', $key)->lockForUpdate()->first();
            $start = Carbon::parse($row->window_started_at);
            $blocked = $row->blocked_until === null ? null : Carbon::parse($row->blocked_until);
            if ($blocked?->isFuture()) {
                return max(1, $blocked->getTimestamp() - now()->getTimestamp());
            }
            $requests = $start->copy()->addMinute()->isFuture() ? $row->requests : 0;
            if ($requests >= max(1, (int) config('nium_va_enrichment.requests_per_minute', 30))) {
                return max(1, $start->addMinute()->getTimestamp() - now()->getTimestamp());
            }
            DB::table('nium_va_detail_rate_limits')->where('target_key', $key)->update([
                'window_started_at' => $requests === 0 ? now() : $row->window_started_at, 'requests' => $requests + 1,
            ]);

            return 0;
        });
        if ($delay > 0) {
            throw new NiumVirtualAccountDetailRetry($delay);
        }
    }

    private function complete(?NiumVirtualAccountDetail $detail): bool
    {
        // Local recovery threshold, not a claim that optional provider fields are mandatory.
        return $detail !== null && filled($detail->account_name)
            && (filled($detail->provider_bank_name) || filled($detail->getRawOriginal('bank_name')))
            && filled($detail->routing_code_type) && filled($detail->routing_code_value);
    }

    private function assertTesting(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Automatic VA enrichment remains testing-only pending reviewed rollout.');
        }
    }

    private function assertOutsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('VA enrichment workers require a committed attachment.');
        }
    }
}
