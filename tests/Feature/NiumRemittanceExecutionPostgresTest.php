<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\NiumPayoutPilotAuthorization;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumPayoutPilotPolicy;
use App\Services\Nium\NiumSgdLocalOutcomeRecovery;
use App\Services\Nium\NiumSgdLocalPayoutGate;
use App\Services\Nium\NiumSgdLocalPayoutWorkflow;
use App\Services\Nium\NiumTransferService;
use App\Services\Wallet\LedgerService;
use App\Services\Wallet\TransferApprovalService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesNiumRemittanceSafetyFixtures;
use Tests\Concerns\CreatesRenewableNiumPilotFixtures;
use Tests\Concerns\CreatesSgdLocalExecutionFixtures;
use Tests\TestCase;
use Throwable;

class NiumRemittanceExecutionPostgresTest extends TestCase
{
    use CreatesNiumRemittanceSafetyFixtures;
    use CreatesRenewableNiumPilotFixtures;
    use CreatesSgdLocalExecutionFixtures;

    protected function setUp(): void
    {
        // Check before Laravel's migration traits run, including accidental full-suite selection.
        if (getenv('DB_HOST') !== '/tmp/origin-wallet-p0-pg' || getenv('DB_DATABASE') !== 'nium_p0_execution_test'
            || getenv('DB_CONNECTION') !== 'pgsql' || getenv('DB_URL') !== '' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Requires the guarded disposable P0 PostgreSQL cluster.');
        }
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame('/tmp/origin-wallet-p0-pg', DB::connection()->getConfig('host'));
        $this->assertSame('nium_p0_execution_test', DB::connection()->getDatabaseName());
        // Recreate only this disposable test schema, including old trigger functions.
        DB::statement('DROP SCHEMA public CASCADE');
        DB::statement('CREATE SCHEMA public');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        if (! Schema::hasColumn('transfers', 'provider_status')) {
            Schema::table('transfers', function (Blueprint $table): void {
                $table->string('provider_status', 80)->nullable();
                $table->text('provider_status_detail')->nullable();
            });
        }
        Http::preventStrayRequests();
    }

    public function test_competing_sgd_maker_requests_preserve_one_review_revision(): void
    {
        [, $transfer] = $this->sgdExecutionFixture();
        $this->race(function () use ($transfer): void {
            $this->fakeSgdProvider();
            app(NiumSgdLocalPayoutWorkflow::class)->requestApproval($transfer->fresh());
        });
        $current = $transfer->fresh();
        $this->assertSame('approval_required', $current->status);
        $this->assertSame($current->raw_data['sgd_local_workflow']['review_revision'],
            $current->raw_data['sgd_local_workflow']['review']['snapshot']['review_revision']);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(0, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
    }

    public static function pilotFundingPolicies(): array
    {
        return [['funded_reserve'], ['one_time_uncapped']];
    }

    public function test_competing_pilot_renewals_and_final_approvals_keep_one_acceptance_and_dispatch(): void
    {
        [, $transfer, $checker, $grant] = $this->expiredTransferThreePilot();
        $preview = app(NiumPayoutPilotPolicy::class)->renewalPreview($transfer, $checker);
        $this->assertTrue($preview['renewal_allowed']);
        $requestId = Str::uuid()->toString();
        $this->race(function () use ($transfer, $checker, $preview, $requestId): void {
            app(NiumPayoutPilotPolicy::class)->renewOneTime($transfer->fresh(), $checker,
                'SYNTHETIC-CONCURRENT-RISK-ACCEPTANCE', true, $requestId, $preview['confirmation_token']);
        });
        $history = $grant->fresh()->verification['one_time_renewals'];
        $this->assertCount(1, $history);
        $this->assertDatabaseCount('nium_payout_pilot_authorizations', 1);
        $this->assertDatabaseCount('ledger_entries', 0);
        app(NiumSgdLocalPayoutWorkflow::class)->requestApproval($transfer->fresh());
        $this->race(function () use ($transfer, $checker): void {
            $this->fakeSgdProvider();
            try {
                app(TransferApprovalService::class)->approve($transfer->fresh(), $checker, manager: app(ProviderTransferManager::class));
            } catch (RuntimeException) {
                // A losing checker can observe the already-claimed operation.
            }
        });
        $current = $transfer->fresh();
        $this->assertSame('pending', $current->status);
        $this->assertDatabaseCount('transfer_approvals', 2); // Retains the expired approval.
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $this->assertSame($current->provider_operation_key, $grant->fresh()->consumed_operation_key);
        $this->assertSame($history, $grant->fresh()->verification['one_time_renewals']);
        $this->assertSame('-5.00000000', LedgerEntry::query()->where('source_type', 'transfer')->where('entry_type', 'hold')->sole()->amount);
        $this->assertSame('5.00000000', Balance::query()->sole()->reserved_balance);
        $this->travelBack();
    }

    #[DataProvider('pilotFundingPolicies')]
    public function test_competing_pilot_approvals_consume_one_authorization_and_preserve_the_funding_policy(string $policy): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture(false);
        if ($policy === NiumPayoutPilotPolicy::UNCAPPED_POLICY) {
            $grant = app(NiumPayoutPilotPolicy::class)->authorizeOneTime($transfer, $checker, 'SYNTHETIC-ONE-TIME-AUTHORIZATION', true);
        } else {
            $balance = Balance::query()->sole();
            $balance->update(['available_balance' => '87', 'ledger_balance' => '87']);
            $funding = LedgerEntry::query()->create(['balance_id' => $balance->id, 'user_id' => 12, 'provider_id' => $transfer->provider_id,
                'reference' => 'synthetic-separate-funding', 'entry_type' => 'credit', 'status' => 'posted', 'currency' => 'USD',
                'amount' => '2', 'balance_after' => '87', 'source_type' => 'verified_funding', 'posted_at' => now()]);
            $grant = app(NiumPayoutPilotPolicy::class)->authorize($transfer, $checker, $funding, '2', [
                'funding_reference' => $funding->reference, 'charge_bound_reference' => 'SYNTHETIC-CEILING-ONLY',
                'review_document_sha256' => hash('sha256', 'Synthetic independently verified funding and all-charges bound'),
                'attestation' => 'settled_separate_funding_and_binding_all_charges_usd_ceiling_same_wallet',
            ]);
        }
        config()->set('services.nium.payout_pilot', ['enabled' => true, 'authorization_id' => $grant->id]);
        app(NiumSgdLocalPayoutWorkflow::class)->requestApproval($transfer);
        $this->race(function () use ($transfer, $checker): void {
            $this->fakeSgdProvider();
            try {
                app(TransferApprovalService::class)->approve($transfer->fresh(), $checker, manager: app(ProviderTransferManager::class));
            } catch (RuntimeException) {
                // A losing caller may observe the already-claimed operation.
            }
        });
        $current = $transfer->fresh();
        $this->assertSame('pending', $current->status);
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $this->assertSame($current->provider_operation_key, $grant->fresh()->consumed_operation_key);
        $this->assertSame('-5.00000000', LedgerEntry::query()->where('source_type', 'transfer')->where('entry_type', 'hold')->sole()->amount);
        $this->assertSame($policy === NiumPayoutPilotPolicy::UNCAPPED_POLICY ? '5.00000000' : '7.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame($policy === NiumPayoutPilotPolicy::UNCAPPED_POLICY ? null : '2.00000000', NiumPayoutPilotAuthorization::query()->sole()->reserve_remaining);
    }

    public function test_concurrent_sgd_capture_recovery_and_terminal_reconciliation_keep_one_debit(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture();
        $requested = app(NiumSgdLocalPayoutWorkflow::class)->requestApproval($transfer);
        $pending = app(TransferApprovalService::class)->approve($requested, $checker, manager: app(ProviderTransferManager::class));
        $pending->update(['status' => 'submission_unknown', 'external_transfer_id' => null]);
        $this->race(function () use ($pending): void {
            try {
                app(NiumSgdLocalOutcomeRecovery::class)->recover($pending->fresh());
            } catch (RuntimeException $e) {
                if (! str_contains($e->getMessage(), 'does not require')) {
                    throw $e;
                }
            }
        });
        $this->assertSame('synthetic-sgd-reference', $pending->fresh()->external_transfer_id);
        $this->assertSame('submission_unknown', $pending->fresh()->status);
        $this->assertSame('5.00000000', Balance::query()->sole()->reserved_balance);
        $this->race(function () use ($provider, $pending): void {
            $this->fakeSgdProvider('completed');
            app(ProviderTransferManager::class)->syncTransferStatus($provider, $pending->fresh());
        });
        $this->assertSame('completed', $pending->fresh()->status);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
    }

    public static function sgdSubmissionResults(): array
    {
        return [['pending', 'pending', '5', '0'], ['timeout', 'submission_unknown', '5', '0'], ['422', 'failed', '5', '0'], ['pending', 'pending', '12.34', '0']];
    }

    public function test_concurrent_financial_reconciliation_posts_principal_and_separate_fee_once(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture(scenario: ['amount' => '12.34']);
        $pending = app(TransferApprovalService::class)->approve(app(NiumSgdLocalPayoutWorkflow::class)->requestApproval($transfer),
            $checker, manager: app(ProviderTransferManager::class));
        $this->race(function () use ($provider, $pending): void {
            $this->fakeSgdProvider('completed', '1.25');
            app(ProviderTransferManager::class)->syncTransferStatus($provider, $pending->fresh());
        });
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'adjustment')->count());
        $this->assertSame('71.41000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame('71.41000000', Balance::query()->sole()->available_balance);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
    }

    public function test_concurrent_conflicting_fee_observations_do_not_apply_both_amounts(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture(scenario: ['amount' => '12.34']);
        $pending = app(TransferApprovalService::class)->approve(app(NiumSgdLocalPayoutWorkflow::class)->requestApproval($transfer),
            $checker, manager: app(ProviderTransferManager::class));
        $this->race(function (int $worker) use ($provider, $pending): void {
            $this->fakeSgdProvider('completed', $worker === 0 ? '1.25' : '2.50');
            app(ProviderTransferManager::class)->syncTransferStatus($provider, $pending->fresh());
        });
        $fee = LedgerEntry::query()->where('entry_type', 'adjustment')->sole();
        $expected = bcadd('72.66', $fee->amount, 8);
        $this->assertSame($expected, Balance::query()->sole()->ledger_balance);
        $this->assertSame($expected, Balance::query()->sole()->available_balance);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
    }

    #[DataProvider('sgdSubmissionResults')]
    public function test_sgd_checker_race_has_one_approved_owned_debit_and_one_dispatch(string $response, string $status, string $principal, string $fee): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture(scenario: ['provider_id' => 31, 'user_id' => 72,
            'account_id' => 41, 'beneficiary_id' => 55, 'amount' => $principal]);
        $this->installSyntheticPricing($transfer, ['fee_amount' => $fee]);
        app(NiumSgdLocalPayoutWorkflow::class)->requestApproval($transfer);
        $this->race(function () use ($transfer, $checker, $response): void {
            $this->fakeSgdProvider($response);
            try {
                app(TransferApprovalService::class)->approve($transfer->fresh(), $checker, manager: app(ProviderTransferManager::class));
            } catch (RuntimeException) {
                // Only the claimed synthetic rejection or a losing submission may throw.
            }
        });
        $current = $transfer->fresh();
        $this->assertSame($status, $current->status);
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $hold = LedgerEntry::query()->where('entry_type', 'hold')->sole();
        $total = (string) BigDecimal::of($principal)->plus($fee);
        $stored = (string) BigDecimal::of($total)->toScale(8);
        $this->assertSame('-'.$stored, $hold->amount);
        $this->assertSame($total, $hold->raw_data['financial_authorization']['total_wallet_debit']);
        $this->assertSame($current->provider_operation_key, $hold->raw_data['operation_key']);
        $this->assertSame($stored, Balance::query()->sole()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
    }

    public function test_competing_reviews_cannot_reserve_an_unfunded_provider_charge_under_principal_policy(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(scenario: ['amount' => '12.34']);
        $this->installSyntheticPricing($transfer, ['fee_amount' => '1.25']);
        $this->race(function () use ($transfer): void {
            $this->fakeSgdProvider();
            try {
                app(NiumSgdLocalPayoutWorkflow::class)->requestApproval($transfer->fresh());
                throw new \LogicException('A provider charge must not expand the principal reservation');
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== NiumSgdLocalPayoutGate::BLOCK_REASON) {
                    throw $exception;
                }
            }
        });
        $this->assertSame('draft', $transfer->fresh()->status);
        $this->assertNull($transfer->fresh()->provider_operation_key);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertSame('85.00000000', Balance::query()->sole()->available_balance);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(0, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
    }

    public function test_concurrent_dynamic_preparation_reuses_one_client_reference_without_reserving(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false, ['provider_id' => 31, 'user_id' => 72,
            'account_id' => 41, 'beneficiary_id' => 55, 'amount' => '12.34']);
        $this->race(function () use ($transfer): void {
            $this->fakeSgdProvider();
            app(NiumSgdLocalPayoutWorkflow::class)->prepare($transfer->user, [
                'provider_id' => 31, 'beneficiary_id' => 55, 'source_currency' => 'USD', 'target_currency' => 'SGD',
                'source_amount' => '7.25', 'purpose_code' => 'SYNTHETIC_PURPOSE',
                'client_reference' => 'ONE-NEW-INTENT', 'expected_fee_amount' => '0', 'expected_fee_currency' => 'USD',
            ]);
        });
        $this->assertSame(1, Transfer::query()->where('client_reference', 'ONE-NEW-INTENT')->count());
        $this->assertDatabaseCount('transfers', 2);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(0, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
    }

    public function test_expired_sgd_reset_competing_with_dispatch_cannot_claim_or_reserve(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture();
        $requested = app(NiumSgdLocalPayoutWorkflow::class)->requestApproval($transfer);
        app(TransferApprovalService::class)->approve($requested, $checker);
        $this->travel(11)->minutes();
        $this->race(function (int $worker) use ($provider, $transfer): void {
            $this->fakeSgdProvider();
            if ($worker === 0) {
                app(NiumSgdLocalPayoutWorkflow::class)->restartExpiredReview($transfer->fresh());
            } else {
                try {
                    app(ProviderTransferManager::class)->submitTransfer($provider, $transfer->fresh());
                } catch (RuntimeException) {
                }
            }
        });
        $this->assertSame('draft', $transfer->fresh()->status);
        $this->assertNull($transfer->fresh()->provider_operation_key);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->assertSame(0, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $this->travelBack();
    }

    public static function submissionResults(): array
    {
        return [['pending'], ['submission_unknown'], ['failed']];
    }

    #[DataProvider('submissionResults')]
    public function test_competing_submissions_have_one_dispatch_and_one_owned_reservation(string $outcome): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        $this->race(function () use ($provider, $transfer, $outcome): void {
            $this->fakeProvider($outcome);
            try {
                app(ProviderTransferManager::class)->submitTransfer($provider, $transfer->fresh());
            } catch (RuntimeException) {
                // A loser or definite synthetic rejection cannot release someone else's hold.
            }
        });
        $current = $transfer->fresh();
        $this->assertSame($outcome, $current->status);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'hold')->count());
        $this->assertSame($outcome === 'failed' ? 1 : 0, LedgerEntry::query()->where('entry_type', 'release')->count());
        $this->assertSame($outcome === 'failed' ? '0.00000000' : '10.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame($current->provider_operation_key, LedgerEntry::query()->where('entry_type', 'hold')->sole()->raw_data['operation_key']);
    }

    public function test_competing_approvals_and_submission_persist_one_checker_and_dispatch(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer(['status' => 'approval_required']);
        config()->set('wallet.transfer_controls.require_admin_approval', true);
        $checker = User::factory()->create();
        $this->race(function () use ($transfer, $checker): void {
            $this->fakeProvider('submission_unknown');
            app(TransferApprovalService::class)->approve($transfer->fresh(), $checker, manager: app(ProviderTransferManager::class));
        });
        $this->assertSame(1, $transfer->approvals()->count());
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $this->assertSame('submission_unknown', $transfer->fresh()->status);
        $this->assertSame('10.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
    }

    public function test_concurrent_terminal_reconciliation_releases_once(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        $this->fakeProvider('pending');
        app(ProviderTransferManager::class)->submitTransfer($provider, $transfer);
        $this->race(function () use ($provider, $transfer): void {
            Http::fake(['*' => Http::response([['status' => 'FAILED']])]);
            app(ProviderTransferManager::class)->syncTransferStatus($provider, $transfer->fresh());
        });
        $this->assertSame('failed', $transfer->fresh()->status);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'release')->count());
        $this->assertSame('1000.00000000', Balance::query()->sole()->available_balance);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
    }

    public function test_conflicting_concurrent_terminal_results_cannot_release_and_settle(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        $this->fakeProvider('pending');
        app(ProviderTransferManager::class)->submitTransfer($provider, $transfer);
        $this->race(function (int $worker) use ($provider, $transfer): void {
            try {
                DB::transaction(function () use ($worker, $provider, $transfer): void {
                    $locked = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
                    $updated = app(NiumTransferService::class)->applyPreparedTransferStatus($provider, $locked, [
                        'status' => $worker === 0 ? 'completed' : 'failed',
                        'status_payload' => ['status' => $worker === 0 ? 'PAID' : 'FAILED'], 'response_data' => [],
                    ]);
                    app(LedgerService::class)->applyTransferTerminalStatus($updated);
                });
            } catch (RuntimeException $exception) {
                if (! str_contains($exception->getMessage(), 'conflicts with an existing terminal')) {
                    throw $exception;
                }
            }
        });
        $this->assertSame(1, LedgerEntry::query()->whereIn('entry_type', ['debit', 'release'])->count());
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $balance = Balance::query()->sole();
        $this->assertSame($transfer->fresh()->status === 'completed' ? '990.00000000' : '1000.00000000', $balance->ledger_balance);
    }

    public function test_two_transfers_cannot_reserve_the_same_available_funds(): void
    {
        [$provider, $first] = $this->makeSubmittableTransfer(['source_amount' => 800]);
        $second = $first->replicate(['transfer_no']);
        $second->transfer_no = 'TRF-SECOND-SYNTHETIC';
        $second->save();
        $ids = [$first->id, $second->id];
        $this->race(function (int $worker) use ($provider, $ids): void {
            $this->fakeProvider('submission_unknown');
            try {
                app(ProviderTransferManager::class)->submitTransfer($provider, Transfer::query()->findOrFail($ids[$worker]));
            } catch (RuntimeException) {
            }
        });
        $this->assertSame('800.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame('200.00000000', Balance::query()->sole()->available_balance);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'hold')->count());
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
    }

    private function fakeProvider(string $outcome): void
    {
        Http::fake(function ($request) use ($outcome) {
            if ($request->method() !== 'POST') {
                return Http::response([['description' => 'Synthetic purpose', 'purposeCode' => 'IR01811']]);
            }
            usleep(200000);
            if ($outcome === 'submission_unknown') {
                throw new ConnectionException('Synthetic timeout; no network');
            }

            return $outcome === 'failed'
                ? Http::response(['message' => 'Synthetic validation rejection'], 422)
                : Http::response(['systemReferenceNumber' => 'synthetic-ref']);
        });
    }

    private function race(callable $work): void
    {
        $dir = sys_get_temp_dir().'/nium-p0-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        DB::disconnect();
        $children = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    DB::purge();
                    DB::reconnect();
                    Http::swap(new Factory);
                    Http::preventStrayRequests();
                    touch($dir.'/ready-'.$worker);
                    $deadline = microtime(true) + 10;
                    while (count(glob($dir.'/ready-*')) < 2) {
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException('Worker barrier timeout');
                        }
                        usleep(10000);
                    }
                    $work($worker);
                    exit(0);
                } catch (Throwable $exception) {
                    file_put_contents($dir.'/error-'.$worker, (string) $exception);
                    exit(1);
                }
            }
            $this->assertGreaterThan(0, $pid);
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $errors = implode("\n", array_map('file_get_contents', glob($dir.'/error-*')));
            $this->assertTrue(pcntl_wifexited($status), $errors);
            $this->assertSame(0, pcntl_wexitstatus($status), $errors);
        }
        DB::purge();
        DB::reconnect();
        foreach (glob($dir.'/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}
