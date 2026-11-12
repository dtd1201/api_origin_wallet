<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Models\LedgerEntry;
use App\Models\NiumDynamicReservation;
use App\Models\Transfer;
use App\Services\Nium\DynamicConfirmation\DynamicReservationLedger;
use App\Services\Nium\NiumWebhookService;
use App\Services\Wallet\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Concerns\CreatesFinalizedUsdConfirmationFixtures;
use Tests\Concerns\CreatesNiumBalanceSyncObservation;
use Tests\Support\DynamicConfirmationDatabaseTestCase;
use Throwable;

/** Committed local PostgreSQL books; no provider HTTP or automatic dispatch. */
final class NiumFundingWebhookOwnershipTest extends DynamicConfirmationDatabaseTestCase
{
    use CreatesDynamicConfirmationFixtures;
    use CreatesFinalizedUsdConfirmationFixtures;
    use CreatesNiumBalanceSyncObservation;

    private const OPENING = '3000.12345678';
    private const HOLD = '10.00000001';
    private const ANCHOR = '0.12345678';
    private const PROVIDER_TOTAL = '3500.87654321';

    protected function setUp(): void
    {
        parent::setUp();
        config(['wallet.ledger.enabled' => true]);
        $this->createDynamicFixtures(self::OPENING);
        $this->wallet->update(['currency' => 'USD']);
        $this->source->review = $this->usdReview();
    }

    public static function providerTotals(): array
    {
        return [
            'increase' => [self::PROVIDER_TOTAL],
            'decrease' => ['2500.00000009'],
        ];
    }

    #[DataProvider('providerTotals')]
    public function test_legacy_hold_keeps_exact_books_and_observes_repeated_provider_totals(string $total): void
    {
        $transfer = $this->transfer('active');
        $ledger = app(LedgerService::class);
        $ledger->reserveTransfer($transfer);
        $this->assertSame(0, NiumDynamicReservation::query()->count());
        $wallet = $this->wallet->fresh();
        $raw = $wallet->raw_data;
        $raw['dynamic_authenticated_availability'] = ['stale' => true];
        $raw['unrelated_metadata'] = 'keep';
        $wallet->update(['raw_data' => $raw]);
        $asOf = $wallet->as_of->toISOString();
        $before = $this->journalSnapshot();
        $this->travelTo(now()->addMinute());

        $this->funding($total);
        $this->funding($total);

        $this->books(bcsub(self::OPENING, self::HOLD, 8), self::HOLD, self::OPENING);
        $wallet = $this->wallet->fresh();
        $this->assertSame($total, $wallet->raw_data['nium_funding_balance_observation']['wallet_balance']);
        $this->assertNotEmpty($wallet->raw_data['nium_funding_balance_observation']['observed_at']);
        $this->assertSame(self::OPENING, $wallet->raw_data['provider_available_balance']);
        $this->assertSame('keep', $wallet->raw_data['unrelated_metadata']);
        $this->assertArrayNotHasKey('dynamic_authenticated_availability', $wallet->raw_data);
        $this->assertSame($asOf, $wallet->as_of->toISOString());
        $this->assertSame($before, $this->journalSnapshot());
        $this->assertSame(0, LedgerEntry::query()->whereIn('entry_type', ['credit', 'debit'])->count());

        // Replaying the hold cannot create another reservation after observation.
        $ledger->reserveTransfer($transfer);
        $this->assertSame($before, $this->journalSnapshot());
    }

    public function test_legacy_no_hold_no_dynamic_wallet_still_mirrors_without_journals(): void
    {
        $this->funding(self::PROVIDER_TOTAL);
        $this->funding(self::PROVIDER_TOTAL);
        $this->books(self::PROVIDER_TOTAL, '0', self::PROVIDER_TOTAL);
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertArrayNotHasKey('nium_funding_balance_observation', $this->wallet->fresh()->raw_data);
    }

    public function test_first_wallet_funding_still_creates_the_legacy_mirror(): void
    {
        $this->wallet->delete();
        $this->funding(self::PROVIDER_TOTAL);
        $this->wallet = $this->owner->balances()->sole();
        $this->books(self::PROVIDER_TOTAL, '0', self::PROVIDER_TOTAL);
        $this->assertSame('fixture-wallet', $this->wallet->external_account_id);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public static function terminalActions(): array
    {
        return ['released' => ['release'], 'settled' => ['settle']];
    }

    #[DataProvider('terminalActions')]
    public function test_completed_legacy_history_without_holds_keeps_existing_mirror_policy(string $action): void
    {
        $transfer = $this->transfer('terminal');
        $ledger = app(LedgerService::class);
        $ledger->reserveTransfer($transfer);
        $this->legacyAction($action, $transfer);
        $before = $this->journalSnapshot();

        $this->funding(self::PROVIDER_TOTAL);
        $this->funding(self::PROVIDER_TOTAL);

        $this->books(self::PROVIDER_TOTAL, '0', self::PROVIDER_TOTAL);
        $this->assertSame($before, $this->journalSnapshot());
        $this->assertCount(2, $before);
    }

    #[DataProvider('terminalActions')]
    public function test_dynamic_historical_books_remain_protected_with_zero_reserved(string $action): void
    {
        $intent = $this->confirmDynamic();
        if ($action === 'release') {
            $this->confirmation->cancel($this->owner, $intent->id);
            $expected = self::OPENING;
        } else {
            OwnerFirst::users([$this->owner->id], function ($context) use ($intent): void {
                $intent = \App\Models\NiumDynamicIntent::query()->lockForUpdate()->findOrFail($intent->id);
                $reservation = NiumDynamicReservation::query()->lockForUpdate()->sole();
                $ledger = new DynamicReservationLedger;
                $ledger->consume($intent, $reservation, $this->wallet, $context);
                $ledger->consume($intent, $reservation->fresh(), $this->wallet, $context);
            });
            $expected = bcsub(self::OPENING, (string) $intent->total_debit, 8);
        }
        $before = $this->journalSnapshot();
        $this->books($expected, '0', $expected);

        $this->funding(self::PROVIDER_TOTAL);
        $this->funding('2500.00000009');
        $this->observeNiumBalance(self::PROVIDER_TOTAL);

        $this->books($expected, '0', $expected);
        $this->assertSame($before, $this->journalSnapshot());
        $this->assertCount(2, $before);
        $this->assertSame('2500.00000009', $this->wallet->fresh()->raw_data['nium_funding_balance_observation']['wallet_balance']);
    }

    #[DataProvider('providerTotals')]
    public function test_shared_sync_and_funding_observations_preserve_the_same_legacy_hold(string $total): void
    {
        app(LedgerService::class)->reserveTransfer($this->transfer('sync'));
        $before = $this->journalSnapshot();

        $this->observeNiumBalance($total);
        $this->funding(self::PROVIDER_TOTAL);
        $this->observeNiumBalance($total);

        $this->books(bcsub(self::OPENING, self::HOLD, 8), self::HOLD, self::OPENING);
        $raw = $this->wallet->fresh()->raw_data;
        $this->assertSame($total, $raw['provider_available_balance']);
        $this->assertSame($total, $raw['provider_ledger_balance']);
        $this->assertNotEmpty($raw['provider_balance_observed_at']);
        $this->assertNull($raw['provider_balance_as_of']);
        $this->assertSame(self::PROVIDER_TOTAL, $raw['nium_funding_balance_observation']['wallet_balance']);
        $this->assertSame($before, $this->journalSnapshot());
    }

    public static function lifecycleLockOrders(): array
    {
        $cases = [];
        foreach (['reserve', 'release', 'settle'] as $action) {
            foreach ([false, true] as $webhookFirst) {
                $cases[$action.' / '.($webhookFirst ? 'webhook first' : 'lifecycle first')] = [$action, $webhookFirst];
            }
        }

        return $cases;
    }

    #[DataProvider('lifecycleLockOrders')]
    public function test_postgres_serializes_legacy_lifecycle_and_webhook_with_an_active_hold(string $action, bool $webhookFirst): void
    {
        // Keep one legacy hold active in both orders, including after release or
        // settle. No Dynamic journal may accidentally mask the legacy regression.
        app(LedgerService::class)->reserveTransfer($this->transfer('anchor', self::ANCHOR));
        $transfer = $this->transfer('racing');
        if ($action !== 'reserve') {
            app(LedgerService::class)->reserveTransfer($transfer);
        }

        $this->race($action, $transfer, $webhookFirst);

        $reserved = $action === 'reserve' ? bcadd(self::ANCHOR, self::HOLD, 8) : self::ANCHOR;
        $ledger = $action === 'settle' ? bcsub(self::OPENING, self::HOLD, 8) : self::OPENING;
        $this->books(bcsub($ledger, $reserved, 8), $reserved, $ledger);
        $before = $this->journalSnapshot();
        $this->funding(self::PROVIDER_TOTAL);
        $this->legacyAction($action, $transfer); // Idempotent lifecycle replay.
        $this->books(bcsub($ledger, $reserved, 8), $reserved, $ledger);
        $this->assertSame($before, $this->journalSnapshot());
        $this->assertCount($action === 'reserve' ? 2 : 3, $before);
        $this->assertSame($action === 'settle' ? 1 : 0, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'credit')->count());
        $this->assertSame(0, NiumDynamicReservation::query()->count());
        $this->assertSame(self::PROVIDER_TOTAL, $this->wallet->fresh()->raw_data['nium_funding_balance_observation']['wallet_balance']);
    }

    #[DataProvider('lifecycleLockOrders')]
    public function test_postgres_last_legacy_hold_transition_obeys_ownership_at_the_balance_lock(string $action, bool $webhookFirst): void
    {
        $transfer = $this->transfer('last-hold');
        if ($action !== 'reserve') {
            app(LedgerService::class)->reserveTransfer($transfer);
        }

        $this->race($action, $transfer, $webhookFirst);

        // With no active hold and no Dynamic history at its lock, the webhook
        // retains the legacy mirror contract, including after a terminal journal.
        $mirrored = $action === 'reserve' ? $webhookFirst : ! $webhookFirst;
        $ledger = $mirrored ? self::PROVIDER_TOTAL
            : ($action === 'settle' ? bcsub(self::OPENING, self::HOLD, 8) : self::OPENING);
        $reserved = $action === 'reserve' ? self::HOLD : '0';
        $this->books(bcsub($ledger, $reserved, 8), $reserved, $ledger);
        $this->assertSame($action === 'reserve' ? 1 : 2, LedgerEntry::query()->count());
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'credit')->count());
        $this->assertSame($action === 'settle' ? 1 : 0, LedgerEntry::query()->where('entry_type', 'debit')->count());
        if ($mirrored) {
            $this->assertArrayNotHasKey('nium_funding_balance_observation', $this->wallet->fresh()->raw_data);
        } else {
            $this->assertSame(self::PROVIDER_TOTAL, $this->wallet->fresh()->raw_data['nium_funding_balance_observation']['wallet_balance']);
        }
    }

    private function funding(string $total): void
    {
        // Isolate the accounting handler. Authenticated HTTP routing is exercised
        // by NiumWebhookApiTest; these races use the real database, not lock mocks.
        (new ReflectionMethod(NiumWebhookService::class, 'processFundingWebhook'))->invoke(
            app(NiumWebhookService::class), $this->account->provider, [
                'walletHashId' => 'fixture-wallet', 'transactionCurrency' => 'USD', 'walletBalance' => $total,
            ],
        );
    }

    private function transfer(string $reference, string $amount = self::HOLD): Transfer
    {
        return Transfer::query()->create([
            'transfer_no' => 'PATCH260-'.$reference, 'user_id' => $this->owner->id,
            'provider_id' => $this->account->provider_id, 'beneficiary_id' => $this->beneficiary->id,
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'USD',
            'source_amount' => $amount, 'fee_amount' => '0', 'fee_currency' => 'USD', 'status' => 'draft',
        ]);
    }

    private function legacyAction(string $action, Transfer $transfer): void
    {
        $ledger = app(LedgerService::class);
        match ($action) {
            'reserve' => $ledger->reserveTransfer($transfer),
            'release' => $ledger->releaseTransferHold($transfer),
            'settle' => $ledger->settleTransfer($transfer),
        };
    }

    private function journalSnapshot(): array
    {
        return LedgerEntry::query()->orderBy('id')->get()->map(fn (LedgerEntry $entry) => $entry->getRawOriginal())->all();
    }

    private function books(string $available, string $reserved, string $ledger): void
    {
        $wallet = $this->wallet->fresh();
        $this->assertSame(bcadd($available, '0', 8), $wallet->available_balance);
        $this->assertSame(bcadd($reserved, '0', 8), $wallet->reserved_balance);
        $this->assertSame(bcadd($ledger, '0', 8), $wallet->ledger_balance);
        $this->assertSame($wallet->ledger_balance, bcadd($wallet->available_balance, $wallet->reserved_balance, 8));
    }

    /** Force and verify both lock acquisition orders on independent PostgreSQL connections. */
    private function race(string $action, Transfer $transfer, bool $webhookFirst): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertTrue(function_exists('pcntl_fork'), 'PATCH 260 PostgreSQL races require pcntl.');
        $directory = tempnam(sys_get_temp_dir(), 'patch260-');
        unlink($directory);
        mkdir($directory, 0700);
        $children = [];
        DB::disconnect();
        try {
            foreach ([0, 1] as $worker) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    try {
                        DB::purge();
                        DB::reconnect();
                        DB::statement("SET lock_timeout = '10s'");
                        DB::statement("SET statement_timeout = '15s'");
                        $backend = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
                        if ($worker === 1) {
                            $this->awaitFile($directory.'/first-holds-locks');
                            file_put_contents($directory.'/waiter-pid.tmp', (string) $backend);
                            rename($directory.'/waiter-pid.tmp', $directory.'/waiter-pid');
                        }
                        OwnerFirst::users([$this->owner->id], function () use ($worker, $directory, $backend, $action, $transfer, $webhookFirst): void {
                            if (($worker === 0) === $webhookFirst) {
                                $this->funding(self::PROVIDER_TOTAL);
                            } else {
                                $this->legacyAction($action, $transfer);
                            }
                            if ($worker === 0) {
                                // The service has finished; its locks remain held
                                // by this outer transaction while the other enters.
                                file_put_contents($directory.'/first-holds-locks', 'ready');
                                $this->awaitFile($directory.'/waiter-pid');
                                $waiter = (int) file_get_contents($directory.'/waiter-pid');
                                $deadline = microtime(true) + 5;
                                do {
                                    // Activity snapshots can otherwise stay cached
                                    // throughout this holding transaction.
                                    DB::selectOne('SELECT pg_stat_clear_snapshot()');
                                    $blocked = DB::selectOne(
                                        "SELECT COUNT(*) AS total FROM pg_stat_activity WHERE pid = ? AND ? = ANY(pg_blocking_pids(pid)) AND wait_event_type = 'Lock' AND (query ILIKE '%balances%for update%' OR query ILIKE '%users%for update%')",
                                        [$waiter, $backend],
                                    );
                                    if ((int) $blocked->total === 1) {
                                        // Legacy journal FK checks can retain User
                                        // KEY SHARE, so a later webhook may wait at
                                        // User before it can lock Balance. This
                                        // does not prove the pre-existing mixed
                                        // lock-order deadlock has been eliminated.
                                        file_put_contents($directory.'/verified-lock-wait', 'ready');
                                        return;
                                    }
                                    usleep(1000);
                                } while (microtime(true) < $deadline);
                                throw new RuntimeException('PATCH 260 did not observe the competing owner/balance row lock.');
                            }
                        });
                        Http::assertNothingSent();
                        \Mockery::close();
                        $result = ['status' => 'ok'];
                    } catch (Throwable $exception) {
                        $result = ['status' => 'error', 'message' => $exception::class.': '.$exception->getMessage()];
                    }
                    file_put_contents($directory.'/result-'.$worker, json_encode($result, JSON_THROW_ON_ERROR));
                    DB::disconnect();
                    exit(0);
                }
                $this->assertGreaterThan(0, $pid);
                $children[] = $pid;
            }
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            DB::purge();
            DB::reconnect();
            $results = array_map(fn ($worker) => json_decode(file_get_contents($directory.'/result-'.$worker), true, flags: JSON_THROW_ON_ERROR), [0, 1]);
            $this->assertSame(['ok', 'ok'], array_column($results, 'status'), json_encode($results));
            $this->assertFileExists($directory.'/verified-lock-wait');
        } finally {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
            }
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function awaitFile(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('PATCH 260 concurrency barrier timed out: '.basename($path));
            }
            usleep(1000);
        }
    }
}
