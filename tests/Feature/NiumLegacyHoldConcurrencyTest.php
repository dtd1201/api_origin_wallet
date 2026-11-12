<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\NiumDynamicReservation;
use App\Models\Transfer;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumService;
use App\Services\Nium\NiumSubmissionPreflightException;
use App\Services\Wallet\LedgerService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Concerns\CreatesFinalizedUsdConfirmationFixtures;
use Tests\Support\DynamicConfirmationDatabaseTestCase;
use Throwable;

/** Real committed PostgreSQL transactions; provider service-port stubs never send HTTP. */
final class NiumLegacyHoldConcurrencyTest extends DynamicConfirmationDatabaseTestCase
{
    use CreatesDynamicConfirmationFixtures;
    use CreatesFinalizedUsdConfirmationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(function_exists('pcntl_fork'), 'PATCH 258 PostgreSQL races require pcntl.');
        config(['wallet.ledger.enabled' => true, 'wallet.transfer_controls.require_admin_approval' => false,
            'services.nium.base_url' => 'https://gatewaysandbox.nium.com',
            'services.nium.client_id' => 'fixture-client', 'services.nium.payout_fx_enabled' => false]);
        $this->createDynamicFixtures('3000');
        $this->wallet->update(['currency' => 'USD']);
        $this->source->review = $this->usdReview();
        $this->confirmDynamic();
        $this->owner->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        $this->owner->kycProfile()->update([
            'applicant_type' => 'business', 'registered_country_code' => 'HK', 'country_code' => 'HK',
            'business_name' => 'Fixture HK Customer', 'metadata' => ['nium_region' => 'HK'],
        ]);
        $this->beneficiary->update(['beneficiary_type' => 'business', 'country_code' => 'HK',
            'currency' => 'USD', 'payout_method' => 'SWIFT', 'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT']]]);
    }

    public function test_concurrent_duplicate_reservation_has_one_creator_and_one_hold(): void
    {
        $transfer = $this->transfer();
        $results = $this->race(function (int $worker, string $directory) use ($transfer): array {
            $hold = app(LedgerService::class)->reserveTransfer($transfer);

            return ['id' => $hold->id, 'created' => $hold->wasRecentlyCreated];
        });

        $this->assertSame(['ok', 'ok'], array_column($results, 'status'), json_encode($results));
        $this->assertSame($results[0]['value']['id'], $results[1]['value']['id']);
        $this->assertSame(1, count(array_filter(array_column($results, 'value'), fn ($value) => $value['created'])));
        $this->assertSame(1, $this->journals($transfer)->count());
        $this->books('1500', '1500', '3000');
    }

    public function test_concurrent_settle_and_release_have_one_terminal_journal(): void
    {
        $transfer = $this->transfer();
        app(LedgerService::class)->reserveTransfer($transfer);
        $results = $this->race(function (int $worker, string $directory) use ($transfer): string {
            $ledger = app(LedgerService::class);
            $entry = $worker === 0 ? $ledger->settleTransfer($transfer) : $ledger->releaseTransferHold($transfer);

            return $entry->entry_type;
        });

        $this->assertSame(1, count(array_filter($results, fn ($result) => $result['status'] === 'ok')), json_encode($results));
        $rejected = array_values(array_filter($results, fn ($result) => $result['status'] === 'rejected'));
        $this->assertCount(1, $rejected, json_encode($results));
        $this->assertContains($rejected[0]['value'], [
            'A released transfer hold cannot be settled.', 'A debited transfer hold cannot be released.',
        ]);
        $this->assertSame(2, $this->journals($transfer)->count());
        if ($this->journals($transfer)->where('entry_type', 'debit')->exists()) {
            $this->books('1500', '1000', '2500');
        } else {
            $this->books('2000', '1000', '3000');
        }
    }

    public static function claimCleanupOrders(): array
    {
        return ['submission claim wins' => [true], 'creator cleanup wins' => [false]];
    }

    #[DataProvider('claimCleanupOrders')]
    public function test_concurrent_creator_cleanup_and_reusing_submission_are_fenced(bool $claimWins): void
    {
        $transfer = $this->transfer();
        $results = $this->race(function (int $worker, string $directory) use ($transfer, $claimWins): array {
            // The creator remains in purpose-code preflight while another request
            // reuses its committed hold. Only service-port I/O is simulated.
            $posts = 0;
            $nium = $this->partialMock(NiumService::class);
            $nium->shouldReceive('get')->once()->andReturnUsing(function (...$arguments) use ($worker, $directory, $claimWins): Response {
                if ($worker === 0) {
                    file_put_contents($directory.'/creator-preflight', 'ready');
                    $this->awaitFile($directory.'/'.($claimWins ? 'wire-entered' : 'reuser-preflight'));

                    return $this->response(['message' => 'Purpose codes unavailable'], 503);
                }
                file_put_contents($directory.'/reuser-preflight', 'ready');
                if (! $claimWins) {
                    $this->awaitFile($directory.'/creator-done');
                }

                return $this->response([['description' => 'General Goods', 'purposeCode' => 'IR01811']]);
            });
            if ($worker === 1 && $claimWins) {
                $nium->shouldReceive('post')->once()->andReturnUsing(function (...$arguments) use ($directory, &$posts): Response {
                    $this->assertSame(0, DB::transactionLevel());
                    $posts++;
                    file_put_contents($directory.'/wire-entered', 'ready');
                    $this->awaitFile($directory.'/creator-done');
                    $this->assertSame('1500.00000000', $this->wallet->fresh()->reserved_balance);
                    $this->assertSame(0, LedgerEntry::query()->where('source_type', 'transfer')->where('entry_type', 'release')->count());

                    return $this->response(['systemReferenceNumber' => 'RT-PATCH258-RACE']);
                });
            } else {
                $nium->shouldReceive('post')->never();
            }
            if ($worker === 1) {
                $this->awaitFile($directory.'/creator-preflight');
            }
            try {
                $submitted = app(ProviderTransferManager::class)->submitTransfer($this->account->provider, $transfer->fresh());

                return ['outcome' => $submitted->status, 'posts' => $posts];
            } catch (NiumSubmissionPreflightException $exception) {
                return ['outcome' => 'preflight_rejected', 'posts' => $posts, 'reason' => $exception->getMessage()];
            } finally {
                if ($worker === 0) {
                    file_put_contents($directory.'/creator-done', 'ready');
                }
            }
        });

        $this->assertSame(['ok', 'ok'], array_column($results, 'status'), json_encode($results));
        $this->assertSame('preflight_rejected', $results[0]['value']['outcome']);
        $this->assertSame('Nium purpose codes are temporarily unavailable.', $results[0]['value']['reason']);
        if ($claimWins) {
            $this->assertSame('pending', $results[1]['value']['outcome']);
            $this->assertSame(1, $results[1]['value']['posts']);
            $this->assertSame(1, $this->journals($transfer)->count());
            $this->books('1500', '1500', '3000');
        } else {
            $this->assertSame('preflight_rejected', $results[1]['value']['outcome']);
            $this->assertSame('Released or debited transfer holds cannot be reused as active reservations.', $results[1]['value']['reason']);
            $this->assertSame(0, $results[1]['value']['posts']);
            $this->assertSame(2, $this->journals($transfer)->count());
            $this->books('2000', '1000', '3000');
        }
        $this->assertSame(0, $results[0]['value']['posts']);
    }

    private function transfer(): Transfer
    {
        return Transfer::query()->create([
            'transfer_no' => 'PATCH258-RACE', 'user_id' => $this->owner->id,
            'provider_id' => $this->account->provider_id, 'beneficiary_id' => $this->beneficiary->id,
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'USD',
            'source_amount' => '500', 'fee_amount' => '0', 'fee_currency' => 'USD',
            'purpose_code' => 'IR01811', 'reference_text' => 'PATCH258', 'status' => 'draft',
        ]);
    }

    private function response(array $body, int $status = 200): Response
    {
        return new Response(new PsrResponse($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));
    }

    private function journals(Transfer $transfer): \Illuminate\Database\Eloquent\Builder
    {
        return LedgerEntry::query()->where('source_type', 'transfer')->where('source_id', (string) $transfer->id);
    }

    private function books(string $available, string $reserved, string $ledger): void
    {
        $wallet = $this->wallet->fresh();
        $this->assertSame(bcadd($available, '0', 8), $wallet->available_balance);
        $this->assertSame(bcadd($reserved, '0', 8), $wallet->reserved_balance);
        $this->assertSame(bcadd($ledger, '0', 8), $wallet->ledger_balance);
        $this->assertSame($wallet->ledger_balance, bcadd($wallet->available_balance, $wallet->reserved_balance, 8));
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame('1000.00000000', NiumDynamicReservation::query()->sole()->amount);
    }

    private function awaitFile(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('PATCH258 concurrency barrier timed out: '.basename($path));
            }
            usleep(1000);
        }
    }

    private function race(callable $operation): array
    {
        $directory = tempnam(sys_get_temp_dir(), 'patch258-');
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
                        DB::statement("SET lock_timeout = '5s'");
                        DB::statement("SET statement_timeout = '10s'");
                        $this->awaitFile($directory.'/start');
                        $result = ['status' => 'ok', 'value' => $operation($worker, $directory)];
                    } catch (RuntimeException $exception) {
                        $result = ['status' => 'rejected', 'value' => $exception->getMessage()];
                    } catch (Throwable $exception) {
                        $result = ['status' => 'error', 'value' => $exception::class.': '.$exception->getMessage()];
                    }
                    try {
                        Http::assertNothingSent();
                        \Mockery::close();
                    } catch (Throwable $exception) {
                        $result = ['status' => 'error', 'value' => $exception::class.': '.$exception->getMessage()];
                    }
                    file_put_contents($directory.'/result-'.$worker, json_encode($result, JSON_THROW_ON_ERROR));
                    DB::disconnect();
                    exit(0);
                }
                $this->assertGreaterThan(0, $pid);
                $children[] = $pid;
            }
            file_put_contents($directory.'/start', 'ready');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            DB::purge();
            DB::reconnect();

            return array_map(fn ($worker) => json_decode(file_get_contents($directory.'/result-'.$worker), true, flags: JSON_THROW_ON_ERROR), [0, 1]);
        } finally {
            file_put_contents($directory.'/start', 'ready');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
            }
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
