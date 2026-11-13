<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutInstruction;
use App\Models\AuditLog;
use App\Models\IntegrationProvider;
use App\Models\LedgerEntry;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use App\Models\NiumDynamicReservation;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutAccounting;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutCustomerStatus;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutDispatcher;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutReconciler;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutTransport;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutWebhook;
use App\Services\Nium\DynamicConfirmation\DynamicReservationLedger;
use App\Services\Nium\DynamicConfirmation\DynamicTransferConfirmationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Concerns\CreatesFinalizedUsdConfirmationFixtures;
use Tests\Concerns\CreatesNiumBalanceSyncObservation;
use Tests\Support\DynamicConfirmationDatabaseTestCase;
use Tests\Support\DynamicPayoutTransportStub;
use Throwable;

/** Offline PostgreSQL state-machine tests. The test port does not verify actual Nium accounting. */
final class NiumDynamicPayoutExecutionTest extends DynamicConfirmationDatabaseTestCase
{
    use CreatesDynamicConfirmationFixtures;
    use CreatesFinalizedUsdConfirmationFixtures;
    use CreatesNiumBalanceSyncObservation;

    private DynamicPayoutTransportStub $wire;
    private DynamicPayoutDispatcher $dispatcher;
    private DynamicPayoutReconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDynamicFixtures('2000');
        $this->wallet->update(['currency' => 'USD']);
        $this->source->rule = 'LOCAL_SINGLE_INTENT_PER_QUOTE';
        $this->source->versions['remittance_instruction'] = 'fixture-purpose-codes-v1';
        $this->wire = new DynamicPayoutTransportStub;
        $this->dispatcher = new DynamicPayoutDispatcher($this->confirmation, $this->wire);
        $this->reconciler = new DynamicPayoutReconciler($this->dispatcher, $this->wire,
            new DynamicPayoutAccounting, new DynamicReservationLedger);
        config(['services.nium.base_url' => 'https://gatewaysandbox.nium.com',
            'services.nium.client_id' => 'fixture-client', 'nium_dynamic_payouts.reconciliation_enabled' => true]);
        $this->app->instance(DynamicPayoutDispatcher::class, $this->dispatcher);
        $this->app->instance(DynamicPayoutTransport::class, $this->wire);
    }

    private function prepare(PayoutAmountMode $mode = PayoutAmountMode::Source): NiumDynamicPayoutOutbox
    {
        $this->source->review = $this->usdReview($mode, ['instruction' => new PayoutInstruction('IR005', 'Salary', 'Fixture invoice')]);
        $this->confirmDynamic();
        config(['nium_dynamic_confirmation.automatic_dispatch_enabled' => true]);

        return NiumDynamicPayoutOutbox::query()->sole();
    }

    private function records(NiumDynamicPayoutOutbox $outbox, string $fee = '20'): array
    {
        $scope = ['clientHashId' => 'fixture-client', 'customerHashId' => 'fixture-customer',
            'walletHashId' => 'fixture-wallet', 'authCurrencyCode' => 'USD', 'debit' => true,
            'settlementStatus' => 'Settled', 'status' => 'Approved'];

        return ['records' => [
            [...$scope, 'authCode' => 'RT-fixture-dynamic', 'externalId' => null, 'originalAuthorizationCode' => null,
                'transactionType' => 'Remittance_Debit', 'settlementAuthAmount' => '980',
                'effectiveAuthAmount' => '1000', 'transactionCurrencyCode' => 'GBP',
                'settlementTransactionAmount' => '784', 'labels' => ['beneficiaryId' => 'fixture-registered-payee']],
            [...$scope, 'authCode' => 'FT-fixture-fee', 'transactionType' => 'Fee_Debit', 'settlementAuthAmount' => $fee,
                'labels' => ['originalAuthorizationCode' => 'RT-fixture-dynamic', 'feeName' => 'REMIT_BANK_FEE']],
        ], 'response_sha256' => [str_repeat('a', 64)], 'observed_at' => now()->toISOString()];
    }

    public function test_sync_during_authenticated_reconciliation_cannot_apply_the_provider_debit_twice(): void
    {
        $outbox = $this->prepare();
        $this->dispatcher->dispatch($outbox->id);
        $this->observeNiumBalance('1000');
        $this->assertSame('2000.00000000', $this->wallet->fresh()->ledger_balance);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->wire->batch = $this->records($outbox->fresh());
        $this->reconciler->reconcile($outbox->id);
        $this->observeNiumBalance('1000');
        $this->reconciler->reconcile($outbox->id);
        $this->assertSame('matched', $outbox->fresh()->state);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->ledger_balance);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('0.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame(1, $this->wire->submissions);
    }

    public static function modes(): array
    {
        return [[PayoutAmountMode::Source], [PayoutAmountMode::Destination]];
    }

    #[DataProvider('modes')]
    public function test_confirmed_amount_is_reserved_dispatched_once_and_consumed_only_after_matched_accounting(PayoutAmountMode $mode): void
    {
        $outbox = $this->prepare($mode);
        $intent = $this->confirmDynamic();
        $this->assertSame($outbox->intent_id, $intent->id);
        $persisted = $intent->fresh();
        $this->assertSame($this->source->review->fingerprint(), $persisted->terms_fingerprint);
        $this->assertSame($persisted->terms_fingerprint,
            hash('sha256', json_encode($persisted->confirmed_terms, JSON_THROW_ON_ERROR)));
        $this->assertSame($this->source->review->quote->expiresAt->format('U.u'),
            $persisted->quote_expires_at->format('U.u'));
        $this->dispatcher->dispatch($outbox->id);
        $this->dispatcher->dispatch($outbox->id);
        $this->assertSame(1, $this->wire->submissions);
        $body = json_decode($this->wire->bodies[0], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($intent->quote_id, $body['payout']['quoteId']);
        $this->assertSame($mode === PayoutAmountMode::Source ? 980 : 784, $body['payout'][$mode->quoteField()]);
        $this->assertArrayNotHasKey($mode === PayoutAmountMode::Source ? 'destinationAmount' : 'sourceAmount', $body['payout']);
        $this->assertSame('PROVIDER_ACCOUNTING_UNVERIFIED', $outbox->fresh()->financial_risk_state);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->wire->batch = $this->records($outbox->fresh());
        $this->reconciler->reconcile($outbox->id);
        $this->reconciler->reconcile($outbox->id);
        $this->assertSame('matched', $outbox->fresh()->state);
        $this->assertSame('1000', $outbox->fresh()->financial_evidence['wallet_debit_usd']);
        $this->assertSame('consumed', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame('-1000.00000000', LedgerEntry::query()->where('entry_type', 'debit')->sole()->amount);
        $this->assertSame('0.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->ledger_balance);
    }

    public static function databaseTimezones(): array
    {
        return [['UTC'], ['Asia/Bangkok']];
    }

    #[DataProvider('databaseTimezones')]
    public function test_consent_claim_and_scheduled_reconciliation_preserve_instants_across_database_timezones(string $zone): void
    {
        $original = DB::selectOne("SELECT current_setting('TimeZone') AS zone")->zone;
        DB::selectOne("SELECT set_config('TimeZone', ?, false)", [$zone]);
        try {
            $outbox = $this->prepare();
            $intent = NiumDynamicIntent::query()->findOrFail($outbox->intent_id);
            $this->assertSame($this->source->review->quote->expiresAt->format('U.u'),
                $intent->quote_expires_at->format('U.u'));
            $this->assertSame($this->at()->format('U.u'), $intent->consented_at->format('U.u'));
            $this->assertSame($this->source->review->fingerprint(), $intent->terms_fingerprint);
            $this->dispatcher->dispatch($outbox->id);
            $current = $outbox->fresh();
            $this->assertSame('submitted', $current->state);
            $this->assertSame(1, $this->wire->submissions);
            $this->assertSame($this->at()->format('U.u'), $current->claimed_at->format('U.u'));
            $this->assertSame($this->at()->format('U.u'), $current->submission_started_at->format('U.u'));
            $this->assertSame($this->at()->format('U.u'), $current->next_reconciliation_at->format('U.u'));
            $this->wire->batch = $this->records($current);
            $this->artisan('nium:process-dynamic-payouts', ['--limit' => 1])->assertSuccessful();
            $this->assertSame(1, $this->wire->reads);
            $this->assertSame(1, $this->wire->submissions);
            $this->assertSame('matched', $outbox->fresh()->state);
            $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        } finally {
            DB::selectOne("SELECT set_config('TimeZone', ?, false)", [$original]);
        }
    }

    public function test_dispatch_requires_automatic_reconciliation_before_claiming(): void
    {
        $outbox = $this->prepare();
        config(['nium_dynamic_payouts.reconciliation_enabled' => false]);
        try {
            $this->dispatcher->dispatch($outbox->id);
            $this->fail('Dispatch accepted without automatic reconciliation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('dynamic_dispatch_requires_reconciliation', $exception->getMessage());
        }
        $this->assertSame('held', $outbox->fresh()->state);
        $this->assertSame(0, $outbox->fresh()->attempts);
        $this->assertSame(0, $this->wire->submissions);
    }

    public function test_terminal_fee_record_preserves_hold_and_requires_review(): void
    {
        $outbox = $this->prepare();
        $this->dispatcher->dispatch($outbox->id);
        $this->wire->batch = $this->records($outbox->fresh());
        $this->wire->batch['records'][1]['status'] = 'Failed';
        $this->reconciler->reconcile($outbox->id);
        $this->assertSame('exception', $outbox->fresh()->state);
        $this->assertSame('PROVIDER_EXCEPTION_REQUIRES_REVIEW', $outbox->fresh()->financial_risk_state);
        $this->assertNull($outbox->fresh()->next_reconciliation_at);
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
    }

    public function test_quote_expiry_immediately_before_wire_keeps_the_hold_and_sends_nothing(): void
    {
        $outbox = $this->prepare();
        $this->wire->beforeWire = fn () => $this->travelTo($this->at('01:00:00'));
        $this->dispatcher->dispatch($outbox->id);
        $this->assertSame(0, $this->wire->submissions);
        $this->assertSame('exception', $outbox->fresh()->state);
        $this->assertSame(0, $outbox->fresh()->attempts);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
    }

    public function test_submission_marker_save_veto_cannot_send_an_unrecorded_payout(): void
    {
        $outbox = $this->prepare();
        $event = 'eloquent.updating: '.NiumDynamicPayoutOutbox::class;
        Event::listen($event, static fn ($row) => $row->attempts === 1 ? false : null);
        try {
            $this->dispatcher->dispatch($outbox->id);
        } finally {
            Event::forget($event);
        }
        $this->assertSame(0, $this->wire->submissions);
        $this->assertSame(0, $outbox->fresh()->attempts);
        $this->assertSame('exception', $outbox->fresh()->state);
    }

    public function test_interrupted_claim_is_never_reclaimed_for_submission(): void
    {
        $outbox = $this->prepare();
        $this->wire->afterWire = static fn () => throw new RuntimeException('lost result');
        $this->dispatcher->dispatch($outbox->id);
        // Simulate a crash after the durable marker, before outcome persistence.
        $row = $outbox->fresh();
        $row->forceFill(['state' => 'claimed'])->save();
        $this->travelTo($this->at('00:13:00'));
        $this->dispatcher->recoverInterrupted($outbox->id);
        $this->dispatcher->dispatch($outbox->id);
        $this->assertSame('ambiguous', $outbox->fresh()->state);
        $this->assertSame(1, $this->wire->submissions);
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
    }

    public function test_changed_consent_instruction_or_missing_authoritative_source_cannot_dispatch(): void
    {
        $outbox = $this->prepare();
        $this->source->review = $this->usdReview(changes: ['instruction' => new PayoutInstruction('IR005', 'Salary', 'Changed invoice')]);
        $this->dispatcher->dispatch($outbox->id);
        $this->assertSame('exception', $outbox->fresh()->state);
        $this->assertSame(0, $this->wire->submissions);
        $this->assertSame('dynamic_execution_consent_or_terms_changed', $outbox->fresh()->exception_code);
    }

    public function test_missing_evidence_source_does_not_promote_business_preparation_to_dispatch_authority(): void
    {
        $outbox = $this->prepare();
        $this->assertSame(0, DB::transactionLevel());
        $dispatcher = new DynamicPayoutDispatcher(new DynamicTransferConfirmationService(new DynamicReservationLedger), $this->wire);
        $dispatcher->dispatch($outbox->id);
        $this->assertSame('dynamic_authoritative_evidence_source_unavailable', $outbox->fresh()->exception_code);
        $this->assertSame('exception', $outbox->fresh()->state);
        $this->assertSame(0, $outbox->fresh()->attempts);
        $this->assertSame(0, $this->wire->submissions);
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
    }

    public function test_execution_refresh_is_rejected_inside_a_transaction_before_calling_the_evidence_source(): void
    {
        $outbox = $this->prepare();
        $intent = NiumDynamicIntent::query()->findOrFail($outbox->intent_id);
        // The service contract rejects before the evidence stub refresh hook.
        // The stub uses an ordinary RuntimeException rather than this typed failure.
        $this->expectException(\App\Services\Wallet\Concurrency\OwnerLockContractViolation::class);
        DB::transaction(fn () => $this->confirmation->refreshForExecution($intent));
    }

    public function test_timeout_never_reposts_and_requires_an_authenticated_provider_reference_for_recovery(): void
    {
        $outbox = $this->prepare();
        $this->wire->afterWire = static fn () => throw new RuntimeException('synthetic timeout after sending');
        $this->dispatcher->dispatch($outbox->id);
        $this->assertSame('ambiguous', $outbox->fresh()->state);
        $this->assertNull($outbox->fresh()->provider_reference);
        $this->observeNiumBalance('1000');
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame('2000.00000000', $this->wallet->fresh()->ledger_balance);
        $this->dispatcher->dispatch($outbox->id);
        $this->assertSame(1, $this->wire->submissions);
        $this->wire->batch = $this->records($outbox->fresh());
        // Even a matching local externalId cannot establish the provider reference.
        $this->wire->batch['records'][0]['externalId'] = $outbox->fresh()->operation_key;
        $this->reconciler->reconcile($outbox->id);
        $this->assertNull($outbox->fresh()->provider_reference);
        $this->assertSame('ambiguous', $outbox->fresh()->state);
        $this->assertSame('pending', $outbox->fresh()->financial_state);
        $this->assertSame('dynamic_remittance_reference_missing', $outbox->fresh()->exception_code);
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
        // Existing authenticated webhook binding supplies the missing reference.
        $this->webhook($outbox, 'PAID');
        $this->wire->batch['records'][0]['externalId'] = null;
        $this->reconciler->reconcile($outbox->id);
        $this->assertSame('RT-fixture-dynamic', $outbox->fresh()->provider_reference);
        $this->assertSame('matched', $outbox->fresh()->state);
        $this->assertSame(1, $this->wire->submissions);
    }

    public function test_provider_2xx_without_a_reference_is_ambiguous_and_never_replayed(): void
    {
        $outbox = $this->prepare();
        $transport = new class($this->wire) implements DynamicPayoutTransport {
            public function __construct(private DynamicPayoutTransportStub $wire) {}

            public function submit(NiumDynamicIntent $intent, NiumDynamicPayoutOutbox $outbox,
                \App\Services\Nium\DynamicConfirmation\DynamicPayoutWireClaim $claim): array
            {
                $this->wire->submit($intent, $outbox, $claim);
                return ['http_status' => 200, 'provider_reference' => null];
            }

            public function transactions(NiumDynamicIntent $intent): array
            {
                return $this->wire->transactions($intent);
            }
        };
        $dispatcher = new DynamicPayoutDispatcher($this->confirmation, $transport);
        $dispatcher->dispatch($outbox->id);
        $dispatcher->dispatch($outbox->id);
        $this->assertSame(1, $this->wire->submissions);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $this->assertSame('ambiguous', $outbox->fresh()->state);
        $this->assertNull($outbox->fresh()->provider_reference);
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
    }

    private function webhook(NiumDynamicPayoutOutbox $outbox, string $status, string $reference = 'RT-fixture-dynamic'): void
    {
        $observed = (new DynamicPayoutWebhook($this->dispatcher))->observe(
            IntegrationProvider::query()->findOrFail($this->account->provider_id), [
                'externalId' => $outbox->fresh()->operation_key, 'systemReferenceNumber' => $reference,
                'status' => $status, 'clientHashId' => 'fixture-client',
                'customerHashId' => 'fixture-customer', 'walletHashId' => 'fixture-wallet',
            ], []);
        $this->assertTrue($observed);
    }

    private function customerStatus(NiumDynamicPayoutOutbox $outbox): array
    {
        return (new DynamicPayoutCustomerStatus)->project(
            NiumDynamicIntent::query()->findOrFail($outbox->intent_id), $outbox->fresh(),
            NiumDynamicReservation::query()->where('intent_id', $outbox->intent_id)->sole());
    }

    public static function terminalRemittanceStatuses(): array
    {
        return [['Returned', 'RETURNED', 'returned'], ['Failed', 'FAILED', 'failed'],
            ['Declined', 'REJECTED', 'failed'], ['Cancelled', 'CANCELLED', 'failed']];
    }

    #[DataProvider('terminalRemittanceStatuses')]
    public function test_get_alone_synchronizes_failure_and_return_status_without_webhook_or_repost(
        string $providerStatus, string $storedStatus, string $customerStatus): void
    {
        $outbox = $this->prepare();
        $this->dispatcher->dispatch($outbox->id);
        $this->wire->batch = $this->records($outbox->fresh());
        $this->wire->batch['records'][0]['status'] = $providerStatus;
        $this->reconciler->reconcile($outbox->id);
        $this->dispatcher->dispatch($outbox->id);
        $this->assertSame('exception', $outbox->fresh()->state);
        $this->assertSame($storedStatus, $outbox->fresh()->provider_status);
        $this->assertSame($customerStatus, $this->customerStatus($outbox)['status']);
        $this->assertFalse($this->customerStatus($outbox)['financial_reconciled']);
        $this->assertSame(1, $this->wire->submissions);
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame(0, LedgerEntry::query()->whereIn('entry_type', ['debit', 'release'])->count());
    }

    public function test_paid_callback_before_timeout_preserves_reference_and_requires_accounting_before_paid_status(): void
    {
        $outbox = $this->prepare();
        $this->wire->afterWire = function () use ($outbox): void {
            $this->webhook($outbox, 'PAID');
            throw new RuntimeException('timeout after provider callback');
        };
        $this->dispatcher->dispatch($outbox->id);
        $this->assertSame('ambiguous', $outbox->fresh()->state);
        $this->assertSame('RT-fixture-dynamic', $outbox->fresh()->provider_reference);
        $this->assertSame('PAID', $outbox->fresh()->provider_status);
        $this->assertSame('pending_provider_confirmation', $this->customerStatus($outbox)['status']);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->wire->batch = $this->records($outbox->fresh());
        $this->reconciler->reconcile($outbox->id);
        $this->webhook($outbox, 'Approved');
        $this->dispatcher->dispatch($outbox->id);
        $this->assertSame('paid', $this->customerStatus($outbox)['status']);
        $this->assertTrue($this->customerStatus($outbox)['financial_reconciled']);
        $this->assertSame(1, $this->wire->submissions);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
    }

    public function test_get_can_report_paid_and_later_return_preserves_the_single_consumed_debit(): void
    {
        $outbox = $this->prepare();
        $this->dispatcher->dispatch($outbox->id);
        $this->wire->batch = $this->records($outbox->fresh());
        $this->wire->batch['records'][0]['status'] = 'Paid';
        $this->reconciler->reconcile($outbox->id);
        $this->assertSame('paid', $this->customerStatus($outbox)['status']);
        $this->webhook($outbox, 'RETURNED');
        $this->assertSame('returned', $this->customerStatus($outbox)['status']);
        $this->assertSame('consumed', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
    }

    public function test_post_response_cannot_replace_a_reference_observed_in_an_earlier_callback(): void
    {
        $outbox = $this->prepare();
        $this->wire->afterWire = fn () => $this->webhook($outbox, 'PAID', 'RT-conflicting-reference');
        $this->dispatcher->dispatch($outbox->id);
        $this->dispatcher->dispatch($outbox->id);
        $this->assertSame('exception', $outbox->fresh()->state);
        $this->assertSame('dynamic_provider_reference_conflict', $outbox->fresh()->exception_code);
        $this->assertSame('RT-conflicting-reference', $outbox->fresh()->provider_reference);
        $this->assertSame(1, $this->wire->submissions);
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
    }

    public function test_wrong_wallet_callback_cannot_supply_a_reference_for_an_unknown_submission(): void
    {
        $outbox = $this->prepare();
        $this->wire->afterWire = static fn () => throw new RuntimeException('unknown provider outcome');
        $this->dispatcher->dispatch($outbox->id);
        try {
            (new DynamicPayoutWebhook($this->dispatcher))->observe(
                IntegrationProvider::query()->findOrFail($this->account->provider_id), [
                    'externalId' => $outbox->fresh()->operation_key, 'systemReferenceNumber' => 'RT-fixture-dynamic',
                    'status' => 'PAID', 'clientHashId' => 'fixture-client',
                    'customerHashId' => 'fixture-customer', 'walletHashId' => 'another-wallet',
                ], []);
            $this->fail('Callback for another wallet was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('dynamic_webhook_scope_unverified', $exception->getMessage());
        }
        $this->assertSame('ambiguous', $outbox->fresh()->state);
        $this->assertNull($outbox->fresh()->provider_reference);
        $this->assertNull($outbox->fresh()->provider_status);
        $this->assertSame(1, $this->wire->submissions);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
    }

    public function test_return_during_post_is_not_overwritten_by_response_paid_or_late_failure(): void
    {
        $outbox = $this->prepare();
        $this->wire->afterWire = fn () => $this->webhook($outbox, 'RETURNED');
        $this->dispatcher->dispatch($outbox->id);
        $this->webhook($outbox, 'PAID');
        $this->webhook($outbox, 'FAILED');
        $this->dispatcher->dispatch($outbox->id);
        $this->assertSame('exception', $outbox->fresh()->state);
        $this->assertSame('RETURNED', $outbox->fresh()->provider_status);
        $this->assertSame('RT-fixture-dynamic', $outbox->fresh()->provider_reference);
        $this->assertSame('returned', $this->customerStatus($outbox)['status']);
        $this->assertSame(1, $this->wire->submissions);
        $this->assertSame(0, LedgerEntry::query()->whereIn('entry_type', ['debit', 'release'])->count());
    }

    public function test_missing_fee_records_are_pending_then_match_without_assuming_zero(): void
    {
        $outbox = $this->prepare();
        $this->dispatcher->dispatch($outbox->id);
        $this->wire->batch = $this->records($outbox->fresh());
        array_pop($this->wire->batch['records']);
        $this->wire->batch['records'][0]['status'] = 'PAID';
        $this->reconciler->reconcile($outbox->id);
        $this->assertSame('pending', $outbox->fresh()->financial_state);
        $this->assertNotNull($outbox->fresh()->next_reconciliation_at);
        $this->assertNull($outbox->fresh()->provider_status);
        $this->assertNotSame('paid', $this->customerStatus($outbox)['status']);
        $receipt = $outbox->fresh()->financial_evidence;
        $this->assertSame('980', $receipt['observed_remittance_debit_usd']);
        $this->assertNull($receipt['principal_usd']);
        $this->assertNull($receipt['fee_usd']);
        $this->assertNull($receipt['wallet_debit_usd']);
        $this->assertSame('20', $receipt['customer_fee_allocation_usd']);
        $this->assertSame('MISSING_FEE_RECORDS', $receipt['fee_evidence']);
        $this->assertSame([], $receipt['provider_fee_records']);
        $this->dispatcher->dispatch($outbox->id);
        $this->assertSame(1, $this->wire->submissions);
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame('2000.00000000', $this->wallet->fresh()->ledger_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->wire->batch = $this->records($outbox->fresh());
        $this->travelTo($this->at('00:12:00'));
        $this->reconciler->reconcile($outbox->id);
        $this->assertSame('matched', $outbox->fresh()->state);
        $this->assertSame('SETTLED_FEE_RECORDS', $outbox->fresh()->financial_evidence['fee_evidence']);
        $this->assertSame('20', $outbox->fresh()->financial_evidence['fee_usd']);
        $this->dispatcher->dispatch($outbox->id);
        $this->assertSame(1, $this->wire->submissions);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
    }

    public function test_provider_mismatch_consumes_only_customer_consent_alerts_operations_and_blocks_wallet(): void
    {
        $outbox = $this->prepare();
        $this->dispatcher->dispatch($outbox->id);
        $this->wire->batch = $this->records($outbox->fresh(), '21');
        $this->reconciler->reconcile($outbox->id);
        $this->reconciler->reconcile($outbox->id);
        $this->assertSame('mismatched', $outbox->fresh()->financial_state);
        $this->assertSame('exception', $outbox->fresh()->state);
        $this->assertSame('1001', $outbox->fresh()->financial_evidence['wallet_debit_usd']);
        $this->assertSame(1, AuditLog::query()->where('action', 'nium_dynamic_financial_exception')->count());
        $this->assertSame('0.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->ledger_balance);
        $this->assertSame('consumed', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame('-1000.00000000', LedgerEntry::query()->where('entry_type', 'debit')->sole()->amount);
        $this->expectExceptionMessage('dynamic_wallet_has_unresolved_payout');
        $this->confirmDynamic('different-customer-reference');
    }

    #[DataProvider('modes')]
    public function test_destination_credit_difference_becomes_an_exception_without_extra_debit_or_second_post(PayoutAmountMode $mode): void
    {
        $outbox = $this->prepare($mode);
        $this->dispatcher->dispatch($outbox->id);
        $this->wire->batch = $this->records($outbox->fresh());
        $this->wire->batch['records'][0]['settlementTransactionAmount'] = '783';
        $this->reconciler->reconcile($outbox->id);
        $this->reconciler->reconcile($outbox->id);
        $this->dispatcher->dispatch($outbox->id);
        $this->assertSame('exception', $outbox->fresh()->state);
        $this->assertSame('mismatched', $outbox->fresh()->financial_state);
        $this->assertSame('784', $outbox->fresh()->financial_evidence['intended_destination_amount']);
        $this->assertSame('783', $outbox->fresh()->financial_evidence['destination_amount']);
        $this->assertSame('1000', $outbox->fresh()->financial_evidence['customer_authorized_debit_usd']);
        $this->assertSame('requires_attention', $this->customerStatus($outbox)['status']);
        $this->assertFalse($this->customerStatus($outbox)['financial_reconciled']);
        $this->assertSame('consumed', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame('-1000.00000000', LedgerEntry::query()->where('entry_type', 'debit')->sole()->amount);
        $this->assertSame(1, $this->wire->submissions);
        $this->assertSame(1, $outbox->fresh()->attempts);
    }

    public function test_missing_records_exhaust_read_only_budget_without_reposting(): void
    {
        $outbox = $this->prepare();
        config(['nium_dynamic_payouts.max_reconciliation_attempts' => 2]);
        $this->dispatcher->dispatch($outbox->id);
        $this->wire->batch = ['records' => [], 'response_sha256' => [str_repeat('a', 64)], 'observed_at' => now()->toISOString()];
        $this->reconciler->reconcile($outbox->id);
        $this->travelTo($this->at('00:12:00'));
        $this->reconciler->reconcile($outbox->id);
        $this->reconciler->reconcile($outbox->id);
        $this->assertSame('unavailable', $outbox->fresh()->financial_state);
        $this->assertSame('dynamic_reconciliation_exhausted', $outbox->fresh()->exception_code);
        $this->assertNull($outbox->fresh()->next_reconciliation_at);
        $this->assertSame(2, $this->wire->reads);
        $this->assertSame(1, $this->wire->submissions);
    }

    public function test_wrong_wallet_is_unavailable_evidence_and_not_a_confirmed_mismatch(): void
    {
        $outbox = $this->prepare();
        $this->dispatcher->dispatch($outbox->id);
        $this->wire->batch = $this->records($outbox->fresh());
        $this->wire->batch['records'][1]['walletHashId'] = 'other-wallet';
        $this->reconciler->reconcile($outbox->id);
        $this->assertSame('unavailable', $outbox->fresh()->financial_state);
        $this->assertNull($outbox->fresh()->financial_evidence);
        $this->assertSame('held', NiumDynamicReservation::query()->sole()->state);
    }

    public static function lateTerminalStates(): array
    {
        return [['Returned', 'RETURNED', 'returned'], ['Failed', 'FAILED', 'failed']];
    }

    #[DataProvider('lateTerminalStates')]
    public function test_bounded_post_match_get_recovers_a_terminal_result_without_webhook_or_another_debit(
        string $providerState, string $storedState, string $customerState): void
    {
        $outbox = $this->prepare();
        $this->dispatcher->dispatch($outbox->id);
        $this->wire->batch = $this->records($outbox->fresh());
        $this->reconciler->reconcile($outbox->id);
        $this->assertNotNull($outbox->fresh()->next_reconciliation_at);
        $this->travelTo(now()->addSeconds(3601));
        $this->wire->batch['records'][0]['status'] = $providerState;
        $this->reconciler->reconcile($outbox->id);
        $this->dispatcher->dispatch($outbox->id);
        $this->assertSame($storedState, $outbox->fresh()->provider_status);
        $this->assertSame($customerState, $this->customerStatus($outbox)['status']);
        $this->assertSame('consumed', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame('1000', $outbox->fresh()->financial_evidence['wallet_debit_usd']);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
        $this->assertSame(1, $this->wire->submissions);
    }

    public function test_post_match_read_budget_is_independent_and_never_reconsumes_the_reservation(): void
    {
        config(['nium_dynamic_payouts.post_match_reconciliation_delays_seconds' => [60, 120]]);
        $outbox = $this->prepare();
        $this->dispatcher->dispatch($outbox->id);
        $this->wire->batch = $this->records($outbox->fresh());
        $this->reconciler->reconcile($outbox->id);
        foreach ([61, 121] as $seconds) {
            $this->travelTo(now()->addSeconds($seconds));
            $this->reconciler->reconcile($outbox->id);
        }
        $this->reconciler->reconcile($outbox->id);
        $current = $outbox->fresh();
        $this->assertSame('matched', $current->state);
        $this->assertNull($current->next_reconciliation_at);
        $this->assertSame(1, $current->reconciliation_attempts);
        $this->assertSame(2, $current->financial_evidence['post_match_recovery']['attempts']);
        $this->assertSame(3, $this->wire->reads);
        $this->assertSame(1, $this->wire->submissions);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
    }

    public function test_post_match_mismatch_preserves_original_receipt_and_records_the_discrepancy(): void
    {
        $outbox = $this->prepare();
        $this->dispatcher->dispatch($outbox->id);
        $this->wire->batch = $this->records($outbox->fresh());
        $this->reconciler->reconcile($outbox->id);
        $this->travelTo(now()->addSeconds(3601));
        $this->wire->batch = $this->records($outbox->fresh(), '40');
        $this->reconciler->reconcile($outbox->id);
        $current = $outbox->fresh();
        $this->assertSame('exception', $current->state);
        $this->assertSame('1000', $current->financial_evidence['wallet_debit_usd']);
        $this->assertSame('1020', $current->financial_evidence['post_match_recovery']['last_receipt']['wallet_debit_usd']);
        $this->assertSame('consumed', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
        $this->assertSame(1, $this->wire->submissions);
    }

    public function test_return_webhook_during_post_match_read_cannot_be_cleared_by_its_stale_success(): void
    {
        $outbox = $this->prepare();
        $this->dispatcher->dispatch($outbox->id);
        $this->wire->batch = $this->records($outbox->fresh());
        $this->reconciler->reconcile($outbox->id);
        $this->wire->duringRead = function (): void {
            (new DynamicPayoutWebhook($this->dispatcher))->observe(IntegrationProvider::query()->findOrFail($this->account->provider_id), [
                'template' => 'REMIT_TRANSACTION_RETURNED_WEBHOOK', 'systemReferenceNumber' => 'RT-fixture-dynamic',
                'clientHashId' => 'fixture-client', 'customerHashId' => 'fixture-customer', 'walletHashId' => 'fixture-wallet',
            ], []);
        };
        $this->travelTo(now()->addSeconds(3601));
        $this->reconciler->reconcile($outbox->id);
        $this->assertSame('RETURNED', $outbox->fresh()->provider_status);
        $this->assertSame('exception', $outbox->fresh()->state);
        $this->assertSame('consumed', NiumDynamicReservation::query()->sole()->state);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame(1, $this->wire->submissions);
    }

    public function test_post_match_read_outage_preserves_original_accounting_and_exhausts_only_recovery_budget(): void
    {
        config(['nium_dynamic_payouts.post_match_reconciliation_delays_seconds' => [60]]);
        $outbox = $this->prepare();
        $this->dispatcher->dispatch($outbox->id);
        $this->wire->batch = $this->records($outbox->fresh());
        $this->reconciler->reconcile($outbox->id);
        $this->wire->duringRead = static fn () => throw new RuntimeException('read outage');
        $this->travelTo(now()->addSeconds(61));
        $this->reconciler->reconcile($outbox->id);
        $this->assertSame('matched', $outbox->fresh()->financial_state);
        $this->assertSame('matched', $outbox->fresh()->state);
        $this->assertSame('unavailable', $outbox->fresh()->financial_evidence['post_match_recovery']['last_result']);
        $this->assertNull($outbox->fresh()->next_reconciliation_at);
        $this->assertSame('1000', $outbox->fresh()->financial_evidence['wallet_debit_usd']);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
    }

    public function test_duplicate_authenticated_return_webhooks_preserve_debit_and_never_repost(): void
    {
        $outbox = $this->prepare();
        $this->dispatcher->dispatch($outbox->id);
        $this->wire->batch = $this->records($outbox->fresh());
        $this->reconciler->reconcile($outbox->id);
        config(['services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'fixture-webhook-key']);
        $this->withHeader('x-partner-key', 'fixture-webhook-key')->postJson('/api/webhooks/providers/nium', [
            'eventId' => 'fixture-dynamic-paid', 'template' => 'REMIT_TRANSACTION_PAID_WEBHOOK',
            'systemReferenceNumber' => 'RT-fixture-dynamic', 'clientHashId' => 'fixture-client',
            'customerHashId' => 'fixture-customer', 'walletHashId' => 'fixture-wallet',
        ])->assertOk();
        $this->assertSame('paid', $this->customerStatus($outbox)['status']);
        $payload = ['eventId' => 'fixture-dynamic-return', 'template' => 'REMIT_TRANSACTION_RETURNED_WEBHOOK',
            'systemReferenceNumber' => 'RT-fixture-dynamic', 'clientHashId' => 'fixture-client',
            'customerHashId' => 'fixture-customer', 'walletHashId' => 'fixture-wallet'];
        for ($i = 0; $i < 2; $i++) {
            $this->withHeader('x-partner-key', 'fixture-webhook-key')->postJson('/api/webhooks/providers/nium', $payload)->assertOk();
        }
        $this->dispatcher->dispatch($outbox->id);
        $this->assertSame('RETURNED', $outbox->fresh()->provider_status);
        $this->assertSame('exception', $outbox->fresh()->state);
        $this->assertSame(1, $this->wire->submissions);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
        $this->assertSame(1, AuditLog::query()->where('entity_id', $outbox->id)->count());
    }

    public function test_return_during_get_cannot_be_overwritten_by_stale_matched_accounting(): void
    {
        $outbox = $this->prepare();
        $this->dispatcher->dispatch($outbox->id);
        $this->wire->batch = $this->records($outbox->fresh());
        $this->wire->duringRead = function () use ($outbox): void {
            (new DynamicPayoutWebhook($this->dispatcher))->observe(IntegrationProvider::query()->findOrFail($this->account->provider_id), [
                'template' => 'REMIT_TRANSACTION_RETURNED_WEBHOOK', 'systemReferenceNumber' => 'RT-fixture-dynamic',
                'clientHashId' => 'fixture-client', 'customerHashId' => 'fixture-customer', 'walletHashId' => 'fixture-wallet',
            ], []);
        };
        $this->reconciler->reconcile($outbox->id);
        $this->assertSame('exception', $outbox->fresh()->state);
        $this->assertSame('RETURNED', $outbox->fresh()->provider_status);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
    }

    public function test_concurrent_dispatch_workers_issue_one_wire_attempt(): void
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Execution concurrency verification requires pcntl.');
        $outbox = $this->prepare();
        $start = tempnam(sys_get_temp_dir(), 'dynamic-execution-start-');
        $log = tempnam(sys_get_temp_dir(), 'dynamic-execution-wire-');
        $outputs = [tempnam(sys_get_temp_dir(), 'dynamic-execution-worker-'), tempnam(sys_get_temp_dir(), 'dynamic-execution-worker-')];
        $children = [];
        $this->wire->wireLog = $log;
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
                        while (file_get_contents($start) !== 'go') {
                            if (microtime(true) > $deadline) {
                                throw new RuntimeException('concurrency_barrier_timeout');
                            }
                            usleep(1000);
                        }
                        $this->source->duringValidation = static fn () => usleep(100000);
                        $this->dispatcher->dispatch($outbox->id);
                        file_put_contents($outputs[$worker], 'ok');
                    } catch (Throwable $e) {
                        file_put_contents($outputs[$worker], $e::class.': '.$e->getMessage());
                    }
                    DB::disconnect();
                    exit(0);
                }
                $this->assertGreaterThan(0, $pid);
                $children[] = $pid;
            }
            file_put_contents($start, 'go');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            DB::purge();
            DB::reconnect();
            $this->assertSame(['ok', 'ok'], array_map('file_get_contents', $outputs));
            $this->assertCount(1, file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
            $this->assertSame(1, $outbox->fresh()->attempts);
            $this->assertSame('submitted', $outbox->fresh()->state);
        } finally {
            file_put_contents($start, 'go');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
            }
            foreach ([$start, $log, ...$outputs] as $file) {
                unlink($file);
            }
            DB::purge();
            DB::reconnect();
        }
    }
}
