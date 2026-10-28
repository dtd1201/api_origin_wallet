<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountAssignmentScope;
use App\Models\NiumVirtualAccountDetail;
use App\Models\NiumVirtualAccountDetailEnrichment as Enrichment;
use App\Models\NiumVirtualAccountDispatchAttempt;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\NiumVirtualAccountRequest;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Nium\NiumPaymentIdService;
use App\Services\Nium\NiumVirtualAccountAssignmentContract;
use App\Services\Nium\NiumVirtualAccountDetailEnrichmentService as EnrichmentService;
use App\Services\Nium\NiumVirtualAccountDetailService;
use App\Services\Nium\NiumVirtualAccountReconciliationService;
use App\Services\Nium\NiumVirtualAccountRequestIdentity;
use App\Services\Nium\NiumVirtualAccountSafetyLedger;
use App\Services\Nium\NiumVirtualAccountWebhookContract;
use App\Services\Nium\NiumWebhookService;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesNiumVirtualAccountLedgerFixtures;
use Tests\TestCase;
use Throwable;

/** Uses a pre-migrated disposable database, never migrate:fresh or truncation. */
class NiumVirtualAccountSafetyLedgerPostgresTest extends TestCase
{
    use CreatesNiumVirtualAccountLedgerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('NIUM_VA_LEDGER_PG_TESTS') !== '1' || DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Opt-in disposable PostgreSQL database and pcntl required.');
        }
        $connection = config('database.connections.'.DB::getDefaultConnection());
        if (! in_array($connection['database'], ['nium_va_ledger_test', 'nium_va_ledger_test_hardened'], true)
            || ! str_starts_with($connection['host'], '/tmp/nium-va-ledger-pg.')) {
            $this->fail('PostgreSQL ledger tests require their isolated /tmp Unix-socket cluster.');
        }
        $this->configureLedgerTarget();
        Http::preventStrayRequests();
    }

    public function test_competing_workers_can_consume_only_one_dispatch_authorization(): void
    {
        $account = $this->ledgerAccount();
        $request = (new NiumVirtualAccountSafetyLedger)->prepare($account, 'postgres-dispatch', $this->ledgerIdentity($account));
        $results = $this->race(function () use ($request): string {
            $attempt = (new NiumVirtualAccountSafetyLedger)->consumeDispatchAuthorization($request, 0);
            Http::assertNothingSent();

            return $attempt === null ? 'loser' : 'winner';
        });

        sort($results);
        $this->assertSame(['loser', 'winner'], $results);
        $this->assertSame(1, NiumVirtualAccountDispatchAttempt::query()->where('operation_id', $request->operation_id)->count());
        $this->assertSame(1, $request->fresh()->state_version);
        $this->assertSame(Operation::SUBMITTING, $request->operation()->value('status'));
    }

    public function test_competing_operation_ids_on_different_accounts_share_the_actual_wallet_scope(): void
    {
        $first = $this->ledgerAccount();
        $second = $this->ledgerAccount($first->external_account_id);
        $accountIds = [$first->id, $second->id];
        $scopeKey = $this->ledgerIdentity($first)->scopeKey();
        $results = $this->race(function (int $worker) use ($accountIds): string {
            $account = UserProviderAccount::query()->findOrFail($accountIds[$worker]);
            try {
                (new NiumVirtualAccountSafetyLedger)->prepare($account, 'postgres-operation-'.$worker, $this->ledgerIdentity($account));

                return 'winner';
            } catch (RuntimeException $exception) {
                if (! str_contains($exception->getMessage(), 'unresolved assignment')) {
                    throw $exception;
                }

                return 'loser';
            }
        });

        sort($results);
        $this->assertSame(['loser', 'winner'], $results);
        $this->assertSame(1, NiumVirtualAccountRequest::query()->where('scope_key', $scopeKey)->count());
        $this->assertSame(1, Operation::query()->whereIn('user_provider_account_id', $accountIds)->count());
        $this->assertNotNull(NiumVirtualAccountAssignmentScope::query()->findOrFail($scopeKey)->active_operation_id);
    }

    public function test_malformed_response_cannot_release_scope_to_a_competing_operation_key(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'postgres-malformed-first', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $evidence = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => "VA\nINVALID"]);
        $rawResponse = $evidence->niumExchangeEvidence->raw_response_body;

        $results = $this->race(function (int $worker) use ($request, $account, $evidence): string {
            $ledger = new NiumVirtualAccountSafetyLedger;
            if ($worker === 0) {
                return $ledger->resolveFromPostEvidence($request, 1, $evidence)->operation_state;
            }
            try {
                $ledger->prepare($account, 'postgres-malformed-competing', $this->ledgerIdentity($account));

                return 'unsafe-new-operation';
            } catch (RuntimeException $exception) {
                if (! str_contains($exception->getMessage(), 'unresolved assignment')) {
                    throw $exception;
                }

                return 'blocked';
            }
        });

        sort($results);
        $this->assertSame(['blocked', Operation::UNKNOWN], $results);
        $request = $request->fresh();
        $this->assertSame(Operation::UNKNOWN, $request->operation_state);
        $this->assertSame(2, $request->state_version);
        $this->assertNull($request->outcome);
        $this->assertNull($request->operation()->value('provider_payment_id'));
        $this->assertNull($request->operation()->value('resolved_at'));
        $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
        $this->assertSame(1, NiumVirtualAccountRequest::query()->where('scope_key', $request->scope_key)->count());
        $this->assertSame(1, $request->dispatchAttempt()->count());
        $this->assertNull($ledger->consumeDispatchAuthorization($request, 2));
        $this->assertSame($rawResponse, $evidence->fresh()->niumExchangeEvidence->raw_response_body);
        Http::assertNothingSent();
    }

    public function test_malformed_and_valid_response_race_cannot_overwrite_a_terminal_assignment(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'postgres-malformed-valid-race', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $malformed = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => "VA\nINVALID"]);
        $valid = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-PG-VERIFIED']);
        $results = $this->race(function (int $worker) use ($request, $malformed, $valid): string {
            try {
                return (new NiumVirtualAccountSafetyLedger)->resolveFromPostEvidence($request, 1, $worker === 0 ? $malformed : $valid)->operation_state;
            } catch (RuntimeException $exception) {
                if (! str_contains($exception->getMessage(), 'Stale')) {
                    throw $exception;
                }

                return 'stale';
            }
        });
        sort($results);
        $this->assertContains($results, [['assigned', 'stale'], ['stale', 'unknown']]);
        $current = $request->fresh();
        $this->assertSame(2, $current->state_version);
        if ($current->operation_state === Operation::UNKNOWN) {
            $this->assertSame($request->operation_id, $current->assignmentScope()->value('active_operation_id'));
            $current = $ledger->resolveFromPostEvidence($current, 2, $valid);
        }
        $this->assertSame(Operation::ASSIGNED, $current->operation_state);
        $this->assertSame('VA-PG-VERIFIED', $current->operation()->value('provider_payment_id'));
        $this->assertNull($current->assignmentScope()->value('active_operation_id'));
        $this->assertSame($current->state_version, $ledger->resolveFromPostEvidence($current, $current->state_version, $valid)->state_version);
        try {
            $ledger->resolveFromPostEvidence($current, $current->state_version, $malformed);
            $this->fail('Late malformed evidence cannot change a verified terminal outcome.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('conflicting terminal', $exception->getMessage());
        }
        $this->assertSame('VA-PG-VERIFIED', $current->operation()->value('provider_payment_id'));
        Http::assertNothingSent();
    }

    public function test_malformed_legacy_assignment_blocks_competing_wallet_owners_without_changing_history(): void
    {
        $first = $this->ledgerAccount();
        $second = $this->ledgerAccount($first->external_account_id);
        $legacy = $this->legacyLedgerOperation($first, Operation::ASSIGNED, [
            'provider_payment_id' => "VA\nINVALID", 'resolved_at' => now(),
        ]);
        $before = $legacy->fresh()->getAttributes();
        $accounts = [$first, $second];
        $results = $this->race(function (int $worker) use ($accounts): string {
            $account = $accounts[$worker];
            try {
                (new NiumVirtualAccountSafetyLedger)->prepare($account, 'postgres-invalid-legacy-'.$worker, $this->ledgerIdentity($account));

                return 'unsafe-new-operation';
            } catch (RuntimeException $exception) {
                if (! str_contains($exception->getMessage(), 'Legacy unresolved')) {
                    throw $exception;
                }

                return 'blocked';
            }
        });
        $this->assertSame(['blocked', 'blocked'], $results);
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->assertSame(0, NiumVirtualAccountRequest::query()->whereIn('operation_id', Operation::query()->whereIn('user_provider_account_id', [$first->id, $second->id])->select('id'))->count());
        Http::assertNothingSent();
    }

    public function test_postgres_guards_preserve_consumption_identity_and_unresolved_scope(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'postgres-guards', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $ledger->transition($request, 1, Operation::UNKNOWN);
        foreach ([
            fn () => DB::table('nium_va_dispatch_attempts')->insert(['operation_id' => $request->operation_id, 'consumed_at' => now()]),
            fn () => DB::table('nium_va_dispatch_attempts')->where('operation_id', $request->operation_id)->delete(),
            fn () => DB::table('nium_va_dispatch_attempts')->where('operation_id', $request->operation_id)->update(['consumed_at' => now()->addDay()]),
            fn () => $request->operation()->update(['currency' => 'EUR']),
            fn () => DB::table('nium_va_requests')->where('operation_id', $request->operation_id)->update(['snapshot' => 'changed', 'state_version' => 3]),
            fn () => $request->assignmentScope()->update(['active_operation_id' => null]),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('PostgreSQL must reject the invariant violation.');
            } catch (QueryException $exception) {
                $this->assertContains($exception->getCode(), ['23505', '23514']);
            }
        }
        $this->assertNull($ledger->consumeDispatchAuthorization($request, 2));
        $resolved = $ledger->resolveFromPostEvidence($request, 2, $this->ledgerResponseEvidence($request));
        $this->assertNull($resolved->assignmentScope()->value('active_operation_id'));
        $next = $ledger->prepare($account, 'postgres-next-usd', $this->ledgerIdentity($account));
        $this->assertNotNull($ledger->consumeDispatchAuthorization($next, 0));
        Http::assertNothingSent();
    }

    public function test_postgres_rejects_outer_transaction_and_keeps_a_committed_consumption_after_rollback(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'postgres-nested', $this->ledgerIdentity($account));
        DB::beginTransaction();
        try {
            $ledger->consumeDispatchAuthorization($request, 0);
            $this->fail('An outer transaction cannot receive authorization.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('top-level', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, $request->dispatchAttempt()->count());
        $this->assertNotNull($ledger->consumeDispatchAuthorization($request, 0));
        DB::beginTransaction();
        DB::rollBack();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::getPdo()->inTransaction());
        $this->assertNull($ledger->consumeDispatchAuthorization($request, 1));
    }

    #[DataProvider('commitFailurePoints')]
    public function test_commit_failure_never_returns_authorization_and_does_not_retry(string $event, bool $committed): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'postgres-commit-failure', $this->ledgerIdentity($account));
        $connection = DB::connection();
        $originalDispatcher = $connection->getEventDispatcher();
        $dispatcher = clone $originalDispatcher;
        $failures = 0;
        $dispatcher->listen($event, function () use (&$failures): void {
            $failures++;
            throw new RuntimeException('Injected commit acknowledgement failure.');
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $ledger->consumeDispatchAuthorization($request, 0);
            $this->fail('No dispatch-capable result may escape a commit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected commit acknowledgement failure.', $exception->getMessage());
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }
        $this->assertSame(1, $failures);
        $connection->reconnectIfMissingConnection();
        $this->assertFalse($connection->getPdo()->inTransaction());
        $this->assertSame($committed ? 1 : 0, $request->dispatchAttempt()->count());
        if ($committed) {
            $this->assertNull($ledger->consumeDispatchAuthorization($request, 1));
        } else {
            // No authorization was returned or committed, and no external activity occurred.
            $this->assertNotNull($ledger->consumeDispatchAuthorization($request, 0));
        }
    }

    public static function commitFailurePoints(): array
    {
        return [[TransactionCommitting::class, false], [TransactionCommitted::class, true]];
    }

    public function test_worker_crash_after_consumption_does_not_restore_authorization(): void
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX signals required for the abrupt worker crash test.');
        }
        $account = $this->ledgerAccount();
        $request = (new NiumVirtualAccountSafetyLedger)->prepare($account, 'postgres-worker-exit', $this->ledgerIdentity($account));
        DB::disconnect();
        $pid = pcntl_fork();
        $this->assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            DB::purge();
            $attempt = (new NiumVirtualAccountSafetyLedger)->consumeDispatchAuthorization($request, 0);
            if ($attempt === null) {
                exit(1);
            }
            posix_kill(getmypid(), SIGKILL);
            exit(2);
        }
        pcntl_waitpid($pid, $status);
        DB::purge();
        $this->assertTrue(pcntl_wifsignaled($status));
        $this->assertSame(SIGKILL, pcntl_wtermsig($status));
        $this->assertSame(1, $request->dispatchAttempt()->count());
        $this->assertNull((new NiumVirtualAccountSafetyLedger)->consumeDispatchAuthorization($request, 1));
        Http::assertNothingSent();
    }

    public function test_competing_state_writers_cannot_reuse_the_same_version(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'postgres-state-cas', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $results = $this->race(function (int $worker) use ($request): string {
            try {
                (new NiumVirtualAccountSafetyLedger)->transition($request, 1, $worker === 0 ? Operation::PENDING : Operation::UNKNOWN);

                return 'winner';
            } catch (RuntimeException $exception) {
                if (! str_contains($exception->getMessage(), 'Stale')) {
                    throw $exception;
                }

                return 'loser';
            }
        });
        sort($results);
        $this->assertSame(['loser', 'winner'], $results);
        $this->assertSame(2, $request->fresh()->state_version);
        $this->assertPostgresRejects(fn () => $request->operation()->update(['status' => Operation::MANUAL_REVIEW]));
    }

    public function test_postgres_terminal_projection_and_resolution_evidence_are_immutable(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'postgres-terminal', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $evidence = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-PG-TERMINAL']);
        $resolved = $ledger->resolveFromPostEvidence($request, 1, $evidence);
        $this->assertSame(2, $ledger->resolveFromPostEvidence($resolved, 2, $evidence)->state_version);
        foreach ([
            fn () => $resolved->operation()->update(['provider_payment_id' => 'CONFLICT']),
            fn () => $resolved->operation()->firstOrFail()->update(['resolved_at' => now()->addDay()]),
            fn () => $resolved->operation()->update(['status' => Operation::FAILED]),
            fn () => $resolved->newQuery()->whereKey($resolved->operation_id)->update(['operation_state' => Operation::UNKNOWN, 'state_version' => 3]),
            fn () => $evidence->update(['response_status' => 503]),
            fn () => $evidence->niumExchangeEvidence()->update(['request_url' => 'https://wrong.test']),
            fn () => $evidence->niumExchangeEvidence()->delete(),
        ] as $write) {
            $this->assertPostgresRejects($write);
        }
        $this->assertSame('VA-PG-TERMINAL', $resolved->operation()->value('provider_payment_id'));
        $next = $ledger->prepare($account, 'postgres-definitive-failure', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($next, 0);
        $rejection = $this->ledgerResponseEvidence($next, ['errors' => [['code' => 'invalid_input', 'field' => 'currency']]], 400);
        $failed = $ledger->resolveFromPostEvidence($next, 1, $rejection);
        $this->assertSame(Operation::FAILED, $failed->operation()->value('status'));
        $this->assertNull($failed->operation()->value('provider_payment_id'));
        $this->assertNull($failed->assignmentScope()->value('active_operation_id'));
        $this->assertPostgresRejects(fn () => $failed->operation()->update(['provider_payment_id' => 'CONFLICT']));
    }

    public function test_postgres_legacy_flow_remains_compatible_and_barrier_is_inactive_for_live_service(): void
    {
        config([
            'services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-key'],
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'test-partner-key',
        ]);
        $account = $this->ledgerAccount();
        Http::fake(fn ($request) => $request->method() === 'GET'
            ? Http::response(['content' => []])
            : Http::response(['uniquePaymentId' => 'VA-PG-LEGACY', 'currencyCode' => 'USD']));
        $before = $account->fresh()->getAttributes();
        $va = app(NiumPaymentIdService::class)->assign(
            $account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', operationId: 'pg-live-unchanged',
        );
        Http::assertSentCount(2);
        $this->assertSame('VA-PG-LEGACY', $va->provider_payment_id);
        $operation = Operation::query()->where('user_provider_account_id', $account->id)->sole();
        $this->assertSame(Operation::ASSIGNED, $operation->status);
        $this->assertFalse(NiumVirtualAccountRequest::query()->whereKey($operation->id)->exists());
        $identity = $this->ledgerIdentity($account);
        $this->assertFalse(NiumVirtualAccountAssignmentScope::query()->whereKey($identity->scopeKey())->exists());
        $ledger = new NiumVirtualAccountSafetyLedger;
        $this->assertTrue($ledger->legacyOperationEligibility($identity)['eligible']);
        $this->assertSame($before, $account->fresh()->getAttributes());
        $legacy = $this->legacyLedgerOperation($account, Operation::UNKNOWN);
        $this->assertSame([$legacy->id], $ledger->legacyOperationEligibility($identity)['blocking_operation_ids']);
        try {
            $ledger->prepare($account, 'pg-legacy-barrier', $identity);
            $this->fail('Unresolved legacy operation must block a new ledger request.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Legacy unresolved', $exception->getMessage());
        }
    }

    #[DataProvider('assignmentVersions')]
    public function test_integrated_concurrent_same_key_sends_only_one_mock_post(string $version): void
    {
        $account = $this->ledgerAccount();
        $this->enableSingleDispatch($account);
        $results = $this->race(function () use ($account, $version): string {
            Http::fake(function ($outgoing) use ($version) {
                $this->assertSame('POST', $outgoing->method());
                $this->assertSame(0, DB::transactionLevel());
                $this->assertFalse(DB::connection()->getPdo()->inTransaction());

                return Http::response(['uniquePaymentId' => 'VA-PG-INTEGRATED-SAME-KEY-'.$version]);
            });
            app(NiumPaymentIdService::class)->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', bankName: 'JPM_SG', operationId: 'pg-integrated-same-key', apiVersion: $version);

            return Http::recorded()->count() === 1 ? 'sent' : 'replay';
        });
        sort($results);
        $this->assertSame(['replay', 'sent'], $results);
        $operation = Operation::query()->where('user_provider_account_id', $account->id)->sole();
        $this->assertSame(Operation::ASSIGNED, $operation->status);
        $this->assertNotNull($operation->nium_virtual_account_id);
        $this->assertSame(1, $account->niumVirtualAccounts()->count());
        $this->assertSame(1, $operation->apiRequestLog()->count());
        $this->assertSame(1, NiumVirtualAccountDispatchAttempt::query()->where('operation_id', $operation->id)->count());
        $this->assertPostgresRejects(fn () => $operation->update(['nium_virtual_account_id' => null]));
    }

    #[DataProvider('assignmentVersions')]
    public function test_integrated_competing_keys_send_only_one_post_and_hold_ambiguous_scope(string $version): void
    {
        $account = $this->ledgerAccount();
        $this->enableSingleDispatch($account);
        $results = $this->race(function (int $worker) use ($account, $version): string {
            Http::fake(fn () => Http::response([], 409));
            try {
                $operation = app(NiumPaymentIdService::class)->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', bankName: 'JPM_SG', operationId: 'pg-integrated-different-'.$worker, apiVersion: $version);
                $this->assertSame(Operation::UNKNOWN, $operation->status);
                Http::assertSentCount(1);

                return 'sent';
            } catch (RuntimeException $exception) {
                if (! str_contains($exception->getMessage(), 'unresolved assignment')) {
                    throw $exception;
                }
                Http::assertNothingSent();

                return 'blocked';
            }
        });
        sort($results);
        $this->assertSame(['blocked', 'sent'], $results);
        $operation = Operation::query()->where('user_provider_account_id', $account->id)->sole();
        $request = NiumVirtualAccountRequest::query()->findOrFail($operation->id);
        $this->assertSame(Operation::UNKNOWN, $operation->status);
        $this->assertSame($operation->id, $request->assignmentScope()->value('active_operation_id'));
        $this->assertSame(1, $request->dispatchAttempt()->count());
        $this->assertNull($operation->nium_virtual_account_id);
    }

    public function test_integrated_sigkill_after_committed_authorization_never_redispatches(): void
    {
        $account = $this->ledgerAccount();
        $this->enableSingleDispatch($account);
        DB::disconnect();
        $pid = pcntl_fork();
        $this->assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            DB::purge();
            Http::fake(function () {
                // Emulate acceptance followed by process death before response/evidence saving.
                posix_kill(getmypid(), SIGKILL);

                return Http::response(['uniquePaymentId' => 'VA-ACCEPTED-BEFORE-CRASH']);
            });
            app(NiumPaymentIdService::class)->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', operationId: 'pg-integrated-killed');
            exit(1);
        }
        pcntl_waitpid($pid, $status);
        DB::purge();
        $this->assertTrue(pcntl_wifsignaled($status));
        $this->assertSame(SIGKILL, pcntl_wtermsig($status));
        $operation = app(NiumPaymentIdService::class)->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', operationId: 'pg-integrated-killed');
        $this->assertSame(Operation::SUBMITTING, $operation->status);
        $request = NiumVirtualAccountRequest::query()->findOrFail($operation->id);
        $this->assertSame(1, $request->dispatchAttempt()->count());
        $this->assertSame($operation->id, $request->assignmentScope()->value('active_operation_id'));
        Http::assertNothingSent();
    }

    #[DataProvider('commitFailurePoints')]
    public function test_integrated_commit_uncertainty_never_reaches_http(string $event, bool $committed): void
    {
        $account = $this->ledgerAccount();
        $this->enableSingleDispatch($account);
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $dispatcher = clone $original;
        $dispatcher->listen($event, function () use ($account): void {
            if (NiumVirtualAccountDispatchAttempt::query()->whereIn('operation_id', Operation::query()->where('user_provider_account_id', $account->id)->select('id'))->exists()) {
                throw new RuntimeException('Injected integrated authorization commit failure');
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            app(NiumPaymentIdService::class)->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', operationId: 'pg-integrated-commit-failure');
            $this->fail('An uncertain commit cannot authorize HTTP.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected integrated authorization commit failure', $exception->getMessage());
        } finally {
            $connection->setEventDispatcher($original);
        }
        $connection->reconnectIfMissingConnection();
        $operation = app(NiumPaymentIdService::class)->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', operationId: 'pg-integrated-commit-failure');
        $this->assertSame($committed ? Operation::SUBMITTING : Operation::CLAIMED, $operation->status);
        $this->assertSame($committed ? 1 : 0, NiumVirtualAccountDispatchAttempt::query()->where('operation_id', $operation->id)->count());
        Http::assertNothingSent();
    }

    public static function assignmentVersions(): array
    {
        return [['v1'], ['v2']];
    }

    #[DataProvider('collisionVersions')]
    public function test_cross_account_identifier_collision_is_quarantined_under_concurrent_resolution(string $firstVersion, string $secondVersion, bool $attach): void
    {
        $ledger = new NiumVirtualAccountSafetyLedger;
        $requests = [];
        $evidence = [];
        $paymentId = 'PG-COLLISION-'.$firstVersion.'-'.$secondVersion.'-'.(int) $attach;
        foreach ([$firstVersion, $secondVersion] as $index => $version) {
            $account = $this->ledgerAccount();
            $identity = NiumVirtualAccountRequestIdentity::forAccount(
                $account,
                NiumVirtualAccountAssignmentContract::payload($version, 'USD', 'SELF_FUNDING_ACCOUNT', 'WIRES', 'JPM_SG'),
                $version, $version === 'v1' ? 'WIRES' : null,
            );
            $requests[$index] = $ledger->prepare($account, 'pg-collision-'.$index, $identity);
            $ledger->consumeDispatchAuthorization($requests[$index], 0);
            $evidence[$index] = $this->ledgerResponseEvidence($requests[$index], ['uniquePaymentId' => $paymentId]);
        }
        $results = $this->race(function (int $worker) use ($requests, $evidence, $attach): string {
            return (new NiumVirtualAccountSafetyLedger)->resolveFromPostEvidence($requests[$worker], 1, $evidence[$worker], attachVirtualAccount: $attach)->operation_state;
        });
        sort($results);
        $this->assertSame([Operation::ASSIGNED, Operation::MANUAL_REVIEW], $results);
        $this->assertSame(1, Operation::query()->where('provider_payment_id', $paymentId)->count());
        $this->assertSame((int) $attach, NiumVirtualAccount::query()->where('provider_payment_id', $paymentId)->count());
        foreach ($requests as $request) {
            $request->refresh();
            $this->assertSame(1, $request->dispatchAttempt()->count());
            if ($request->operation_state === Operation::MANUAL_REVIEW) {
                $this->assertNull($ledger->consumeDispatchAuthorization($request, $request->state_version));
                $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
                $this->assertNull($request->outcome);
                $this->assertNull($request->operation->provider_payment_id);
                $this->assertNull($request->operation->nium_virtual_account_id);
            } else {
                $this->assertNull($request->assignmentScope()->value('active_operation_id'));
            }
        }
        Http::assertNothingSent();
    }

    public static function collisionVersions(): array
    {
        return [['v1', 'v1', true], ['v1', 'v2', true], ['v2', 'v2', true], ['v1', 'v2', false]];
    }

    public function test_repeatable_read_collision_resolution_fails_closed(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'pg-repeatable-read', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $evidence = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'PG-REPEATABLE-READ']);
        DB::statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        try {
            $ledger->resolveFromPostEvidence($request, 1, $evidence, attachVirtualAccount: true);
            $this->fail('A stale snapshot cannot authorize assignment.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('READ COMMITTED', $exception->getMessage());
        } finally {
            DB::statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        $this->assertSame(Operation::SUBMITTING, $request->fresh()->operation_state);
        $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
        $this->assertNull($request->operation->nium_virtual_account_id);
        Http::assertNothingSent();
    }

    public function test_collision_waiter_observes_committed_owner_after_an_actual_advisory_lock_wait(): void
    {
        $ledger = new NiumVirtualAccountSafetyLedger;
        $requests = [];
        $logs = [];
        for ($index = 0; $index < 2; $index++) {
            $account = $this->ledgerAccount();
            $identity = NiumVirtualAccountRequestIdentity::forAccount($account, [
                'currencyCode' => 'USD', 'accountCategory' => 'SELF_FUNDING_ACCOUNT', 'bankName' => 'JPM_SG',
            ], 'v1', 'WIRES');
            $requests[$index] = $ledger->prepare($account, 'pg-controlled-collision', $identity);
            $ledger->consumeDispatchAuthorization($requests[$index], 0);
            $logs[$index] = $this->ledgerResponseEvidence($requests[$index], ['uniquePaymentId' => 'PG-CONTROLLED-COLLISION']);
        }
        $result = $this->withLedgerWorker(
            fn () => (new NiumVirtualAccountSafetyLedger)->resolveFromPostEvidence($requests[1], 1, $logs[1], attachVirtualAccount: true)->operation_state,
            function ($socket, int $workerPid) use ($ledger, $requests, $logs): void {
                $paused = false;
                DB::connection()->beforeExecuting(function (string $query) use (&$paused, $socket, $workerPid): void {
                    if ($paused || ! str_contains($query, 'from "nium_virtual_accounts"')) {
                        return;
                    }
                    // Parent owns the identifier advisory lock, before its ownership SELECT.
                    $paused = true;
                    fwrite($socket, "go\n");
                    $this->assertSame("account-lock\n", fgets($socket));
                    $deadline = microtime(true) + 3;
                    do {
                        $waiting = DB::selectOne("SELECT count(*) AS n FROM pg_locks WHERE pid = ? AND locktype = 'advisory' AND NOT granted", [$workerPid]);
                        if ((int) $waiting->n === 1) {
                            return;
                        }
                        usleep(10000);
                    } while (microtime(true) < $deadline);
                    $this->fail('The independent resolver must actually wait on the identifier lock.');
                });
                $this->assertSame(Operation::ASSIGNED, $ledger->resolveFromPostEvidence($requests[0], 1, $logs[0], attachVirtualAccount: true)->operation_state);
                $this->assertTrue($paused);
            },
        );
        $this->assertSame(Operation::MANUAL_REVIEW, $result);
        $this->assertSame($requests[1]->operation_id, $requests[1]->assignmentScope()->value('active_operation_id'));
        $this->assertNull($requests[1]->fresh()->outcome);
        $this->assertSame(1, NiumVirtualAccount::query()->where('provider_payment_id', 'PG-CONTROLLED-COLLISION')->count());
        Http::assertNothingSent();
    }

    public function test_mixed_version_preparations_share_the_same_wallet_currency_lock(): void
    {
        $first = $this->ledgerAccount();
        $second = $this->ledgerAccount($first->external_account_id);
        $accountIds = [$first->id, $second->id];
        $results = $this->race(function (int $worker) use ($accountIds): string {
            $account = UserProviderAccount::query()->findOrFail($accountIds[$worker]);
            $version = $worker === 0 ? 'v1' : 'v2';
            $identity = NiumVirtualAccountRequestIdentity::forAccount($account,
                NiumVirtualAccountAssignmentContract::payload($version, 'USD', 'SELF_FUNDING_ACCOUNT', 'WIRES', 'JPM_SG'),
                $version, $version === 'v1' ? 'WIRES' : null);
            try {
                (new NiumVirtualAccountSafetyLedger)->prepare($account, 'pg-mixed-version-scope', $identity);

                return 'prepared';
            } catch (RuntimeException $exception) {
                if (! str_contains($exception->getMessage(), 'unresolved assignment')) {
                    throw $exception;
                }

                return 'blocked';
            }
        });
        sort($results);
        $this->assertSame(['blocked', 'prepared'], $results);
        $this->assertSame(1, Operation::query()->whereIn('user_provider_account_id', $accountIds)->count());
        Http::assertNothingSent();
    }

    public function test_concurrent_reconciliation_workers_attach_one_va_and_preserve_terminal_evidence(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'pg-reconcile-same-operation', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $log = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-PG-RECONCILE-ONCE']);
        $results = $this->race(function () use ($request): string {
            $operation = app(NiumVirtualAccountReconciliationService::class)->reconcile($request->operation()->firstOrFail());
            Http::assertNothingSent();

            return $operation->status;
        });
        $this->assertSame([Operation::ASSIGNED, Operation::ASSIGNED], $results);
        $this->assertSame(1, NiumVirtualAccount::query()->where('provider_payment_id', 'VA-PG-RECONCILE-ONCE')->count());
        $this->assertNotNull($request->operation()->value('nium_virtual_account_id'));
        $this->assertSame(1, $request->dispatchAttempt()->count());
        $this->assertSame($log->id, $request->fresh()->resolution_api_request_log_id);
        $this->assertNull($request->assignmentScope()->value('active_operation_id'));
        $before = $request->fresh()->getAttributes();
        app(NiumVirtualAccountReconciliationService::class)->reconcile($request->operation()->firstOrFail());
        $this->assertSame($before, $request->fresh()->getAttributes());
    }

    public function test_reconciliation_participants_quarantine_cross_account_identifier_collision(): void
    {
        $requests = [];
        foreach ([0, 1] as $i) {
            $account = $this->ledgerAccount();
            $ledger = new NiumVirtualAccountSafetyLedger;
            $request = $ledger->prepare($account, 'pg-reconcile-collision-'.$i, $this->ledgerIdentity($account));
            $ledger->consumeDispatchAuthorization($request, 0);
            $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-PG-RECONCILE-COLLISION']);
            $requests[] = $request;
        }
        $results = $this->race(function (int $i) use ($requests): string {
            $operation = app(NiumVirtualAccountReconciliationService::class)->reconcile($requests[$i]->operation()->firstOrFail());
            Http::assertNothingSent();

            return $operation->status;
        });
        sort($results);
        $this->assertSame([Operation::ASSIGNED, Operation::MANUAL_REVIEW], $results);
        $this->assertSame(1, NiumVirtualAccount::query()->where('provider_payment_id', 'VA-PG-RECONCILE-COLLISION')->count());
        foreach ($requests as $request) {
            $current = $request->fresh();
            if ($current->operation_state === Operation::MANUAL_REVIEW) {
                $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
                $this->assertNull($request->operation()->value('provider_payment_id'));
                $this->assertNull($request->operation()->value('nium_virtual_account_id'));
                $this->assertNull($current->outcome);
            }
        }
    }

    public function test_reconciliation_revalidates_wallet_after_waiting_for_account_lock(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'pg-reconcile-target-race', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-PG-STALE-TARGET']);
        $result = $this->withLedgerWorker(function () use ($request): string {
            return app(NiumVirtualAccountReconciliationService::class)->reconcile($request->operation()->firstOrFail())->status;
        }, function ($socket, int $workerPid) use ($account): void {
            DB::beginTransaction();
            $account = UserProviderAccount::query()->lockForUpdate()->findOrFail($account->id);
            $account->update(['external_account_id' => 'changed-wallet-after-read']);
            fwrite($socket, "go\n");
            $this->assertWorkerWaitingForAccount($socket, $workerPid);
            DB::commit();
        });
        $this->assertSame(Operation::SUBMITTING, $result);
        $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
        $this->assertNull($request->operation()->value('provider_payment_id'));
        $this->assertFalse(NiumVirtualAccount::query()->where('provider_payment_id', 'VA-PG-STALE-TARGET')->exists());
        Http::assertNothingSent();
    }

    public function test_reconciliation_va_write_failure_rolls_back_postgres_resolution(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'pg-reconcile-rollback', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-PG-RECONCILE-ROLLBACK']);
        $failInsert = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$failInsert): void {
            if ($failInsert && str_starts_with($query, 'insert into "nium_virtual_accounts"')) {
                throw new RuntimeException('Synthetic PostgreSQL VA insertion failure');
            }
        });
        try {
            $operation = app(NiumVirtualAccountReconciliationService::class)->reconcile($request->operation()->firstOrFail());
            $this->assertSame(Operation::SUBMITTING, $operation->status);
            $this->assertSame(1, $request->fresh()->state_version);
            $this->assertNull($request->fresh()->resolution_api_request_log_id);
            $this->assertNull($request->fresh()->outcome);
            $this->assertNull($operation->nium_virtual_account_id);
            $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
        } finally {
            $failInsert = false;
        }
        $this->assertSame(Operation::ASSIGNED, app(NiumVirtualAccountReconciliationService::class)->reconcile($request->operation()->firstOrFail())->status);
        Http::assertNothingSent();
    }

    private function configureVaWebhookIngress(): void
    {
        config([
            'services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-key'],
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'test-partner-key',
        ]);
    }

    private function vaWebhookPayload(NiumVirtualAccountRequest $request, string $eventId, string $paymentId): array
    {
        $snapshot = $request->identity()->snapshot();

        return [
            'eventId' => $eventId, 'template' => NiumVirtualAccountWebhookContract::ASSIGNED,
            'clientHashId' => $snapshot['client_id'], 'customerHashId' => $snapshot['customer_id'],
            'walletHashId' => $snapshot['wallet_id'], 'currencyCode' => $request->identity()->currency(),
            'uniquePaymentId' => $paymentId,
        ];
    }

    private function deliverVaWebhook(array $payload): array
    {
        $provider = IntegrationProvider::query()->where('code', 'nium')->firstOrFail();
        $incoming = Request::create('/api/webhooks/providers/nium', 'POST', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PARTNER_KEY' => 'test-partner-key',
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));

        return app(NiumWebhookService::class)->handleWebhook($provider, $incoming);
    }

    public function test_webhook_and_reconciliation_race_attach_one_va_without_post(): void
    {
        $this->configureVaWebhookIngress();
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'pg-webhook-vs-reconcile', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $post = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-PG-WEBHOOK-RECONCILE']);
        $payload = $this->vaWebhookPayload($request, 'pg-webhook-reconcile-delivery', 'VA-PG-WEBHOOK-RECONCILE');
        $results = $this->race(function (int $worker) use ($request, $payload): string {
            if ($worker === 0) {
                $this->deliverVaWebhook($payload);
            } else {
                app(NiumVirtualAccountReconciliationService::class)->reconcile($request->operation()->firstOrFail());
            }
            Http::assertNothingSent();

            return $request->operation()->value('status');
        });
        $this->assertSame([Operation::ASSIGNED, Operation::ASSIGNED], $results);
        $this->assertSame(1, NiumVirtualAccount::query()->where('provider_payment_id', 'VA-PG-WEBHOOK-RECONCILE')->count());
        $this->assertSame($post->id, $request->fresh()->resolution_api_request_log_id);
        $this->assertNull($request->assignmentScope()->value('active_operation_id'));
        $this->assertSame(1, $request->dispatchAttempt()->count());
    }

    public function test_concurrent_duplicate_webhook_receipts_have_one_durable_winner(): void
    {
        $this->configureVaWebhookIngress();
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'pg-duplicate-webhook', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-PG-WEBHOOK-DUPLICATE']);
        $payload = $this->vaWebhookPayload($request, 'pg-webhook-duplicate-delivery', 'VA-PG-WEBHOOK-DUPLICATE');
        $results = $this->race(function () use ($payload): string {
            $result = $this->deliverVaWebhook($payload);
            Http::assertNothingSent();

            return ($result['duplicate'] ?? false) ? 'duplicate' : 'processed';
        });
        sort($results);
        $this->assertSame(['duplicate', 'processed'], $results);
        $this->assertSame(1, WebhookEvent::query()->where('event_id', 'pg-webhook-duplicate-delivery')->count());
        $this->assertSame(1, NiumVirtualAccount::query()->where('provider_payment_id', 'VA-PG-WEBHOOK-DUPLICATE')->count());
        $this->assertSame(Operation::ASSIGNED, $request->operation()->value('status'));
    }

    public function test_cross_account_webhook_writers_quarantine_one_conflicting_identifier(): void
    {
        $this->configureVaWebhookIngress();
        $requests = [];
        $payloads = [];
        foreach ([0, 1] as $i) {
            $account = $this->ledgerAccount();
            $ledger = new NiumVirtualAccountSafetyLedger;
            $request = $ledger->prepare($account, 'pg-webhook-id-collision-'.$i, $this->ledgerIdentity($account));
            $ledger->consumeDispatchAuthorization($request, 0);
            $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-PG-WEBHOOK-COLLISION']);
            $requests[] = $request;
            $payloads[] = $this->vaWebhookPayload($request, 'pg-webhook-id-collision-'.$i, 'VA-PG-WEBHOOK-COLLISION');
        }
        $results = $this->race(function (int $i) use ($requests, $payloads): string {
            $this->deliverVaWebhook($payloads[$i]);
            Http::assertNothingSent();

            return $requests[$i]->operation()->value('status');
        });
        sort($results);
        $this->assertSame([Operation::ASSIGNED, Operation::MANUAL_REVIEW], $results);
        $this->assertSame(1, NiumVirtualAccount::query()->where('provider_payment_id', 'VA-PG-WEBHOOK-COLLISION')->count());
        foreach ($requests as $request) {
            if ($request->operation()->value('status') === Operation::MANUAL_REVIEW) {
                $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
                $this->assertNull($request->operation()->value('provider_payment_id'));
                $this->assertNull($request->operation()->value('nium_virtual_account_id'));
            }
        }
    }

    public function test_mixed_legacy_and_ledger_webhook_writers_share_collision_quarantine(): void
    {
        $this->configureVaWebhookIngress();
        $account = $this->ledgerAccount();
        $legacyAccount = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'pg-mixed-va-webhooks', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-PG-MIXED-WEBHOOKS']);
        $ledgerPayload = $this->vaWebhookPayload($request, 'pg-mixed-ledger-delivery', 'VA-PG-MIXED-WEBHOOKS');
        $legacyPayload = array_replace($ledgerPayload, [
            'eventId' => 'pg-mixed-legacy-delivery', 'customerHashId' => $legacyAccount->external_customer_id,
            'walletHashId' => $legacyAccount->external_account_id, 'accountType' => 'LOCAL', 'accountCategory' => 'COLLECTION_ACCOUNT',
        ]);
        $results = $this->race(function (int $worker) use ($ledgerPayload, $legacyPayload, $request): string {
            Http::fake(fn () => Http::response(['content' => []]));
            try {
                $this->deliverVaWebhook($worker === 0 ? $ledgerPayload : $legacyPayload);
                Http::assertNotSent(fn ($outgoing): bool => $outgoing->method() !== 'GET');

                return $worker === 0 ? $request->operation()->value('status') : 'legacy_assigned';
            } catch (RuntimeException $exception) {
                if (! str_contains($exception->getPrevious()?->getMessage() ?? '', 'ownership collision')) {
                    throw $exception;
                }
                Http::assertNothingSent();

                return 'legacy_collision';
            }
        });
        $this->assertContains($results, [[Operation::ASSIGNED, 'legacy_collision'], [Operation::MANUAL_REVIEW, 'legacy_assigned']]);
        $this->assertSame(1, NiumVirtualAccount::query()->where('provider_payment_id', 'VA-PG-MIXED-WEBHOOKS')->count());
        $this->assertSame(1, $request->dispatchAttempt()->count());
        if ($request->operation()->value('status') === Operation::MANUAL_REVIEW) {
            $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
            $this->assertNull($request->operation()->value('nium_virtual_account_id'));
        } else {
            $this->assertNull($request->assignmentScope()->value('active_operation_id'));
        }
    }

    public function test_webhook_waits_for_account_before_taking_request_operation_or_evidence_locks(): void
    {
        $this->configureVaWebhookIngress();
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'pg-webhook-lock-order', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $post = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-PG-WEBHOOK-LOCK-ORDER']);
        $payload = $this->vaWebhookPayload($request, 'pg-webhook-lock-order-delivery', 'VA-PG-WEBHOOK-LOCK-ORDER');
        $result = $this->withLedgerWorker(function () use ($request, $payload): string {
            $this->deliverVaWebhook($payload);
            Http::assertNothingSent();

            return $request->operation()->value('status');
        }, function ($socket, int $workerPid) use ($account, $request, $post): void {
            DB::beginTransaction();
            UserProviderAccount::query()->lockForUpdate()->findOrFail($account->id);
            fwrite($socket, "go\n");
            $this->assertWorkerWaitingForAccount($socket, $workerPid);
            // These later locks are obtainable while the webhook worker is blocked on account.
            NiumVirtualAccountAssignmentScope::query()->lockForUpdate()->findOrFail($request->scope_key);
            NiumVirtualAccountRequest::query()->lockForUpdate()->findOrFail($request->operation_id);
            Operation::query()->lockForUpdate()->findOrFail($request->operation_id);
            ApiRequestLog::query()->lockForUpdate()->findOrFail($post->id);
            $this->addToAssertionCount(1);
            DB::commit();
        });
        $this->assertSame(Operation::ASSIGNED, $result);
        $this->assertNull($request->assignmentScope()->value('active_operation_id'));
    }

    public function test_postgres_constraint_failure_rolls_back_webhook_resolution_and_retains_receipt(): void
    {
        $this->configureVaWebhookIngress();
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'pg-webhook-real-rollback', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-PG-WEBHOOK-ROLLBACK']);
        $payload = $this->vaWebhookPayload($request, 'pg-webhook-rollback-delivery', 'VA-PG-WEBHOOK-ROLLBACK');
        DB::statement("ALTER TABLE nium_virtual_accounts ADD CONSTRAINT nium_va_test_webhook_write_failure CHECK (provider_payment_id <> 'VA-PG-WEBHOOK-ROLLBACK')");
        try {
            $this->recoveryDelivery($payload)->assertStatus(422);
            $this->assertSame(Operation::SUBMITTING, $request->operation()->value('status'));
            $this->assertNull($request->fresh()->resolution_api_request_log_id);
            $this->assertNull($request->operation()->value('nium_virtual_account_id'));
            $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
        } finally {
            DB::statement('ALTER TABLE nium_virtual_accounts DROP CONSTRAINT nium_va_test_webhook_write_failure');
        }
        $event = WebhookEvent::query()->where('event_id', 'pg-webhook-rollback-delivery')->firstOrFail();
        $this->assertSame('failed', $event->processing_status);
        $this->assertSame($payload, NiumVirtualAccountWebhookContract::payload($event));
        $this->assertPostgresRejects(fn () => $event->update(['nium_va_evidence' => null]));
        $this->assertPostgresRejects(fn () => $event->delete());
        $this->recoveryDelivery($payload)->assertOk()->assertJsonPath('processing_status', 'processed');
        $this->assertSame(Operation::ASSIGNED, $request->operation()->value('status'));
        Http::assertNothingSent();
    }

    private function recoveryDelivery(array $payload): TestResponse
    {
        return $this->withHeader('x-partner-key', 'test-partner-key')
            ->postJson('/api/webhooks/providers/nium', $payload);
    }

    #[DataProvider('recoveryCrashBoundaries')]
    public function test_redelivery_recovers_after_real_processor_death(string $boundary): void
    {
        $this->configureVaWebhookIngress();
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'pg-recovery-crash-'.$boundary, $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $paymentId = 'VA-PG-CRASH-'.strtoupper($boundary);
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => $paymentId]);
        $payload = $this->vaWebhookPayload($request, 'pg-crash-delivery-'.$boundary, $paymentId);
        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::purge();
            DB::reconnect();
            if ($boundary === 'attachment') {
                NiumVirtualAccount::created(function ($va) use ($paymentId): void {
                    if ($va->provider_payment_id === $paymentId) {
                        posix_kill(getmypid(), SIGKILL);
                    }
                });
            } else {
                app('events')->listen(TransactionCommitted::class, function () use ($boundary, $payload): void {
                    // Continuation intent uses a savepoint; only the top-level
                    // commit represents this crash boundary.
                    if (DB::transactionLevel() !== 0) {
                        return;
                    }
                    $event = WebhookEvent::query()->where('event_id', $payload['eventId'])->first();
                    $resolved = $boundary === 'resolved' && $event?->processing_status === 'processing'
                        && Operation::query()->where('provider_payment_id', $payload['uniquePaymentId'])->where('status', Operation::ASSIGNED)->exists();
                    if ($event?->processing_status === $boundary || $resolved) {
                        posix_kill(getmypid(), SIGKILL);
                    }
                });
            }
            $this->recoveryDelivery($payload);
            exit(11); // Reaching here means the intended crash boundary was not exercised.
        }
        $this->assertGreaterThan(0, $pid);
        pcntl_waitpid($pid, $status);
        $this->assertTrue(pcntl_wifsignaled($status));
        $this->assertSame(SIGKILL, pcntl_wtermsig($status));
        DB::purge();
        DB::reconnect();
        $event = WebhookEvent::query()->where('event_id', $payload['eventId'])->firstOrFail();
        $this->assertSame($boundary === 'received' ? 'received' : 'processing', $event->processing_status);
        $this->assertSame($boundary === 'resolved' ? Operation::ASSIGNED : Operation::SUBMITTING, $request->operation()->value('status'));
        $this->assertSame($boundary === 'resolved' ? 1 : 0, NiumVirtualAccount::query()->where('provider_payment_id', $paymentId)->count());
        $assignedAt = DB::table('nium_virtual_accounts')->where('provider_payment_id', $paymentId)->value('assigned_at');
        $raw = DB::table('webhook_events')->where('id', $event->id)->value('nium_va_evidence');
        $this->recoveryDelivery($payload)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('processing_status', 'processed');
        $this->assertSame(Operation::ASSIGNED, $request->operation()->value('status'));
        $this->assertNull($request->assignmentScope()->value('active_operation_id'));
        $this->assertSame($raw, DB::table('webhook_events')->where('id', $event->id)->value('nium_va_evidence'));
        $this->assertSame($boundary === 'received' ? 1 : 2, $event->fresh()->nium_va_recovery['attempts']);
        $this->assertSame(1, $request->dispatchAttempt()->count());
        if ($boundary === 'resolved') {
            $this->assertSame($assignedAt, DB::table('nium_virtual_accounts')->where('provider_payment_id', $paymentId)->value('assigned_at'));
        }
        Http::assertNothingSent();
    }

    public static function recoveryCrashBoundaries(): array
    {
        return [['received'], ['processing'], ['attachment'], ['resolved']];
    }

    public function test_concurrent_redelivery_and_admin_retry_do_not_enter_an_active_processor(): void
    {
        $this->configureVaWebhookIngress();
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'pg-recovery-concurrent', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $payload = $this->vaWebhookPayload($request, 'pg-recovery-concurrent-delivery', 'VA-PG-RECOVERY-CONCURRENT');
        $this->recoveryDelivery($payload)->assertOk()->assertJsonPath('processing_status', 'observed');
        $event = WebhookEvent::query()->where('event_id', $payload['eventId'])->firstOrFail();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => $payload['uniquePaymentId']]);
        $admin = User::factory()->create();
        $admin->roles()->create(['role_code' => 'admin']);
        $token = (string) Str::uuid();
        ApiToken::query()->create([
            'user_id' => $admin->id, 'name' => 'pg-recovery', 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);
        $result = $this->withLedgerWorker(function () use ($payload): string {
            $this->recoveryDelivery($payload)->assertOk()->assertJsonPath('processing_status', 'processed');
            Http::assertNothingSent();

            return 'processed';
        }, function ($socket, int $workerPid) use ($event, $payload, $token): void {
            fwrite($socket, "go\n");
            $this->assertSame("account-lock\n", fgets($socket));
            // Worker owns the receipt claim, but has not entered the ledger account lock yet.
            $this->recoveryDelivery($payload)->assertOk()->assertJsonPath('duplicate', true)
                ->assertJsonPath('processing_status', 'processing');
            $this->withToken($token)->postJson('/api/admin/provider-webhook-events/'.$event->id.'/retry')
                ->assertStatus(422)->assertJsonPath('webhook_event.status', 'processing');
            $this->assertSame(2, $event->fresh()->nium_va_recovery['attempts']);
            fwrite($socket, "continue\n");
        }, pauseAtAccount: true);
        $this->assertSame('processed', $result);
        $this->assertSame(2, $event->fresh()->nium_va_recovery['attempts']);
        $this->recoveryDelivery($payload)->assertOk()->assertJsonPath('processing_status', 'processed');
        $this->assertSame(2, $event->fresh()->nium_va_recovery['attempts']);
        $this->assertSame(1, NiumVirtualAccount::query()->where('provider_payment_id', $payload['uniquePaymentId'])->count());
        Http::assertNothingSent();
    }

    public function test_stale_processor_after_database_session_loss_cannot_overwrite_recovery_winner(): void
    {
        $this->configureVaWebhookIngress();
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'pg-recovery-stale', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $payload = $this->vaWebhookPayload($request, 'pg-stale-delivery', 'VA-PG-STALE-RECOVERY');
        $this->recoveryDelivery($payload)->assertOk()->assertJsonPath('processing_status', 'observed');
        $event = WebhookEvent::query()->where('event_id', $payload['eventId'])->firstOrFail();
        $this->ledgerResponseEvidence($request, ['uniquePaymentId' => $payload['uniquePaymentId']]);
        $result = $this->withLedgerWorker(function () use ($payload): string {
            $this->recoveryDelivery($payload)->assertStatus(422);
            Http::assertNothingSent();

            return 'stale-rejected';
        }, function ($socket, int $workerPid) use ($event, $payload): void {
            fwrite($socket, "go\n");
            $this->assertSame("account-lock\n", fgets($socket));
            $this->assertTrue(DB::selectOne('SELECT pg_terminate_backend(?) AS terminated', [$workerPid])->terminated);
            $this->recoveryDelivery($payload)->assertOk()->assertJsonPath('processing_status', 'processed');
            $winner = $event->fresh()->nium_va_recovery['token'];
            $this->assertNotNull($winner);
            fwrite($socket, "continue\n");
        }, pauseAtAccount: true);
        $this->assertSame('stale-rejected', $result);
        $this->assertSame('processed', $event->fresh()->processing_status);
        $this->assertSame(3, $event->fresh()->nium_va_recovery['attempts']);
        $this->assertSame(Operation::ASSIGNED, $request->operation()->value('status'));
        $this->assertSame(1, NiumVirtualAccount::query()->where('provider_payment_id', $payload['uniquePaymentId'])->count());
        Http::assertNothingSent();
    }

    private function enableSingleDispatch(UserProviderAccount $account): void
    {
        config([
            'services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-key'],
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'test-partner-key',
            'services.nium.va_single_dispatch' => ['enabled' => true, 'account_ids' => [$account->id], 'old_workers_fenced' => true],
        ]);
    }

    #[DataProvider('eligibilityRevocations')]
    public function test_committed_revocation_while_waiting_for_the_account_lock_prevents_dispatch(array $revocation, bool $alreadyPrepared): void
    {
        $account = $this->ledgerAccount();
        $this->enableSingleDispatch($account);
        $request = $alreadyPrepared
            ? (new NiumVirtualAccountSafetyLedger)->prepare($account, 'pg-revoked', $this->ledgerIdentity($account))
            : null;

        $result = $this->withLedgerWorker(function () use ($account, $request): string {
            Http::fake(fn () => Http::response(['uniquePaymentId' => 'VA-MUST-NOT-DISPATCH']));
            try {
                if ($request === null) {
                    app(NiumPaymentIdService::class)->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', operationId: 'pg-revoked');
                } else {
                    (new NiumVirtualAccountSafetyLedger)->consumeDispatchAuthorization($request, 0);
                }
                throw new RuntimeException('Revoked account received dispatch authorization.');
            } catch (RuntimeException $exception) {
                if (! str_contains($exception->getMessage(), 'not eligible')) {
                    throw $exception;
                }
            }
            Http::assertNothingSent();

            return 'revoked';
        }, function ($socket, int $workerPid) use ($account, $revocation): void {
            DB::beginTransaction();
            $locked = UserProviderAccount::query()->lockForUpdate()->findOrFail($account->id);
            $locked->update($revocation);
            // The worker's initial unlocked eligibility SELECT still sees the committed
            // active row. Preparation/consumption must wait for this revocation to commit.
            fwrite($socket, "go\n");
            $this->assertWorkerWaitingForAccount($socket, $workerPid);
            DB::commit();
        });

        $this->assertSame('revoked', $result);
        $this->assertSame(0, NiumVirtualAccountDispatchAttempt::query()
            ->whereIn('operation_id', Operation::query()->where('user_provider_account_id', $account->id)->select('id'))->count());
        if ($request !== null) {
            $this->assertSame(Operation::CLAIMED, $request->fresh()->operation_state);
            $this->assertSame($request->operation_id, $request->assignmentScope()->value('active_operation_id'));
        } else {
            $this->assertFalse(Operation::query()->where('user_provider_account_id', $account->id)->exists());
        }
        Http::assertNothingSent();
    }

    public static function eligibilityRevocations(): array
    {
        $cases = [];
        foreach ([false, true] as $prepared) {
            foreach ([
                'suspended' => ['status' => 'suspended'],
                'under review' => ['status' => 'under_review'],
                'security conflict' => ['security_conflict_at' => '2026-10-27 00:00:00'],
            ] as $name => $change) {
                $cases[($prepared ? 'consumption ' : 'preparation ').$name] = [$change, $prepared];
            }
        }

        return $cases;
    }

    public function test_cross_currency_same_key_prepare_and_resolution_use_account_first_without_deadlock(): void
    {
        $account = $this->ledgerAccount();
        $ledger = new NiumVirtualAccountSafetyLedger;
        $request = $ledger->prepare($account, 'pg-cross-currency', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $evidence = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-PG-LOCK-ORDER']);
        $payload = array_replace($request->identity()->snapshot()['payload'], ['currency' => 'EUR']);
        $otherCurrency = $this->ledgerIdentity($account, ['payload' => $payload]);

        $result = $this->withLedgerWorker(function () use ($request, $evidence): string {
            return (new NiumVirtualAccountSafetyLedger)->resolveFromPostEvidence($request, 1, $evidence, attachVirtualAccount: true)->operation_state;
        }, function ($socket, int $workerPid) use ($account, $ledger, $otherCurrency): void {
            // Pause actual prepare after its account lock, before the existing operation
            // lookup. Resolution must wait for the account, without holding its operation.
            $started = false;
            DB::connection()->beforeExecuting(function (string $query) use (&$started, $socket, $workerPid): void {
                if (! $started && str_starts_with($query, 'select * from "nium_virtual_account_operations" where "user_provider_account_id"')) {
                    $started = true;
                    fwrite($socket, "go\n");
                    $this->assertWorkerWaitingForAccount($socket, $workerPid);
                }
            });
            try {
                $ledger->prepare($account, 'pg-cross-currency', $otherCurrency);
                $this->fail('Changed-currency replay must conflict with the existing immutable request.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('identity cannot change', $exception->getMessage());
            }
            $this->assertTrue($started);
        });

        $this->assertSame(Operation::ASSIGNED, $result);
        $this->assertSame('VA-PG-LOCK-ORDER', $request->operation()->value('provider_payment_id'));
        $this->assertNotNull($request->operation()->value('nium_virtual_account_id'));
        $this->assertNull($request->assignmentScope()->value('active_operation_id'));
        $this->assertSame(1, $request->dispatchAttempt()->count());
        $replay = app(NiumPaymentIdService::class)->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', operationId: 'pg-cross-currency');
        $this->assertSame($request->operation()->value('nium_virtual_account_id'), $replay->id);
        $this->assertFalse(NiumVirtualAccountAssignmentScope::query()->whereKey($otherCurrency->scopeKey())->exists());
        Http::assertNothingSent();
    }

    /** Run a real second connection with a controlled start and account-lock notification. */
    private function withLedgerWorker(callable $worker, callable $parent, bool $pauseAtAccount = false): string
    {
        DB::disconnect();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($sockets[0]);
            stream_set_timeout($sockets[1], 15);
            try {
                DB::purge();
                DB::reconnect();
                DB::statement("SET lock_timeout = '10s'");
                fwrite($sockets[1], DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n");
                if (fgets($sockets[1]) !== "go\n") {
                    throw new RuntimeException('Worker start barrier failed.');
                }
                $notified = false;
                DB::connection()->beforeExecuting(function (string $query) use (&$notified, $sockets, $pauseAtAccount): void {
                    if (! $notified && str_contains($query, 'from "user_provider_accounts"') && str_contains($query, 'for update')) {
                        $notified = true;
                        fwrite($sockets[1], "account-lock\n");
                        if ($pauseAtAccount && fgets($sockets[1]) !== "continue\n") {
                            throw new RuntimeException('Processor continuation barrier failed.');
                        }
                    }
                });
                fwrite($sockets[1], $worker()."\n");
                DB::disconnect();
                exit(0);
            } catch (Throwable $exception) {
                fwrite($sockets[1], 'error: '.$exception->getMessage()."\n");
                DB::disconnect();
                exit(1);
            }
        }
        $this->assertGreaterThan(0, $pid);
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 15);
        try {
            $workerPid = (int) fgets($sockets[0]);
            DB::purge();
            DB::reconnect();
            $this->assertNotSame($workerPid, (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
            $parent($sockets[0], $workerPid);
            $result = trim((string) fgets($sockets[0]));
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
            throw $exception;
        } finally {
            fclose($sockets[0]);
        }
        pcntl_waitpid($pid, $status);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status), $result);

        return $result;
    }

    private function assertWorkerWaitingForAccount($socket, int $workerPid): void
    {
        $this->assertSame("account-lock\n", fgets($socket));
        $deadline = microtime(true) + 3;
        do {
            // Clear the transaction-local statistics snapshot while inspecting the actual
            // PostgreSQL wait, rather than assuming a pre-query notification proves a race.
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$workerPid]);
            if ($waiting?->wait_event_type === 'Lock') {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('The independent worker never blocked on the provider account lock.');
    }

    private function assertPostgresRejects(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('PostgreSQL must reject uncontrolled or conflicting writes.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }

    #[DataProvider('detailRaceStates')]
    public function test_overlapping_detail_reads_cannot_overwrite_a_committed_winner(bool $existingDetail): void
    {
        config([
            'services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'synthetic-detail-key'],
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'synthetic-detail-partner-key',
            'services.nium.payment_ids_endpoint' => '/api/v2/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentIds',
        ]);
        $account = $this->ledgerAccount();
        $ledger = app(NiumVirtualAccountSafetyLedger::class);
        $request = $ledger->prepare($account, 'pg-detail-race', $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $paymentId = $existingDetail ? 'VA-PG-DETAIL-UPDATE' : 'VA-PG-DETAIL-INSERT';
        $log = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => $paymentId]);
        $request = $ledger->resolveFromPostEvidence($request, 1, $log, attachVirtualAccount: true);
        $va = $request->operation()->firstOrFail()->virtualAccount()->firstOrFail();
        if ($existingDetail) {
            NiumVirtualAccountDetail::query()->create([
                'nium_virtual_account_id' => $va->id, 'account_name' => 'Verified holder',
            ]);
        }
        // Both workers must finish their pre-GET snapshot before either response returns.
        // This tests separate PostgreSQL connections, not only reentrant HTTP callbacks.
        $peers = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($peers);
        try {
            $results = $this->race(function (int $worker) use ($va, $peers, $existingDetail): string {
                $peer = $peers[$worker];
                stream_set_timeout($peer, 15);
                Http::fake(function () use ($peer, $worker, $va, $existingDetail) {
                    if (fwrite($peer, "read-ready\n") === false || fgets($peer) !== "read-ready\n") {
                        throw new RuntimeException('Detail response barrier failed.');
                    }
                    $facts = $existingDetail
                        ? ($worker === 0 ? ['bankAddress' => 'Verified address'] : ['fullBankName' => 'Verified display name'])
                        : ['bankAddress' => 'Worker '.$worker.' authoritative address'];

                    return Http::response(['content' => [[
                        'uniquePaymentId' => $va->provider_payment_id, 'currencyCode' => 'USD',
                        'accountName' => 'Verified holder', 'bankName' => 'DBS_HK',
                    ] + $facts], 'totalPages' => 1, 'totalElements' => 1]);
                });
                try {
                    app(NiumVirtualAccountDetailService::class)->sync($va);
                    Http::assertNotSent(fn ($outgoing): bool => $outgoing->method() !== 'GET');

                    return 'winner';
                } catch (RuntimeException $exception) {
                    if (! str_contains($exception->getMessage(), 'Stale VA detail retrieval')) {
                        throw $exception;
                    }

                    return 'stale';
                }
            });
        } finally {
            fclose($peers[0]);
            fclose($peers[1]);
        }
        sort($results);
        $this->assertSame(['stale', 'winner'], $results);
        $detail = $va->detail()->firstOrFail();
        $this->assertSame(1, $va->detail()->count());
        $this->assertSame(1, $detail->enrichment_version);
        $this->assertSame('assigned', $va->fresh()->status);
        $this->assertSame(Operation::ASSIGNED, $request->fresh()->operation_state);
        if ($existingDetail) {
            $this->assertSame('Verified holder', $detail->account_name);
            // The loser can repeat GET after observing the winner, then fill missing facts.
            Http::fake(fn () => Http::response(['content' => [[
                'uniquePaymentId' => $paymentId, 'bankAddress' => 'Verified address',
                'fullBankName' => 'Verified display name',
            ]]]));
            $retried = app(NiumVirtualAccountDetailService::class)->sync($va);
            $this->assertSame($detail->id, $retried->id);
            $this->assertSame('Verified address', $retried->bank_address);
            $this->assertSame('Verified display name', $retried->bank_name);
            $this->assertSame(2, $retried->enrichment_version);
        } else {
            $this->assertContains($detail->bank_address, ['Worker 0 authoritative address', 'Worker 1 authoritative address']);
        }
        Http::assertNotSent(fn ($outgoing): bool => $outgoing->method() !== 'GET');
    }

    public static function detailRaceStates(): array
    {
        return ['first detail insert' => [false], 'existing partial detail update' => [true]];
    }

    public function test_concurrent_enrichment_job_deliveries_perform_one_read_and_one_merge(): void
    {
        [$request, $task] = $this->enrichmentFixture();
        $results = $this->race(function () use ($task, $request): string {
            Http::fake(fn () => Http::response($this->enrichmentPayload($request)));
            app(EnrichmentService::class)->run($task->id);
            Http::assertNotSent(fn ($outgoing): bool => $outgoing->method() !== 'GET');

            return (string) count(Http::recorded());
        });
        sort($results);
        $this->assertSame(['0', '1'], $results);
        $this->assertSame('complete', $task->fresh()->state);
        $this->assertSame(1, $task->fresh()->attempts);
        $this->assertSame(1, NiumVirtualAccountDetail::query()->where('nium_virtual_account_id', $task->nium_virtual_account_id)->sole()->enrichment_version);
        $this->assertSame(Operation::ASSIGNED, $request->fresh()->operation_state);
    }

    #[DataProvider('enrichmentCrashBoundaries')]
    public function test_enrichment_recovers_after_real_process_death_without_assignment_post(string $boundary): void
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX signals required for crash validation.');
        }
        [$request, $task, $log] = $this->enrichmentFixture($boundary === 'after_commit');
        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::purge();
            DB::reconnect();
            if ($boundary === 'after_commit') {
                app()->instance(EnrichmentService::class, new class extends EnrichmentService
                {
                    public function enqueue(int $id): bool
                    {
                        posix_kill(getmypid(), SIGKILL);

                        return false;
                    }
                });
                app(NiumVirtualAccountSafetyLedger::class)->resolveFromPostEvidence($request, 1, $log, attachVirtualAccount: true);
            } else {
                Http::fake(function () use ($boundary, $request) {
                    if ($boundary === 'during_get') {
                        posix_kill(getmypid(), SIGKILL);
                    }

                    return Http::response($this->enrichmentPayload($request));
                });
                if ($boundary === 'after_details') {
                    DB::connection()->beforeExecuting(function (string $query, array $bindings): void {
                        if (str_starts_with($query, 'update "nium_va_detail_enrichments"') && in_array('complete', $bindings, true)) {
                            posix_kill(getmypid(), SIGKILL);
                        }
                    });
                }
                app(EnrichmentService::class)->run($task->id);
            }
            exit(11);
        }
        $this->assertGreaterThan(0, $pid);
        pcntl_waitpid($pid, $status);
        $this->assertTrue(pcntl_wifsignaled($status));
        $this->assertSame(SIGKILL, pcntl_wtermsig($status));
        DB::purge();
        DB::reconnect();
        $this->assertSame(Operation::ASSIGNED, $request->fresh()->operation_state);
        $task = Enrichment::query()->where('operation_id', $request->operation_id)->sole();
        $assignment = $request->fresh()->getAttributes();
        $this->assertSame($boundary === 'after_commit' ? 0 : 1, $task->attempts);
        $this->assertSame($boundary === 'after_commit' ? 'pending' : 'running', $task->state);
        if ($boundary !== 'after_commit') {
            $this->travel(EnrichmentService::LEASE_SECONDS + 1)->seconds();
        }
        if ($boundary === 'after_details') {
            $this->assertSame(1, NiumVirtualAccountDetail::query()->where('nium_virtual_account_id', $task->nium_virtual_account_id)->sole()->enrichment_version);
        }
        $this->assertSame(1, app(EnrichmentService::class)->recover(1, $request->operation_id));
        Http::fake(fn () => Http::response($this->enrichmentPayload($request)));
        app(EnrichmentService::class)->run($task->id);
        $this->assertSame('complete', $task->fresh()->state);
        $this->assertSame($boundary === 'after_commit' ? 1 : 2, $task->fresh()->attempts);
        $this->assertSame($assignment, $request->fresh()->getAttributes());
        $this->assertSame(1, $request->dispatchAttempt()->count());
        $this->assertSame(1, NiumVirtualAccountDetail::query()->where('nium_virtual_account_id', $task->nium_virtual_account_id)->sole()->enrichment_version);
        Http::assertNotSent(fn ($outgoing): bool => $outgoing->method() !== 'GET');
    }

    public static function enrichmentCrashBoundaries(): array
    {
        return [['after_commit'], ['during_get'], ['after_details']];
    }

    private function enrichmentFixture(bool $beforeResolution = false): array
    {
        config([
            'services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'synthetic-enrichment-key'],
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'synthetic-enrichment-partner-key',
            'services.nium.payment_ids_endpoint' => '/api/v2/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentIds',
        ]);
        $account = $this->ledgerAccount();
        $ledger = app(NiumVirtualAccountSafetyLedger::class);
        $request = $ledger->prepare($account, 'pg-enrichment-'.Str::uuid(), $this->ledgerIdentity($account));
        $ledger->consumeDispatchAuthorization($request, 0);
        $log = $this->ledgerResponseEvidence($request, ['uniquePaymentId' => 'VA-PG-'.Str::uuid()]);
        if ($beforeResolution) {
            return [$request->fresh(), null, $log];
        }
        $request = $ledger->resolveFromPostEvidence($request, 1, $log, attachVirtualAccount: true);

        return [$request, Enrichment::query()->where('operation_id', $request->operation_id)->sole(), $log];
    }

    private function enrichmentPayload(NiumVirtualAccountRequest $request): array
    {
        return ['content' => [[
            'uniquePaymentId' => $request->operation()->value('provider_payment_id'),
            'accountName' => 'Synthetic enrichment holder', 'bankName' => 'DBS_HK',
            'routingCodeType1' => 'SWIFT', 'routingCodeValue1' => 'SYNTHETICXXX',
        ]]];
    }

    private function race(callable $callback): array
    {
        DB::disconnect();
        $workers = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $this->assertNotFalse($sockets);
            $pid = pcntl_fork();
            if ($pid === 0) {
                fclose($sockets[0]);
                foreach ($workers as [, $socket]) {
                    fclose($socket);
                }
                stream_set_timeout($sockets[1], 20);
                try {
                    DB::purge();
                    DB::reconnect();
                    DB::statement("SET lock_timeout = '10s'");
                    fwrite($sockets[1], "ready\n");
                    if (fgets($sockets[1]) !== "go\n") {
                        throw new RuntimeException('Concurrency start barrier failed.');
                    }
                    fwrite($sockets[1], $callback($worker)."\n");
                    DB::disconnect();
                    exit(0);
                } catch (Throwable $exception) {
                    fwrite($sockets[1], 'error: '.$exception->getMessage()."\n");
                    DB::disconnect();
                    exit(1);
                }
            }
            $this->assertGreaterThan(0, $pid);
            fclose($sockets[1]);
            stream_set_timeout($sockets[0], 20);
            $workers[] = [$pid, $sockets[0]];
        }

        foreach ($workers as [, $socket]) {
            $this->assertSame("ready\n", fgets($socket));
        }
        foreach ($workers as [, $socket]) {
            fwrite($socket, "go\n");
        }
        $results = [];
        foreach ($workers as [$pid, $socket]) {
            $result = trim((string) fgets($socket));
            pcntl_waitpid($pid, $status);
            fclose($socket);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), $result);
            $results[] = $result;
        }
        DB::purge();
        DB::reconnect();

        return $results;
    }
}
