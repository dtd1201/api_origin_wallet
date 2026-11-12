<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Models\LedgerEntry;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use App\Models\NiumDynamicReservation;
use App\Models\User;
use App\Services\Nium\DynamicConfirmation\DynamicReservationLedger;
use App\Services\Nium\DynamicConfirmation\DynamicTransferConfirmationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Concerns\CreatesFinalizedUsdConfirmationFixtures;
use Tests\Concerns\CreatesNiumBalanceSyncObservation;
use Tests\Support\DynamicConfirmationDatabaseTestCase;
use Throwable;

final class NiumDynamicConfirmationConcurrencyTest extends DynamicConfirmationDatabaseTestCase
{
    use CreatesDynamicConfirmationFixtures;
    use CreatesFinalizedUsdConfirmationFixtures;
    use CreatesNiumBalanceSyncObservation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(function_exists('pcntl_fork'), 'AUTO-02B concurrency verification requires pcntl.');
        $this->createDynamicFixtures('150.00');
    }

    public function test_concurrent_identical_confirmations_return_one_intent_and_one_hold(): void
    {
        $results = $this->race(fn (int $worker) => $this->confirmDynamic()->id);

        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertSame($results[0]['value'], $results[1]['value']);
        $this->assertOneReservation();
    }

    public function test_concurrent_different_quotes_cannot_overspend_the_same_wallet(): void
    {
        $results = $this->race(function (int $worker): string {
            $this->source->review = $this->dynamicReview(quoteId: 'competing-quote-'.$worker);

            return $this->confirmDynamic('competing-reference-'.$worker)->id;
        });

        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['status'] === 'ok')));
        $errors = array_values(array_filter($results, fn ($r) => $r['status'] === 'rejected'));
        $this->assertCount(1, $errors);
        $this->assertSame('dynamic_insufficient_available_funds', $errors[0]['value']);
        $this->assertOneReservation();
    }

    public function test_concurrent_conflicting_confirmations_have_one_winner(): void
    {
        $results = $this->race(function (int $worker): string {
            $this->source->review = $this->dynamicReview(fee: $worker === 0 ? '2' : '3');

            return $this->confirmDynamic()->id;
        });

        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['status'] === 'ok')));
        $errors = array_values(array_filter($results, fn ($r) => $r['status'] === 'rejected'));
        $this->assertCount(1, $errors);
        $this->assertSame('dynamic_confirmation_idempotency_conflict', $errors[0]['value']);
        $this->assertSame(1, NiumDynamicIntent::query()->count());
        $this->assertSame(1, LedgerEntry::query()->count());
        $this->assertContains($this->wallet->fresh()->reserved_balance, ['102.00000000', '103.00000000']);
    }

    public function test_concurrent_cancellations_release_once(): void
    {
        $intent = $this->confirmDynamic();
        $results = $this->race(fn (int $worker) => $this->confirmation->cancel($this->owner, $intent->id)->id);

        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'release')->count());
        $this->assertSame('150.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('0.00000000', $this->wallet->fresh()->reserved_balance);
    }

    public function test_concurrent_sync_and_confirmation_preserve_the_new_hold(): void
    {
        $results = $this->race(function (int $worker): string {
            if ($worker === 0) {
                return $this->confirmDynamic()->id;
            }
            $this->observeNiumBalance('150');
            return 'synced';
        });
        $this->assertSame(['ok', 'ok'], array_column($results, 'status'), json_encode($results));
        $this->assertOneReservation();
        $this->assertSame('150.00000000', $this->wallet->fresh()->ledger_balance);
    }

    public function test_concurrent_sync_and_cancellation_preserve_one_release(): void
    {
        $intent = $this->confirmDynamic();
        $results = $this->race(function (int $worker) use ($intent): string {
            if ($worker === 0) {
                return $this->confirmation->cancel($this->owner, $intent->id)->id;
            }
            $this->observeNiumBalance('150');
            return 'synced';
        });
        $this->assertSame(['ok', 'ok'], array_column($results, 'status'), json_encode($results));
        $this->assertSame('150.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('150.00000000', $this->wallet->fresh()->ledger_balance);
        $this->assertSame('0.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'release')->count());
    }

    public function test_concurrent_sync_and_ledger_consumption_preserve_one_customer_debit(): void
    {
        $this->wallet->update(['currency' => 'USD', 'available_balance' => '3000',
            'ledger_balance' => '3000', 'raw_data' => ['provider_available_balance' => '3000']]);
        $this->source->review = $this->usdReview();
        $intent = $this->confirmDynamic();
        $results = $this->race(function (int $worker) use ($intent): string {
            if ($worker === 0) {
                OwnerFirst::users([$this->owner->id], function ($context) use ($intent): void {
                    $intent = \App\Models\NiumDynamicIntent::query()->lockForUpdate()->findOrFail($intent->id);
                    User::query()->lockForUpdate()->findOrFail($this->owner->id);
                    $reservation = NiumDynamicReservation::query()->lockForUpdate()->sole();
                    (new DynamicReservationLedger)->consume($intent, $reservation, $this->wallet, $context);
                });
                return 'consumed';
            }
            $this->observeNiumBalance('2000');
            return 'synced';
        });
        $this->assertSame(['ok', 'ok'], array_column($results, 'status'), json_encode($results));
        $this->assertSame('2000.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('2000.00000000', $this->wallet->fresh()->ledger_balance);
        $this->assertSame('0.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
    }

    private function assertOneReservation(): void
    {
        $this->assertSame(1, NiumDynamicIntent::query()->count());
        $this->assertSame(1, NiumDynamicReservation::query()->count());
        $this->assertSame(1, NiumDynamicPayoutOutbox::query()->count());
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'hold')->count());
        $this->assertSame('48.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('102.00000000', $this->wallet->fresh()->reserved_balance);
    }

    /** Real committed PostgreSQL transactions; no surrounding RefreshDatabase transaction. */
    private function race(callable $operation): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'auto02b-start-');
        $outputs = [tempnam(sys_get_temp_dir(), 'auto02b-result-'), tempnam(sys_get_temp_dir(), 'auto02b-result-')];
        $children = [];
        DB::disconnect();

        try {
            foreach ([0, 1] as $worker) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    try {
                        DB::purge();
                        DB::reconnect();
                        DB::statement("SET lock_timeout = '5s'");
                        DB::statement("SET statement_timeout = '10s'");
                        $deadline = microtime(true) + 10;
                        while (file_get_contents($barrier) !== 'go') {
                            if (microtime(true) > $deadline) {
                                throw new RuntimeException('concurrency_barrier_timeout');
                            }
                            usleep(1000);
                        }
                        // Hold real row locks long enough for the other request to overlap.
                        $this->source->duringValidation = static fn () => usleep(150000);
                        $this->confirmation = new DynamicTransferConfirmationService(new DynamicReservationLedger, $this->source);
                        $result = ['status' => 'ok', 'value' => $operation($worker)];
                    } catch (RuntimeException $exception) {
                        $result = ['status' => 'rejected', 'value' => $exception->getMessage()];
                    } catch (Throwable $exception) {
                        $result = ['status' => 'error', 'value' => $exception::class.': '.$exception->getMessage()];
                    }
                    try {
                        Http::assertNothingSent();
                        Queue::assertNothingPushed();
                        Bus::assertNothingDispatched();
                    } catch (Throwable $exception) {
                        $result = ['status' => 'error', 'value' => $exception::class.': '.$exception->getMessage()];
                    }
                    file_put_contents($outputs[$worker], json_encode($result, JSON_THROW_ON_ERROR));
                    DB::disconnect();
                    exit(0);
                }
                $this->assertGreaterThan(0, $pid);
                $children[] = $pid;
            }
            file_put_contents($barrier, 'go');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            DB::purge();
            DB::reconnect();

            return array_map(fn ($path) => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR), $outputs);
        } finally {
            // Ensure assertion failures cannot leave waiting children behind.
            file_put_contents($barrier, 'go');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
            }
            unlink($barrier);
            foreach ($outputs as $path) {
                unlink($path);
            }
            DB::purge();
            DB::reconnect();
        }
    }
}
