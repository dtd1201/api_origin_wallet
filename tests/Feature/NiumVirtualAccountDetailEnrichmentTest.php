<?php

namespace Tests\Feature;

use App\Jobs\Nium\EnrichNiumVirtualAccountDetails;
use App\Models\NiumApiExchangeEvidence;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountDetail;
use App\Models\NiumVirtualAccountDetailEnrichment as Enrichment;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\NiumVirtualAccountRequest;
use App\Services\Nium\NiumVirtualAccountDetailEnrichmentService as Service;
use App\Services\Nium\NiumVirtualAccountDetailService;
use App\Services\Nium\NiumVirtualAccountSafetyLedger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesNiumVirtualAccountLedgerFixtures;
use Tests\TestCase;

class NiumVirtualAccountDetailEnrichmentTest extends TestCase
{
    use CreatesNiumVirtualAccountLedgerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.nium_enrichment_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ], 'queue.default' => 'sync']);
        DB::setDefaultConnection('nium_enrichment_test');
        $this->artisan('migrate', ['--database' => 'nium_enrichment_test', '--force' => true])->assertSuccessful();
        $this->configureLedgerTarget();
        config([
            'services.nium.payment_ids_endpoint' => '/api/v2/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentIds',
            'services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'synthetic-enrichment-key'],
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'synthetic-partner-key',
        ]);
        Http::preventStrayRequests();
        $this->freezeTime();
    }

    protected function tearDown(): void
    {
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        DB::purge('nium_enrichment_test');
        parent::tearDown();
    }

    public function test_intent_is_transactional_and_get_runs_only_after_terminal_attachment_commit(): void
    {
        $request = $this->prepared();
        $observed = false;
        app('events')->listen(TransactionCommitting::class, function () use (&$observed): void {
            if (! $observed && DB::transactionLevel() === 1 && Enrichment::query()->exists()) {
                $observed = true;
                $this->assertSame(0, DB::table('jobs')->count());
                Http::assertNothingSent();
            }
        });
        [$va, $task] = $this->resolve($request);
        $this->assertTrue($observed);
        $this->assertSame(1, DB::table('jobs')->where('queue', Service::QUEUE)->count());
        Http::assertNothingSent();
        $this->fake(function () use ($va, $request) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertSame(Operation::ASSIGNED, $request->fresh()->operation_state);
            $this->assertSame($va->id, $request->operation()->value('nium_virtual_account_id'));

            return Http::response($this->payload($va));
        });
        $queue = new DatabaseQueue(DB::connection(), 'jobs', Service::QUEUE, 90, false);
        $queue->setContainer(app());
        $job = $queue->pop(Service::QUEUE);
        $this->assertNotNull($job);
        $job->fire();
        $job->delete();
        $this->assertSame('complete', $task->fresh()->state);
        Http::assertSentCount(1);
    }

    public function test_attachment_rollback_removes_intent_and_never_publishes_work(): void
    {
        $request = $this->prepared();
        $fail = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$fail): void {
            if ($fail && str_starts_with($query, 'update "nium_va_assignment_scopes"') && Enrichment::query()->exists()) {
                throw new RuntimeException('Synthetic attachment rollback');
            }
        });
        try {
            $this->resolve($request);
            $this->fail('Assignment commit should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic attachment rollback', $exception->getMessage());
        } finally {
            $fail = false;
        }
        $this->assertSame(0, Enrichment::query()->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, NiumVirtualAccount::query()->count());
        Http::assertNothingSent();
    }

    public function test_definitive_post_failure_does_not_create_an_intent(): void
    {
        $request = $this->prepared();
        $evidence = $this->ledgerResponseEvidence($request, ['errors' => [['code' => 'invalid_input', 'field' => 'currencyCode']]], 400);
        app(NiumVirtualAccountSafetyLedger::class)->resolveFromPostEvidence($request, 1, $evidence, attachVirtualAccount: true);
        $this->assertSame(Operation::FAILED, $request->fresh()->operation_state);
        $this->assertSame(0, app(Service::class)->recover());
        $this->assertSame(0, Enrichment::query()->count());
        $this->assertSame(0, DB::table('jobs')->count());
        Http::assertNothingSent();
    }

    #[DataProvider('unresolved')]
    public function test_unresolved_assignment_never_creates_enrichment_work(string $state): void
    {
        $request = $this->prepared();
        app(NiumVirtualAccountSafetyLedger::class)->transition($request, 1, $state);
        $this->assertSame(0, app(Service::class)->recover());
        $this->assertSame(0, Enrichment::query()->count());
        $this->assertSame(0, DB::table('jobs')->count());
        Http::assertNothingSent();
    }

    public static function unresolved(): array
    {
        return [[Operation::UNKNOWN], [Operation::PENDING], [Operation::MANUAL_REVIEW]];
    }

    #[DataProvider('unavailable')]
    public function test_get_unavailability_remains_retryable_and_preserves_assignment(string $failure): void
    {
        [$va, $task, $request] = $this->resolve($this->prepared());
        $before = $request->fresh()->getAttributes();
        $parent = $va->fresh()->getAttributes();
        $this->fake(fn () => $failure === 'timeout' ? Http::failedConnection('Synthetic timeout')
            : ($failure === 'not_listed' ? Http::response(['content' => []]) : Http::response([], 404)));
        (new EnrichNiumVirtualAccountDetails($task->id))->handle(app(Service::class));
        $this->assertSame('pending', $task->fresh()->state);
        $this->assertSame(1, $task->fresh()->attempts);
        $this->assertTrue($task->fresh()->available_at->isFuture());
        $this->assertNotNull($task->fresh()->delivery_until);
        $this->assertSame($before, $request->fresh()->getAttributes());
        $this->assertSame($parent, $va->fresh()->getAttributes());
        $this->assertSame(0, NiumVirtualAccountDetail::query()->count());
        $this->fake(fn () => Http::response($this->payload($va)));
        $this->travel(61)->seconds();
        app(Service::class)->run($task->id);
        $this->assertSame('complete', $task->fresh()->state);
        $this->assertSame(2, $task->fresh()->attempts);
    }

    public static function unavailable(): array
    {
        return [['404'], ['timeout'], ['not_listed']];
    }

    public function test_partial_facts_are_preserved_and_later_read_fills_missing_facts(): void
    {
        [$va, $task] = $this->resolve($this->prepared());
        $this->fake(fn () => Http::response(['content' => [[
            'uniquePaymentId' => $va->provider_payment_id, 'accountName' => 'Synthetic holder',
        ]]]));
        app(Service::class)->run($task->id);
        $this->assertSame('pending', $task->fresh()->state);
        $this->assertSame('incomplete', $task->fresh()->last_result);
        $this->assertSame('Synthetic holder', $va->detail()->firstOrFail()->account_name);
        $this->travel(61)->seconds();
        $this->fake(fn () => Http::response($this->payload($va)));
        app(Service::class)->run($task->id);
        $detail = $va->detail()->firstOrFail();
        $this->assertSame('complete', $task->fresh()->state);
        $this->assertSame(2, $detail->enrichment_version);
        $this->assertSame('ABA (Wire)', $detail->routing_code_type_2);
        $this->assertSame('GLOBAL', $detail->provider_account_type);
        $this->assertSame('LOCAL', $va->fresh()->account_type);
    }

    public function test_incomplete_pair_and_conflicting_facts_never_overwrite_verified_banking_details(): void
    {
        [$va, $task] = $this->resolve($this->prepared());
        $detail = NiumVirtualAccountDetail::query()->create(['nium_virtual_account_id' => $va->id,
            'account_name' => 'Verified holder', 'routing_code_type' => 'SWIFT', 'routing_code_value' => 'VERIFIEDXXX']);
        $before = $detail->fresh()->getAttributes();
        $this->fake(fn () => Http::response($this->payload($va, ['routingCodeValue2' => null])));
        app(Service::class)->run($task->id);
        $this->assertSame($before, $detail->fresh()->getAttributes());
        $this->travel(61)->seconds();
        $this->fake(fn () => Http::response($this->payload($va)));
        app(Service::class)->run($task->id);
        $this->assertSame($before, $detail->fresh()->getAttributes());
        $this->assertSame('pending', $task->fresh()->state);
        $this->assertSame('assigned', $va->fresh()->status);
    }

    public function test_overlapping_delivery_is_a_noop_and_completed_delivery_is_idempotent(): void
    {
        [$va, $task] = $this->resolve($this->prepared());
        $this->fake(function () use ($va, $task) {
            $this->assertSame('running', $task->fresh()->state);
            app(Service::class)->run($task->id);
            $this->assertSame(1, $task->fresh()->attempts);

            return Http::response($this->payload($va));
        });
        $job = new EnrichNiumVirtualAccountDetails($task->id);
        $job->handle(app(Service::class));
        $before = $va->detail()->firstOrFail()->getAttributes();
        $job->handle(app(Service::class));
        $this->assertSame($before, $va->detail()->firstOrFail()->getAttributes());
        $this->assertSame(1, $task->fresh()->attempts);
        Http::assertSentCount(1);
    }

    public function test_lost_callback_or_queue_publish_is_recovered_without_assignment_post(): void
    {
        $failQueue = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$failQueue): void {
            if ($failQueue && str_starts_with($query, 'insert into "jobs"')) {
                throw new RuntimeException('Synthetic queue publication failure');
            }
        });
        [$va, $task, $request] = $this->resolve($this->prepared());
        $failQueue = false;
        $this->assertSame(Operation::ASSIGNED, $request->fresh()->operation_state);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->artisan('nium:recover-va-details', ['--limit' => 1])->assertSuccessful();
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, app(Service::class)->recover());
        $this->fake(fn () => Http::response($this->payload($va)));
        app(Service::class)->run($task->id);
        $this->assertSame('complete', $task->fresh()->state);
    }

    public function test_intent_storage_failure_cannot_rollback_assignment_and_is_backfilled(): void
    {
        $failIntent = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$failIntent): void {
            if ($failIntent && str_starts_with($query, 'insert into "nium_va_detail_enrichments"')) {
                throw new RuntimeException('Synthetic continuation storage failure');
            }
        });
        $request = $this->prepared();
        $evidence = $this->ledgerResponseEvidence($request);
        app(NiumVirtualAccountSafetyLedger::class)->resolveFromPostEvidence($request, 1, $evidence, attachVirtualAccount: true);
        $failIntent = false;
        $this->assertSame(Operation::ASSIGNED, $request->fresh()->operation_state);
        $this->assertSame(0, Enrichment::query()->count());
        $this->assertSame(1, app(Service::class)->recover());
        $this->assertSame($request->operation_id, Enrichment::query()->sole()->operation_id);
        Http::assertNothingSent();
    }

    public function test_worker_crash_consumes_a_durable_attempt_and_expired_lease_is_recoverable(): void
    {
        [$va, $task] = $this->resolve($this->prepared());
        $task->update(['state' => 'running', 'attempts' => 1, 'claim_token' => (string) Str::uuid(),
            'lease_until' => now()->addSeconds(Service::LEASE_SECONDS), 'delivery_until' => null]);
        DB::table('jobs')->delete();
        $this->assertSame(0, app(Service::class)->recover());
        $this->travel(Service::LEASE_SECONDS + 1)->seconds();
        $this->assertSame(1, app(Service::class)->recover());
        $this->fake(fn () => Http::response($this->payload($va)));
        app(Service::class)->run($task->id);
        $this->assertSame(2, $task->fresh()->attempts);
        $this->assertSame('complete', $task->fresh()->state);
    }

    public function test_expired_worker_is_fenced_from_saving_after_recovery_wins(): void
    {
        [$va, $task] = $this->resolve($this->prepared());
        $this->fake(function () use ($va, $task) {
            $this->travel(Service::LEASE_SECONDS + 1)->seconds();
            $this->fake(fn () => Http::response($this->payload($va, ['accountName' => 'Recovery winner'])));
            app(Service::class)->run($task->id);

            return Http::response($this->payload($va, ['accountName' => 'Expired worker']));
        });
        app(Service::class)->run($task->id);
        $this->assertSame('Recovery winner', $va->detail()->firstOrFail()->account_name);
        $this->assertSame(1, $va->detail()->firstOrFail()->enrichment_version);
        $this->assertSame('complete', $task->fresh()->state);
        $this->assertSame(2, $task->fresh()->attempts);
    }

    public function test_retry_budget_cannot_be_reset_by_recovery_or_duplicate_delivery(): void
    {
        [, $task, $request] = $this->resolve($this->prepared());
        $this->fake(fn () => Http::response([], 404));
        $delays = [60, 300, 900, 3600, 14400, 14400];
        for ($attempt = 1; $attempt <= Service::MAX_ATTEMPTS; $attempt++) {
            $started = now()->getTimestamp();
            app(Service::class)->run($task->id);
            $this->assertSame($attempt, $task->fresh()->attempts);
            $this->assertSame($delays[$attempt - 1], $task->fresh()->available_at->getTimestamp() - $started);
            $this->travelTo($task->fresh()->available_at->copy()->addSecond());
        }
        $this->assertSame('exhausted', $task->fresh()->state);
        $this->assertSame(0, app(Service::class)->recover());
        app(Service::class)->run($task->id);
        $this->assertSame(Service::MAX_ATTEMPTS, $task->fresh()->attempts);
        $this->assertSame(Operation::ASSIGNED, $request->fresh()->operation_state);
        Http::assertSentCount(Service::MAX_ATTEMPTS);
    }

    public function test_arbitrary_enrichment_failure_cannot_change_terminal_assignment_or_leak_its_message(): void
    {
        [$va, $task, $request] = $this->resolve($this->prepared());
        $assignment = $request->fresh()->getAttributes();
        $operation = $request->operation()->firstOrFail()->getAttributes();
        $parent = $va->fresh()->getAttributes();
        $this->mock(NiumVirtualAccountDetailService::class)->shouldReceive('syncEnrolled')->once()
            ->andThrow(new RuntimeException('Synthetic private credentials and banking evidence'));
        app(Service::class)->run($task->id);
        $this->assertSame($assignment, $request->fresh()->getAttributes());
        $this->assertSame($operation, $request->operation()->firstOrFail()->getAttributes());
        $this->assertSame($parent, $va->fresh()->getAttributes());
        $this->assertSame('pending', $task->fresh()->state);
        $this->assertSame('read_failed', $task->fresh()->last_result);
        $this->assertStringNotContainsString('private credentials', json_encode($task->fresh()->toArray()));
        Http::assertNothingSent();
    }

    #[DataProvider('changedIdentity')]
    public function test_automatic_worker_never_substitutes_current_identity(string $field): void
    {
        [$va, $task, $request] = $this->resolve($this->prepared());
        if ($field === 'client') {
            config(['services.nium.client_id' => 'substitute-client']);
        } else {
            $request->operation->providerAccount->update([$field => 'substitute-target']);
        }
        $this->fake(fn () => Http::response($this->payload($va)));
        app(Service::class)->run($task->id);
        Http::assertNothingSent();
        $this->assertSame('pending', $task->fresh()->state);
        $this->assertSame(0, NiumVirtualAccountDetail::query()->count());
        $this->assertSame(Operation::ASSIGNED, $request->fresh()->operation_state);
    }

    public static function changedIdentity(): array
    {
        return [['client'], ['external_customer_id'], ['external_account_id']];
    }

    public function test_task_cannot_borrow_another_enrolled_operations_terminal_proof(): void
    {
        [$va, $task] = $this->resolve($this->prepared());
        $other = $this->prepared();
        $task->update(['operation_id' => $other->operation_id]);
        $this->fake(fn () => Http::response($this->payload($va)));
        app(Service::class)->run($task->id);
        Http::assertNothingSent();
        $this->assertSame(0, NiumVirtualAccountDetail::query()->count());
        $this->assertSame('pending', $task->fresh()->state);
    }

    public function test_database_rejects_mutation_of_original_terminal_evidence(): void
    {
        [$va, $task, $request] = $this->resolve($this->prepared());
        $evidence = $request->operation->apiRequestLog->niumExchangeEvidence;
        $stored = $evidence->fresh()->getAttributes();
        $assignment = $request->fresh()->getAttributes();
        $continuation = $task->fresh()->getAttributes();

        try {
            // Keep the rejected write in its own transaction/savepoint so a
            // PostgreSQL constraint error cannot poison subsequent assertions.
            DB::transaction(fn () => $evidence->update([
                'assignment_target' => 'https://untrusted.test/wrong-assignment',
            ]));
            $this->fail('Protected terminal POST evidence must remain immutable.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'nium_va_evidence_nium_api_exchange_evidence_update', $exception->getMessage(),
            );
        }

        $this->assertSame($stored, $evidence->fresh()->getAttributes());
        $this->assertSame($assignment, $request->fresh()->getAttributes());
        $this->assertSame($continuation, $task->fresh()->getAttributes());
        $proof = app(NiumVirtualAccountSafetyLedger::class)->verifiedAssignmentResponse($request->fresh());
        $this->assertSame($va->provider_payment_id, $proof['uniquePaymentId']);
        Http::assertNothingSent();
    }

    public function test_automatic_worker_fails_closed_when_original_terminal_evidence_cannot_be_read(): void
    {
        [$va, $task, $request] = $this->resolve($this->prepared());
        $evidence = $request->operation->apiRequestLog->niumExchangeEvidence;
        $stored = $evidence->fresh()->getAttributes();
        $assignment = $request->fresh()->getAttributes();
        $operation = $request->operation()->firstOrFail()->getAttributes();
        $parent = $va->fresh()->getAttributes();
        $unavailable = true;
        $blockedReads = 0;

        // A legally resolved assignment remains untouched. Simulate unavailable
        // authoritative proof at the read boundary, without deleting evidence,
        // altering protected rows or replacing the production proof validator.
        NiumApiExchangeEvidence::retrieved(function (NiumApiExchangeEvidence $candidate) use ($evidence, &$unavailable, &$blockedReads): void {
            if ($unavailable && $candidate->getKey() === $evidence->getKey()) {
                $blockedReads++;
                throw (new ModelNotFoundException)->setModel(NiumApiExchangeEvidence::class, [$evidence->getKey()]);
            }
        });
        $this->fake(fn () => Http::response($this->payload($va)));
        try {
            app(Service::class)->run($task->id);
        } finally {
            $unavailable = false;
        }

        $this->assertSame(1, $blockedReads, 'The worker must read original terminal evidence before GET.');
        // This forbids both enrichment GET and assignment POST on failure.
        Http::assertNothingSent();
        $this->assertSame('pending', $task->fresh()->state);
        $this->assertSame('read_failed', $task->fresh()->last_result);
        $this->assertSame(1, $task->fresh()->attempts);
        $this->assertTrue($task->fresh()->available_at->isFuture());
        $this->assertNotNull($task->fresh()->delivery_until);
        $this->assertSame($stored, $evidence->fresh()->getAttributes());
        $this->assertSame($assignment, $request->fresh()->getAttributes());
        $this->assertSame($operation, $request->operation()->firstOrFail()->getAttributes());
        $this->assertSame($parent, $va->fresh()->getAttributes());
        $this->assertSame(0, $va->detail()->count());

        // Once proof is readable, the same bounded GET-only continuation can
        // revalidate it and complete without reconstructing assignment evidence.
        $this->travelTo($task->fresh()->available_at->copy()->addSecond());
        app(Service::class)->run($task->id);
        $this->assertSame('complete', $task->fresh()->state);
        $this->assertSame(2, $task->fresh()->attempts);
        $this->assertSame($stored, $evidence->fresh()->getAttributes());
        $this->assertSame($assignment, $request->fresh()->getAttributes());
        $this->assertSame($operation, $request->operation()->firstOrFail()->getAttributes());
        $this->assertSame($parent, $va->fresh()->getAttributes());
        $this->assertSame(1, $va->detail()->firstOrFail()->enrichment_version);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $outgoing): bool => $outgoing->method() !== 'GET');
    }

    public function test_worker_rejects_an_open_transaction_before_consuming_an_attempt(): void
    {
        [, $task] = $this->resolve($this->prepared());
        try {
            DB::transaction(fn () => app(Service::class)->run($task->id));
            $this->fail('An open transaction must not run enrichment.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('committed attachment', $exception->getMessage());
        }
        $this->assertSame(0, $task->fresh()->attempts);
        Http::assertNothingSent();
    }

    public function test_enrichment_and_recovery_remain_inactive_outside_testing(): void
    {
        [, $task] = $this->resolve($this->prepared());
        app()->instance('env', 'production');
        try {
            $this->assertFalse(app(Service::class)->enqueue($task->id));
            try {
                app(Service::class)->run($task->id);
                $this->fail('Automatic worker must remain testing-only.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('testing-only', $exception->getMessage());
            }
            try {
                app(Service::class)->recover();
                $this->fail('Recovery must remain testing-only.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('testing-only', $exception->getMessage());
            }
        } finally {
            app()->instance('env', 'testing');
        }
        $this->assertSame(0, $task->fresh()->attempts);
        Http::assertNothingSent();
    }

    public function test_legacy_sync_remains_direct_read_only_and_does_not_create_tasks(): void
    {
        $account = $this->ledgerAccount();
        $va = NiumVirtualAccount::query()->create(['user_provider_account_id' => $account->id,
            'provider_payment_id' => 'VA-LEGACY-ENRICHMENT', 'currency' => 'USD', 'status' => 'assigned']);
        $this->fake(fn () => Http::response(['paymentIds' => [[
            'paymentId' => $va->provider_payment_id, 'accountName' => 'Legacy holder',
        ]]]));
        $detail = app(NiumVirtualAccountDetailService::class)->sync($va);
        $this->assertSame('Legacy holder', $detail->account_name);
        $this->assertSame(0, app(Service::class)->recover());
        $this->assertSame(0, Enrichment::query()->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_provider_429_retry_after_blocks_other_enrichments_for_the_same_frozen_client(): void
    {
        [$va, $first] = $this->resolve($this->prepared());
        [, $second] = $this->resolve($this->prepared());
        $this->fake(fn () => Http::response([], 429, ['Retry-After' => '600']));
        app(Service::class)->run($first->id);
        $this->assertGreaterThanOrEqual(now()->addSeconds(600)->getTimestamp(), $first->fresh()->available_at->getTimestamp());
        app(Service::class)->run($second->id);
        Http::assertSentCount(1);
        $this->assertSame('pending', $second->fresh()->state);
        $this->assertSame('assigned', $va->fresh()->status);
    }

    public function test_each_page_obeys_the_shared_client_rate_budget(): void
    {
        config(['nium_va_enrichment.requests_per_minute' => 1]);
        [$va, $task] = $this->resolve($this->prepared());
        $this->fake(fn () => Http::response(['content' => [], 'totalPages' => 2]));
        app(Service::class)->run($task->id);
        Http::assertSentCount(1);
        $this->assertSame('pending', $task->fresh()->state);
        $this->assertSame(0, $va->detail()->count());
    }

    public function test_job_payload_and_continuation_state_exclude_credentials_and_banking_evidence(): void
    {
        [$va, $task] = $this->resolve($this->prepared());
        $payload = DB::table('jobs')->sole()->payload;
        foreach (['synthetic-enrichment-key', $va->provider_payment_id, 'routingCode', 'snapshot', 'customerHashId'] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
            $this->assertStringNotContainsString($secret, json_encode($task->toArray()));
        }
        $queued = unserialize(json_decode($payload, true)['data']['command']);
        $this->assertInstanceOf(EnrichNiumVirtualAccountDetails::class, $queued);
        $this->assertSame($task->id, $queued->enrichmentId);
    }

    private function prepared(): NiumVirtualAccountRequest
    {
        $account = $this->ledgerAccount();
        $ledger = app(NiumVirtualAccountSafetyLedger::class);
        $request = $ledger->prepare($account, 'enrichment-'.Str::uuid(), $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);

        return $request->fresh();
    }

    private function resolve(NiumVirtualAccountRequest $request): array
    {
        $evidence = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-'.Str::uuid()]);
        $request = app(NiumVirtualAccountSafetyLedger::class)->resolveFromPostEvidence($request, 1, $evidence, attachVirtualAccount: true);
        $va = $request->operation()->firstOrFail()->virtualAccount()->firstOrFail();

        return [$va, Enrichment::query()->where('operation_id', $request->operation_id)->sole(), $request];
    }

    private function payload(NiumVirtualAccount $va, array $overrides = []): array
    {
        return ['content' => [array_replace([
            'uniquePaymentId' => $va->provider_payment_id, 'accountName' => 'Synthetic holder',
            'bankName' => 'DBS_HK', 'fullBankName' => 'Synthetic bank', 'accountType' => 'GLOBAL',
            'routingCodeType1' => 'SWIFT', 'routingCodeValue1' => 'SYNTHETICXXX',
            'routingCodeType2' => 'ABA (Wire)', 'routingCodeValue2' => '000000002',
        ], $overrides)], 'totalPages' => 1, 'totalElements' => 1];
    }

    private function fake(callable $callback): void
    {
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        Http::swap(new HttpFactory(app('events')));
        Http::preventStrayRequests();
        Http::fake($callback);
    }
}
