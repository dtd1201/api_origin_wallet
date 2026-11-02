<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\LedgerEntry;
use App\Models\NiumApiExchangeEvidence;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumPayoutFinancialControls;
use App\Services\Nium\NiumPayoutPreDispatchException;
use App\Services\Nium\NiumService;
use App\Services\Nium\NiumSgdLocalOutcomeRecovery;
use App\Services\Nium\NiumSgdLocalPayoutWorkflow;
use App\Services\Nium\NiumSgdLocalRemittancePayload;
use App\Services\Nium\NiumSgdLocalZeroFeePolicy;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\SgdReleasePostgresSafety;

/** Real PostgreSQL commits/forks; every external request is synthetic. Inherits HK races too. */
class NiumSgdReleasePostgresTest extends NiumRemittanceExecutionPostgresTest
{
    protected function postgresSafety(): string
    {
        return SgdReleasePostgresSafety::class;
    }

    private function activateZeroFee(Transfer $transfer): void
    {
        config()->set('services.nium.sgd_local_zero_fee', [
            'enabled' => true, 'version' => 'SYNTHETIC-ZERO-FEE-V1',
            'confirmation_reference' => 'SYNTHETIC-OWNER-CONFIRMATION', 'confirmed_by' => 'Synthetic owner',
            'confirmed_at' => now()->subHour()->toISOString(), 'effective_from' => now()->subMinutes(30)->toISOString(),
            'expires_at' => now()->addHour()->toISOString(),
            'provider_context' => app(NiumPayoutFinancialControls::class)->intent($transfer)['provider_context'],
            'terms' => NiumSgdLocalZeroFeePolicy::TERMS,
        ]);
    }

    public static function zeroFeeOutcomes(): array
    {
        return [['pending', 'pending'], ['timeout', 'submission_unknown'], ['422', 'failed'],
            ['307', 'submission_unknown'], ['missing_reference', 'submission_unknown'], ['500', 'submission_unknown']];
    }

    #[DataProvider('zeroFeeOutcomes')]
    public function test_zero_fee_concurrent_admin_endpoints_and_serialized_queue_replays_cannot_repeat_dispatch(string $outcome, string $status): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture(false, ['amount' => '12.34000001', 'quote_minutes' => 15]);
        $this->activateZeroFee($transfer);
        $requested = $this->submitSgd($transfer);
        $secondChecker = User::factory()->create(['status' => 'active']);
        $secondChecker->roles()->create(['role_code' => 'admin']);
        $tokens = [$this->sgdToken($checker), $this->sgdToken($secondChecker)];
        $this->race(function (int $worker) use ($transfer, $tokens, $outcome): void {
            $this->fakeSgdProvider($outcome);
            $response = $this->withToken($tokens[$worker])->postJson("/api/admin/transfers/{$transfer->id}/approve");
            $this->assertContains($response->status(), [200, 422]);
        });
        $current = $requested->fresh();
        $this->assertSame($status, $current->status);
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $operation = $current->provider_operation_key;
        $this->assertNotNull($operation);
        $this->assertNotNull($current->raw_data['nium_execution']['dispatch_started_at']);
        $hold = LedgerEntry::query()->where('entry_type', 'hold')->sole();
        $this->assertSame('-12.34000001', $hold->amount);
        $this->assertSame('0', $hold->raw_data['financial_authorization']['fee_amount']);
        $this->assertSame('12.34000001', $hold->raw_data['financial_authorization']['total_wallet_debit']);
        $this->assertSame('SYNTHETIC-OWNER-CONFIRMATION', $hold->raw_data['financial_authorization']['business_confirmation']['confirmation_reference']);
        $this->assertSame($operation, $hold->raw_data['operation_key']);
        $log = ApiRequestLog::query()->where('operation', 'transfer_money')->sole();
        $evidence = NiumApiExchangeEvidence::query()->where('api_request_log_id', $log->id)->sole();
        $wire = json_decode($evidence->raw_request_body, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($current->raw_data['sgd_local_approval']['quote_id'], $wire['payout']['quoteId']);
        $this->assertEquals(12.34000001, $wire['payout']['sourceAmount']);
        $this->assertArrayNotHasKey('auditId', $wire['payout']);
        $this->assertArrayNotHasKey('destinationAmount', $wire['payout']);
        // No production remittance job exists. Exercise queue serialization/re-entry of the actual service.
        $this->fakeSgdProvider();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $job = unserialize(serialize(new SyntheticSgdSubmissionReplay($provider->id, $transfer->id)));
            try {
                app('queue')->connection('sync')->push($job);
                $this->fail('A serialized retry cannot acquire a new dispatch attempt.');
            } catch (RuntimeException) {
            }
        }
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertSame($operation, $transfer->fresh()->provider_operation_key);
        $this->assertSame($status, $transfer->fresh()->status);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame('12.34000001', Balance::query()->sole()->reserved_balance);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
    }

    public static function expiryBoundaries(): array
    {
        return [['customer'], ['checker'], ['claim'], ['wire'], ['policy_at_wire']];
    }

    #[DataProvider('expiryBoundaries')]
    public function test_zero_fee_policy_never_bypasses_quote_or_policy_expiry_at_committed_boundaries(string $boundary): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture(false, ['quote_minutes' => 15]);
        $this->activateZeroFee($transfer);
        $token = $this->reviewToken($transfer)['submission_token'];
        if ($boundary !== 'customer') {
            $this->submitSgd($transfer);
        }
        if (in_array($boundary, ['claim', 'wire', 'policy_at_wire'], true)) {
            app(TransferApprovalService::class)->approve($transfer->fresh(), $checker);
        }
        $this->fakeSgdProvider();
        if (in_array($boundary, ['wire', 'policy_at_wire'], true)) {
            Event::listen(RequestSending::class, function (RequestSending $event) use ($transfer, $boundary): void {
                if (str_ends_with($event->request->url(), '/remittance')) {
                    $this->assertNotNull($transfer->fresh()->provider_operation_key);
                    if ($boundary === 'wire') {
                        $this->travelTo($transfer->fresh()->fxQuote->expires_at);
                    } else {
                        config()->set('services.nium.sgd_local_zero_fee.expires_at', now()->toISOString());
                    }
                }
            });
        } else {
            $this->travelTo($transfer->fxQuote->expires_at);
        }
        try {
            match ($boundary) {
                'customer' => app(NiumSgdLocalPayoutWorkflow::class)->submit($transfer->fresh(), $transfer->user, $token, true),
                'checker' => app(TransferApprovalService::class)->approve($transfer->fresh(), $checker, manager: app(ProviderTransferManager::class)),
                default => app(ProviderTransferManager::class)->submitTransfer($provider, $transfer->fresh()),
            };
            $this->assertContains($boundary, ['wire', 'policy_at_wire']);
        } catch (RuntimeException|ValidationException) {
            $this->assertNotContains($boundary, ['wire', 'policy_at_wire']);
        }
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertDatabaseCount('fx_quotes', 1);
        $current = $transfer->fresh();
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame('85.00000000', Balance::query()->sole()->available_balance);
        if (in_array($boundary, ['wire', 'policy_at_wire'], true)) {
            $this->assertSame('failed', $current->status);
            $this->assertDatabaseCount('ledger_entries', 2);
            $this->assertNotNull($current->provider_operation_key);
            $this->assertArrayNotHasKey('dispatch_started_at', $current->raw_data['nium_execution']);
            $this->assertSame($current->provider_operation_key, $current->fxQuote->raw_data['consumed_operation_key']);
        } else {
            $this->assertNull($current->provider_operation_key);
            $this->assertDatabaseCount('ledger_entries', 0);
        }
        $this->travelBack();
    }

    public function test_concurrent_wire_callers_cannot_release_the_winners_zero_fee_hold(): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture(false);
        $this->activateZeroFee($transfer);
        $this->submitSgd($transfer);
        $raced = false;
        Event::listen(RequestSending::class, function (RequestSending $event) use ($transfer, &$raced): void {
            if ($raced || ! str_ends_with($event->request->url(), '/remittance')) {
                return;
            }
            $raced = true;
            $this->race(function () use ($transfer): void {
                $this->fakeSgdProvider();
                $current = $transfer->fresh();
                $snapshot = $current->raw_data['sgd_local_approval'];
                $nium = app(NiumService::class);
                try {
                    $nium->post($nium->path(config('services.nium.transfer_endpoint'), $snapshot['provider_context']),
                        NiumSgdLocalRemittancePayload::fromSnapshot($snapshot), $current->user,
                        $current->id, 'transfer_money', $current->provider_operation_key);
                } catch (NiumPayoutPreDispatchException) {
                    // Only one transport authority can consume the persisted marker.
                }
            });
        });
        $current = app(TransferApprovalService::class)->approve($transfer->fresh(), $checker, manager: app(ProviderTransferManager::class));
        $this->assertTrue($raced);
        $this->assertSame('submission_unknown', $current->status);
        $this->assertNotNull($current->raw_data['nium_execution']['dispatch_started_at']);
        $this->assertSame('5.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
    }

    public function test_post_response_database_failure_preserves_capture_and_recovers_without_repost(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture(false);
        $this->activateZeroFee($transfer);
        $this->submitSgd($transfer);
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION synthetic_reject_outcome() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'Synthetic post-response database failure'; END; $$;
            CREATE TRIGGER synthetic_reject_outcome BEFORE UPDATE ON transfers
            FOR EACH ROW WHEN (OLD.status = 'submitting' AND NEW.status = 'pending')
            EXECUTE FUNCTION synthetic_reject_outcome();
            SQL);
        try {
            app(TransferApprovalService::class)->approve($transfer->fresh(), $checker, manager: app(ProviderTransferManager::class));
            $this->fail('The synthetic outcome write must fail after the committed send.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic post-response database failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER synthetic_reject_outcome ON transfers; DROP FUNCTION synthetic_reject_outcome()');
        }
        $current = $transfer->fresh();
        $this->assertSame('submitting', $current->status);
        $this->assertNull($current->external_transfer_id);
        $operation = $current->provider_operation_key;
        $this->assertNotNull($current->raw_data['nium_execution']['dispatch_started_at']);
        $this->assertSame('5.00000000', Balance::query()->sole()->reserved_balance);
        $recovered = app(NiumSgdLocalOutcomeRecovery::class)->recover($current);
        $this->assertSame('synthetic-sgd-reference', $recovered->external_transfer_id);
        $this->race(function () use ($provider, $recovered): void {
            $this->fakeSgdProvider('completed');
            app(ProviderTransferManager::class)->syncTransferStatus($provider, $recovered->fresh());
        });
        $this->assertSame('completed', $transfer->fresh()->status);
        $this->assertSame($operation, $transfer->fresh()->provider_operation_key);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame('80.00000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
    }

    public function test_reservation_failure_rolls_back_the_entire_claim_before_any_transport(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture(false);
        $this->activateZeroFee($transfer);
        app(TransferApprovalService::class)->approve($this->submitSgd($transfer), $checker);
        $this->fakeSgdProvider();
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION synthetic_reject_hold() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'Synthetic reservation failure'; END; $$;
            CREATE TRIGGER synthetic_reject_hold BEFORE INSERT ON ledger_entries
            FOR EACH ROW WHEN (NEW.entry_type = 'hold') EXECUTE FUNCTION synthetic_reject_hold();
            SQL);
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $transfer->fresh());
            $this->fail('The synthetic reservation write must fail.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic reservation failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER synthetic_reject_hold ON ledger_entries; DROP FUNCTION synthetic_reject_hold()');
        }
        $current = $transfer->fresh();
        $this->assertSame('approved', $current->status);
        $this->assertNull($current->provider_operation_key);
        $this->assertNull($current->fxQuote->raw_data['consumed_operation_key'] ?? null);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame('85.00000000', Balance::query()->sole()->available_balance);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        // Existing approval can resume only because no dispatch claim ever committed.
        $pending = app(TransferApprovalService::class)->approve($current, $checker, manager: app(ProviderTransferManager::class));
        $this->assertSame('pending', $pending->status);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_exception_after_claim_before_wire_marker_keeps_an_uncaptured_unknown_hold(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture(false);
        $this->activateZeroFee($transfer);
        $this->submitSgd($transfer);
        $this->fakeSgdProvider();
        Event::listen(RequestSending::class, function (RequestSending $event): void {
            if (str_ends_with($event->request->url(), '/remittance')) {
                throw new RuntimeException('Synthetic process failure before wire guard');
            }
        });
        $unknown = app(TransferApprovalService::class)->approve($transfer->fresh(), $checker, manager: app(ProviderTransferManager::class));
        $this->assertSame('submission_unknown', $unknown->status);
        $this->assertNotNull($unknown->provider_operation_key);
        $this->assertArrayNotHasKey('dispatch_started_at', $unknown->raw_data['nium_execution']);
        $this->assertNull($unknown->external_transfer_id);
        $this->assertSame('5.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $unknown);
            $this->fail('An uncaptured durable claim cannot be retried.');
        } catch (RuntimeException) {
        }
        config()->set('services.nium.transfer_reconciliation_enabled', true);
        // Without marker/reference/capture, the scheduler deliberately cannot guess the outcome.
        $this->artisan('nium:reconcile-transfers')->assertExitCode(0);
        $this->assertSame('submission_unknown', $unknown->fresh()->status);
        $this->assertSame('5.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public static function finalLockExpiries(): array
    {
        return [['quote'], ['policy']];
    }

    #[DataProvider('finalLockExpiries')]
    public function test_authorization_expiring_while_final_wallet_lock_is_acquired_never_reaches_transport(string $kind): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture(false);
        $this->activateZeroFee($transfer);
        $this->submitSgd($transfer);
        $this->fakeSgdProvider();
        $atWire = false;
        $waitSimulated = false;
        $expiry = $transfer->fxQuote->expires_at;
        Event::listen(RequestSending::class, function (RequestSending $event) use (&$atWire): void {
            if (str_ends_with($event->request->url(), '/remittance')) {
                $atWire = true;
            }
        });
        DB::listen(function (QueryExecuted $query) use (&$atWire, &$waitSimulated, $expiry, $kind): void {
            if ($atWire && str_contains($query->sql, '"balances"') && str_contains($query->sql, 'for update')) {
                $atWire = false;
                $waitSimulated = true;
                // Deterministically model elapsed lock wait after the first authorization check.
                if ($kind === 'quote') {
                    $this->travelTo($expiry);
                } else {
                    config()->set('services.nium.sgd_local_zero_fee.expires_at', now()->toISOString());
                }
            }
        });
        $current = app(TransferApprovalService::class)->approve($transfer->fresh(), $checker, manager: app(ProviderTransferManager::class));
        $this->assertTrue($waitSimulated);
        $this->assertSame('failed', $current->status);
        $this->assertArrayNotHasKey('dispatch_started_at', $current->raw_data['nium_execution']);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'release')->count());
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->travelBack();
    }
}

/** Test-only queue envelope: production Admin Approve invokes this service synchronously. */
class SyntheticSgdSubmissionReplay implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $providerId, public int $transferId) {}

    public function handle(ProviderTransferManager $manager): void
    {
        $manager->submitTransfer(IntegrationProvider::query()->findOrFail($this->providerId), Transfer::query()->findOrFail($this->transferId));
    }
}
