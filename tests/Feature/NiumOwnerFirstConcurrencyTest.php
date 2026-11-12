<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\NiumDynamicPayoutOutbox;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutDispatcher;
use App\Services\Nium\DynamicConfirmation\DynamicReservationLedger;
use App\Services\Nium\NiumProviderAccountStateService;
use App\Services\Nium\NiumSubmissionPreflightException;
use App\Services\Nium\NiumTransferPolicy;
use App\Services\Transfers\TransferEligibilityService;
use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Services\Wallet\Concurrency\OwnerLockContractViolation;
use App\Services\Wallet\LedgerService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;
use Tests\Support\NiumOwnerFirstTestCase;
use Tests\Support\PostgresLockRace;

final class NiumOwnerFirstConcurrencyTest extends NiumOwnerFirstTestCase
{
    public static function legacyActions(): array
    {
        $cases = [];
        foreach (['reserve', 'settle', 'release'] as $action) {
            $cases[$action.' legacy first'] = [$action, true];
            $cases[$action.' dynamic first'] = [$action, false];
        }
        return $cases;
    }

    #[DataProvider('legacyActions')]
    public function test_legacy_lifecycle_and_dynamic_reservation_serialize_at_owner(string $action, bool $legacyFirst): void
    {
        $transfer = $this->legacyTransfer();
        if ($action !== 'reserve') app(LedgerService::class)->reserveTransfer($transfer);
        $legacy = fn () => $this->legacyAction($action, $transfer);
        $dynamic = fn () => $this->confirmDynamic()->id;
        $race = $this->orderedRace($legacyFirst ? $legacy : $dynamic, $legacyFirst ? $dynamic : $legacy);
        $this->assertRaceSucceeded($race);
        $ledger = $action === 'settle' ? bcsub(self::OPENING, self::LEGACY, 8) : self::OPENING;
        $reserved = $action === 'reserve' ? bcadd('1000', self::LEGACY, 8) : '1000';
        $this->books(bcsub($ledger, $reserved, 8), $reserved, $ledger);
        $this->assertSame($action === 'reserve' ? 2 : 3, LedgerEntry::query()->count());
    }

    public function test_webhook_waits_before_legacy_balance_and_never_credits_protected_books(): void
    {
        $transfer = $this->legacyTransfer();
        $race = $this->orderedRace(fn () => app(LedgerService::class)->reserveTransfer($transfer)?->id,
            function (): void { $this->funding('9999.99999999'); $this->funding('1.00000001'); });
        $this->assertRaceSucceeded($race);
        $this->books(bcsub(self::OPENING, self::LEGACY, 8), self::LEGACY, self::OPENING);
        $this->assertSame('1.00000001', $this->wallet->fresh()->raw_data['nium_funding_balance_observation']['wallet_balance']);
        $this->assertSame(1, LedgerEntry::query()->count());
    }

    public function test_shared_sync_waits_for_legacy_settlement_and_preserves_dynamic_history(): void
    {
        $this->confirmDynamic();
        $transfer = $this->legacyTransfer();
        app(LedgerService::class)->reserveTransfer($transfer);
        $race = $this->orderedRace(fn () => app(LedgerService::class)->settleTransfer($transfer)?->id,
            fn () => $this->observeNiumBalance('9000.00000001'));
        $this->assertRaceSucceeded($race);
        $ledger = bcsub(self::OPENING, self::LEGACY, 8);
        $this->books(bcsub($ledger, '1000', 8), '1000', $ledger);
        $this->assertSame('9000.00000001', $this->wallet->fresh()->raw_data['provider_available_balance']);
    }

    public function test_account_state_writer_and_shared_sync_wait_at_owner_before_account(): void
    {
        $race = $this->orderedRace(
            fn () => app(NiumProviderAccountStateService::class)->markReconciliationFailure(
                $this->account, 'fixture authoritative read failed', 'patch268c_fixture',
            )->id,
            fn () => $this->observeNiumBalance(self::OPENING),
            'user_provider_accounts',
        );
        $this->assertRaceSucceeded($race);
        $this->assertGreaterThan(0, AuditLog::query()->where('user_id', $this->owner->id)->count());
        $this->books(self::OPENING, '0', self::OPENING);
    }

    public function test_nested_transfer_first_call_is_rejected_without_adding_a_user_lock(): void
    {
        $transfer = $this->legacyTransfer();
        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            if (str_contains($query->sql, 'for update')) $locks[] = $query->sql;
        });
        try {
            DB::transaction(function () use ($transfer): void {
                Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
                app(LedgerService::class)->reserveTransfer($transfer);
            });
            $this->fail('A T-first transaction was adopted.');
        } catch (OwnerLockContractViolation) {
            $this->assertCount(1, $locks);
            $this->assertStringContainsString('transfers', $locks[0]);
        }
        $this->books(self::OPENING, '0', self::OPENING);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_retry_after_released_hold_cannot_reserve_or_submit_again(): void
    {
        $transfer = $this->legacyTransfer();
        $ledger = app(LedgerService::class);
        $ledger->reserveTransfer($transfer);
        $ledger->releaseTransferHold($transfer);
        try {
            $ledger->reserveTransfer($transfer);
            $this->fail('A released hold was reused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cannot be reused', $exception->getMessage());
        }
        $this->books(self::OPENING, '0', self::OPENING);
        $this->assertSame(2, LedgerEntry::query()->count());
    }

    public static function creatorPreflightOutcomes(): array
    {
        return ['duplicate submit' => [false], 'creator cleanup after duplicate claim' => [true]];
    }

    #[DataProvider('creatorPreflightOutcomes')]
    public function test_duplicate_submission_and_creator_cleanup_never_release_a_competing_claim(bool $failCreator): void
    {
        $transfer = $this->legacyTransfer();
        // Only the provider-submission fixture needs a policy-valid reference.
        $transfer->update(['source_amount' => '500', 'reference_text' => 'PATCH270B fixture invoice']);
        $this->owner->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        $this->owner->kycProfile()->update(['applicant_type' => 'business', 'registered_country_code' => 'HK',
            'country_code' => 'HK', 'business_name' => 'Fixture HK Customer', 'metadata' => ['nium_region' => 'HK']]);
        $this->beneficiary->update(['beneficiary_type' => 'business', 'country_code' => 'HK', 'currency' => 'USD',
            'payout_method' => 'SWIFT', 'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT']]]);

        // Local validation only: the creator worker must still INSERT the first hold.
        $candidate = $transfer->fresh(['provider', 'user.kycProfile', 'beneficiary', 'sourceBankAccount', 'fxQuote']);
        app(TransferEligibilityService::class)->ensureTransferCanBeSubmitted($candidate);
        app(NiumTransferPolicy::class)->assertBeforeFxLock($candidate, [['code' => 'IR01811', 'label' => 'General Goods']]);
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->books(self::OPENING, '0', self::OPENING);
        Cache::forget('nium:purpose-codes');

        $operation = function (int $worker, PostgresLockRace $race) use ($transfer, $failCreator): string {
            try {
                // Cache::remember uses this exact key. Each worker must enter GET,
                // including when a preceding test or inherited array store populated it.
                Cache::forget('nium:purpose-codes');
                $this->app->instance(LedgerService::class, $this->tracedSubmissionLedger($race, $worker));
                $nium = $this->partialMock(\App\Services\Nium\NiumService::class);
                $response = static fn (array $body, int $status = 200) => new \Illuminate\Http\Client\Response(
                    new \GuzzleHttp\Psr7\Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)),
                );
                $getCalls = 0;
                $nium->shouldReceive('get')->once()->andReturnUsing(function (string $path, array $query = [],
                    ?User $user = null, ?string $operation = null, ?string $externalReference = null) use ($worker, $race, $failCreator, $response, &$getCalls) {
                    OwnerFirst::outside();
                    $this->assertSame('purpose_codes_list', $operation);
                    $race->signal('purpose-get-'.$worker, ['count' => ++$getCalls]);
                    if ($worker === 0) {
                        $race->signal('creator-preflight');
                        $race->await('wire-entered', 1);
                        $race->await('claim-observed');
                        $race->signal('creator-purpose-result', ['status' => $failCreator ? 503 : 200]);
                        if ($failCreator) return $response(['message' => 'Fixture purpose lookup unavailable'], 503);
                    }
                    return $response([['description' => 'General Goods', 'purposeCode' => 'IR01811']]);
                });
                if ($worker === 1) {
                    $nium->shouldReceive('post')->once()->andReturnUsing(function (string $path, array $payload = [],
                        ?User $user = null, ?int $relatedTransferId = null, ?string $operation = null,
                        ?string $externalReference = null, ?\Closure $beforeDispatch = null) use ($race, $transfer, $response) {
                        OwnerFirst::outside();
                        $claim = $transfer->fresh();
                        $this->assertSame('transfer_money', $operation);
                        $this->assertSame($transfer->id, $relatedTransferId);
                        $this->assertSame('submitting', $claim->status);
                        $this->assertIsString($claim->provider_operation_key);
                        $this->assertNotSame('', $claim->provider_operation_key);
                        $this->assertSame($claim->provider_operation_key, $externalReference);
                        $race->phase('post-in-flight');
                        $race->recordWire($claim->provider_operation_key);
                        $race->signal('wire-entered', ['key' => $claim->provider_operation_key]);
                        $race->await('creator-done', 0);
                        $cleanup = $race->await('cleanup-finished-0', 0);
                        $this->assertSame(1, $cleanup['count']);
                        $this->assertNull($cleanup['release_id']);
                        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
                        return $response(['systemReferenceNumber' => 'RT-PATCH268C']);
                    });
                } else {
                    $nium->shouldReceive('post')->never();
                }
                return app(\App\Services\Integrations\ProviderTransferManager::class)
                    ->submitTransfer($this->account->provider, $transfer)->status;
            } finally {
                // The manager's real cleanup has returned before this signal.
                if ($worker === 0) $race->signal('creator-done');
            }
        };
        $harness = new PostgresLockRace;
        $observedClaim = null;
        $race = $harness->run(
            function (PostgresLockRace $race) use ($operation): string {
                $race->pauseAfterLock('balances');
                return $operation(0, $race);
            },
            function (PostgresLockRace $race) use ($operation): string {
                $race->await('locked', 0);
                // Real contention at U while the creator still holds B.
                OwnerFirst::users([$this->owner->id], fn () => null);
                $race->await('creator-preflight', 0);
                return $operation(1, $race);
            },
            function (PostgresLockRace $race, Connection $observer, array $pids) use ($transfer, &$observedClaim): void {
                $race->await('locked', 0);
                $wait = $race->waitForBlock($observer, $pids[1], $pids[0]);
                $this->assertStringContainsString('users', $wait['query']);
                $race->signal('continue');
                $wire = $race->await('wire-entered', 1);
                // This third session cannot see an uncommitted claim. No financial
                // locks are taken by the observer, and the creator remains paused.
                $this->assertSame(0, $observer->transactionLevel());
                $this->assertFalse($observer->getPdo()->inTransaction());
                $observedClaim = $observer->selectOne('SELECT status, provider_operation_key FROM transfers WHERE id = ?', [$transfer->id], false);
                $this->assertSame('submitting', $observedClaim->status);
                $this->assertNotSame('', (string) $observedClaim->provider_operation_key);
                $this->assertSame($wire['key'], $observedClaim->provider_operation_key);
                $held = $observer->selectOne('SELECT amount FROM ledger_entries WHERE reference = ?', ['transfer:'.$transfer->id.':hold'], false);
                $this->assertSame('-500.00000000', $held->amount);
                $race->signal('claim-observed', ['key' => $observedClaim->provider_operation_key]);
            },
        );
        $diagnostics = json_encode($race, JSON_THROW_ON_ERROR);
        $this->assertSame('error', $race['workers'][0]['status'], $diagnostics);
        $this->assertSame(NiumSubmissionPreflightException::class, $race['workers'][0]['class'], $diagnostics);
        $this->assertSame($failCreator ? 'Nium purpose codes are temporarily unavailable.'
            : 'Transfer has already entered provider submission and cannot acquire a payout FX lock.', $race['workers'][0]['message'], $diagnostics);
        $this->assertStringNotContainsString('Barrier timeout', $race['workers'][0]['message']);
        $this->assertSame('ok', $race['workers'][1]['status'], $diagnostics);
        $this->assertSame('pending', $race['workers'][1]['value'], $diagnostics);
        $this->assertCount(3, array_unique($race['pids']));
        foreach ($race['workers'] as $worker => $result) {
            $this->assertSame(0, $result['depth'], $diagnostics);
            $this->assertFalse($result['pdo_transaction'], $diagnostics);
            $this->assertArrayNotHasKey('reporting_failures', $result, $diagnostics);
            $this->assertSame(0, $race['exits'][$worker]['exit_code'], $diagnostics);
            $this->assertNull($race['exits'][$worker]['signal'], $diagnostics);
            $this->assertSame(1, $harness->await('purpose-get-'.$worker)['count']);
        }
        foreach ($race['sql'] as $queries) $this->assertSame([], array_values(array_filter(array_column($queries, 'sqlstate'))), $diagnostics);
        $this->assertSame(['status' => $failCreator ? 503 : 200], $harness->await('creator-purpose-result'));
        $created = $harness->await('hold-0');
        $duplicate = $harness->await('hold-1');
        $this->assertTrue($created['created']);
        $this->assertFalse($duplicate['created']);
        $this->assertSame($created['id'], $duplicate['id']);
        $cleanup = $harness->await('cleanup-started-0');
        $this->assertSame(1, $cleanup['count']);
        $this->assertSame($created['id'], $cleanup['hold_id']);
        $this->assertTrue($cleanup['created']);
        $this->assertSame('submitting', $cleanup['status']);
        $this->assertSame($observedClaim->provider_operation_key, $cleanup['key']);
        $this->assertNull($cleanup['no_wire_key']);
        $this->assertSame(['count' => 1, 'release_id' => null], $harness->await('cleanup-finished-0'));
        $this->assertFileDoesNotExist($harness->artifactDirectory().'/cleanup-started-1');
        $this->assertFileDoesNotExist($harness->artifactDirectory().'/cleanup-error-0');
        $this->assertCount(1, $race['wire']);
        $this->assertSame($observedClaim->provider_operation_key, $race['wire'][0]['key']);
        $this->assertSame($race['workers'][1]['pid'], $race['wire'][0]['pid']);
        $this->assertSame(0, $race['wire'][0]['depth']);
        $this->assertFalse($race['wire'][0]['pdo_transaction']);
        $this->assertSame('pending', $transfer->fresh()->status);
        $this->assertSame($observedClaim->provider_operation_key, $transfer->fresh()->provider_operation_key);
        $this->assertSame(1, LedgerEntry::query()->count());
        $hold = LedgerEntry::query()->sole();
        $this->assertSame($created['id'], $hold->id);
        $this->assertSame('transfer', $hold->source_type);
        $this->assertSame((string) $transfer->id, $hold->source_id);
        $this->assertSame('transfer:'.$transfer->id.':hold', $hold->reference);
        $this->assertSame('hold', $hold->entry_type);
        $this->assertSame('posted', $hold->status);
        $this->assertSame('-500.00000000', $hold->amount);
        $this->assertSame('2500.12345678', $hold->balance_after);
        $this->assertSame('fixture-wallet', $hold->raw_data['wallet_id']);
        $walletReceipt = $transfer->fresh()->raw_data['wallet'];
        $this->assertSame((string) $this->wallet->id, (string) $walletReceipt['balance_id']);
        $this->assertSame($hold->reference, $walletReceipt['hold_reference']);
        $this->assertSame('500.00000000', $walletReceipt['held_amount']);
        $this->assertSame(0, LedgerEntry::query()->whereIn('entry_type', ['release', 'debit', 'credit'])->count());
        $this->books('2500.12345678', '500.00000000', '3000.12345678');
    }

    /** Trace calls without replacing any reservation, lock, claim or cleanup decision. */
    private function tracedSubmissionLedger(PostgresLockRace $race, int $worker): LedgerService
    {
        return new class($race, $worker) extends LedgerService {
            private int $cleanupCalls = 0;

            public function __construct(private PostgresLockRace $race, private int $worker) {}

            public function reserveTransfer(Transfer $transfer): ?LedgerEntry
            {
                $this->race->phase('reserve');
                $hold = parent::reserveTransfer($transfer);
                $this->race->signal('hold-'.$this->worker, ['id' => $hold?->id, 'created' => $hold?->wasRecentlyCreated]);
                return $hold;
            }

            public function releaseOwnedTransferHoldBeforeWire(Transfer $transfer, LedgerEntry $createdHold,
                ?string $noWireOperationKey = null): ?LedgerEntry
            {
                $this->race->phase('creator-cleanup');
                $this->race->signal('cleanup-started-'.$this->worker, ['count' => ++$this->cleanupCalls,
                    'hold_id' => $createdHold->id, 'created' => $createdHold->wasRecentlyCreated,
                    'status' => $transfer->status, 'key' => $transfer->provider_operation_key, 'no_wire_key' => $noWireOperationKey]);
                try {
                    $entry = parent::releaseOwnedTransferHoldBeforeWire($transfer, $createdHold, $noWireOperationKey);
                    $this->race->signal('cleanup-finished-'.$this->worker, ['count' => $this->cleanupCalls, 'release_id' => $entry?->id]);
                    return $entry;
                } catch (Throwable $exception) {
                    $this->race->signal('cleanup-error-'.$this->worker, ['class' => $exception::class, 'message' => $exception->getMessage()]);
                    throw $exception;
                }
            }
        };
    }

    public function test_peer_failure_is_reported_without_waiting_for_its_missing_barrier(): void
    {
        $race = (new PostgresLockRace)->run(
            fn (PostgresLockRace $race) => $race->await('never-published', 1),
            static function (): void { throw new RuntimeException('fixture-peer-original-failure'); },
            static function (): void {},
        );
        $this->assertSame(['error', 'error'], array_column($race['workers'], 'status'), json_encode($race));
        $this->assertSame('fixture-peer-original-failure', $race['workers'][1]['message']);
        $this->assertStringContainsString('fixture-peer-original-failure', $race['workers'][0]['message']);
        $this->assertStringNotContainsString('Barrier timeout', $race['workers'][0]['message']);
        $this->assertSame('await:never-published', $race['workers'][0]['phase']);
        $this->assertFileExists($race['artifacts'].'/result-1');
        $this->assertFileExists($race['artifacts'].'/sql-1');
        $this->assertFileExists($race['artifacts'].'/phases-1');
        $this->assertFileExists($race['artifacts'].'/diagnostics');
    }

    public function test_exit_without_a_result_reports_worker_exit_status_and_retains_diagnostics(): void
    {
        $harness = new PostgresLockRace;
        try {
            $harness->run(
                fn (PostgresLockRace $race) => $race->await('never-published', 1),
                static function (): void { exit(23); },
                static function (): void {},
            );
            $this->fail('A child exit without a result was hidden.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Worker 1 exited before publishing result', $exception->getMessage());
            $diagnostics = json_decode(file_get_contents($harness->artifactDirectory().'/diagnostics'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(23, $diagnostics['exits'][1]['exit_code']);
            $this->assertFileExists($harness->artifactDirectory().'/sql-1');
            $this->assertFileExists($harness->artifactDirectory().'/phases-1');
        }
    }

    public function test_unserializable_worker_result_is_reported_as_a_failure(): void
    {
        $race = (new PostgresLockRace)->run(
            static fn () => fopen('php://memory', 'w+'),
            static fn () => 'peer completed',
            static function (): void {},
        );
        $this->assertSame('error', $race['workers'][0]['status'], json_encode($race));
        $this->assertSame(\JsonException::class, $race['workers'][0]['class']);
        $this->assertSame('ok', $race['workers'][1]['status']);
        $this->assertArrayNotHasKey('value', $race['workers'][0]);
        foreach ($race['workers'] as $worker => $result) {
            $this->assertSame(0, $result['depth']);
            $this->assertFalse($result['pdo_transaction']);
            $this->assertSame(0, $race['exits'][$worker]['exit_code']);
        }
        $this->assertFileExists($race['artifacts'].'/result-0');
        $this->assertFileExists($race['artifacts'].'/diagnostics');
    }

    public function test_dynamic_local_execution_validation_and_legacy_settlement_keep_dispatch_off(): void
    {
        $this->source->rule = 'LOCAL_SINGLE_INTENT_PER_QUOTE';
        $this->source->versions['remittance_instruction'] = 'fixture-purpose-codes-v1';
        $this->source->review = $this->usdReview(changes: ['instruction' => new \App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutInstruction('IR005', 'Salary', 'Fixture invoice')]);
        $intent = $this->confirmDynamic();
        $outbox = NiumDynamicPayoutOutbox::query()->sole();
        $transfer = $this->legacyTransfer();
        app(LedgerService::class)->reserveTransfer($transfer);
        $dispatcher = new DynamicPayoutDispatcher($this->confirmation, new \Tests\Support\DynamicPayoutTransportStub);
        $race = $this->orderedRace(fn () => app(LedgerService::class)->settleTransfer($transfer)?->id,
            fn () => $dispatcher->locked($outbox->id, function ($intent, $current, $reservation) use ($dispatcher): void {
                $this->confirmation->revalidateForExecution($intent);
                (new \ReflectionMethod(DynamicPayoutDispatcher::class, 'assertReservation'))->invoke($dispatcher, $intent, $current, $reservation);
            }));
        $this->assertRaceSucceeded($race);
        $this->assertFalse((bool) config('nium_dynamic_confirmation.automatic_dispatch_enabled'));
        $this->assertFalse($outbox->fresh()->dispatchable);
        $this->assertSame(0, $outbox->fresh()->attempts);
        $ledger = bcsub(self::OPENING, self::LEGACY, 8);
        $this->books(bcsub($ledger, '1000', 8), '1000', $ledger);
    }

    public static function oldCycles(): array
    {
        return ['balance versus user' => ['balances'], 'account versus user' => ['user_provider_accounts']];
    }

    #[DataProvider('oldCycles')]
    public function test_control_reproduces_old_fk_cycle_and_records_40p01(string $table): void
    {
        // Deliberate old-order SQL control, never a production/context bypass.
        $race = (new PostgresLockRace)->run(
            function (PostgresLockRace $race) use ($table): void {
                DB::transaction(function () use ($race, $table): void {
                    DB::table($table)->where('id', $table === 'balances' ? $this->wallet->id : $this->account->id)->lockForUpdate()->first();
                    $race->signal('old-first');
                    $race->await('close-cycle');
                    if ($table === 'balances') {
                        LedgerEntry::query()->create(['balance_id' => $this->wallet->id, 'user_id' => $this->owner->id,
                            'provider_id' => $this->account->provider_id, 'reference' => 'old-cycle-control',
                            'entry_type' => 'hold', 'status' => 'posted', 'currency' => 'USD', 'amount' => '-1',
                            'source_type' => 'lock_control', 'source_id' => 'fixture']);
                    } else {
                        AuditLog::query()->create(['user_id' => $this->owner->id, 'action' => 'lock_control',
                            'entity_type' => 'fixture', 'entity_id' => 'fixture']);
                    }
                    throw new RuntimeException('Control rollback after winning the injected cycle.');
                });
            },
            function (PostgresLockRace $race) use ($table): void {
                $race->await('old-first');
                DB::transaction(function () use ($race, $table): void {
                    User::query()->whereKey($this->owner->id)->lockForUpdate()->firstOrFail();
                    $race->signal('old-user');
                    DB::table($table)->where('id', $table === 'balances' ? $this->wallet->id : $this->account->id)->lockForUpdate()->first();
                });
            },
            function (PostgresLockRace $race, Connection $observer, array $pids): void {
                $race->await('old-user');
                $race->waitForBlock($observer, $pids[1], $pids[0]);
                $race->signal('close-cycle');
            },
        );
        $states = array_merge(array_column($race['sql'][0], 'sqlstate'), array_column($race['sql'][1], 'sqlstate'));
        $this->assertContains('40P01', $states, json_encode($race));
        $this->assertNotContains('55P03', $states);
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->books(self::OPENING, '0', self::OPENING);
    }

    public static function retryFaults(): array
    {
        return ['deadlock fault' => ['40P01'], 'serialization fault' => ['40001']];
    }

    #[DataProvider('retryFaults')]
    public function test_recording_exposes_failed_local_attempt_even_when_retry_succeeds(string $state): void
    {
        $transfer = $this->legacyTransfer();
        $race = $this->orderedRace(function () use ($transfer, $state): array {
            $attempts = 0;
            $contexts = [];
            OwnerFirst::users([$this->owner->id], function ($context) use ($transfer, $state, &$attempts, &$contexts): void {
                $contexts[] = $context;
                app(LedgerService::class)->reserveTransfer($transfer);
                if (++$attempts === 1) {
                    DB::statement("DO $$ BEGIN RAISE EXCEPTION 'injected local fault' USING ERRCODE = '$state'; END $$");
                }
            }, 3);
            return ['attempts' => $attempts, 'fresh_context' => $contexts[0] !== $contexts[1]];
        }, fn () => $this->confirmDynamic()->id);
        $this->assertSame(['ok', 'ok'], array_column($race['workers'], 'status'), json_encode($race));
        $this->assertSame(['attempts' => 2, 'fresh_context' => true], $race['workers'][0]['value']);
        $failures = array_values(array_filter($race['sql'][0], fn ($event) => $event['sqlstate'] !== null));
        $this->assertCount(1, $failures, json_encode($race));
        $this->assertSame($state, $failures[0]['sqlstate']);
        $this->assertSame(1, $failures[0]['attempt']);
        $this->assertSame([], array_values(array_filter(array_column($race['sql'][1], 'sqlstate'))));
        foreach ($race['workers'] as $worker) {
            $this->assertSame(0, $worker['depth']);
            $this->assertFalse($worker['pdo_transaction']);
        }
        $this->assertSame(2, LedgerEntry::query()->count());
        $reserved = bcadd('1000', self::LEGACY, 8);
        $this->books(bcsub(self::OPENING, $reserved, 8), $reserved, self::OPENING);
    }

    public function test_real_lock_timeout_is_reported_once_without_implicit_retry(): void
    {
        $race = (new PostgresLockRace)->run(
            function (PostgresLockRace $race): void {
                $race->pauseAfterLock('users');
                OwnerFirst::users([$this->owner->id], fn () => null);
            },
            function (PostgresLockRace $race): void {
                $race->await('locked');
                DB::statement("SET lock_timeout = '500ms'");
                try {
                    OwnerFirst::users([$this->owner->id], fn () => null, 3);
                } finally { $race->signal('timed-out'); }
            },
            function (PostgresLockRace $race, Connection $observer, array $pids): void {
                $race->await('locked');
                $race->waitForBlock($observer, $pids[1], $pids[0]);
                $race->await('timed-out');
                $race->signal('continue');
            },
        );
        $this->assertSame('55P03', $race['workers'][1]['sqlstate'], json_encode($race));
        $failed = array_values(array_filter($race['sql'][1], fn ($event) => $event['sqlstate'] !== null));
        $this->assertCount(1, $failed);
        $this->assertSame(1, $failed[0]['attempt']);
        $this->assertSame(0, $race['workers'][1]['depth']);
    }

    private function legacyAction(string $action, Transfer $transfer): ?int
    {
        $ledger = app(LedgerService::class);
        return match ($action) {
            'reserve' => $ledger->reserveTransfer($transfer)?->id,
            'settle' => $ledger->settleTransfer($transfer)?->id,
            'release' => $ledger->releaseTransferHold($transfer)?->id,
        };
    }
}
