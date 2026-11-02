<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumDataSyncService;
use App\Services\Nium\NiumPayoutDebitReconciliation;
use App\Services\Nium\NiumTransferService;
use App\Services\Wallet\LedgerService;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesSgdLocalExecutionFixtures;
use Tests\Concerns\RefreshesCommittedPayoutDatabase;
use Tests\TestCase;

class NiumPayoutDebitReconciliationTest extends TestCase
{
    use CreatesSgdLocalExecutionFixtures, RefreshesCommittedPayoutDatabase;

    private function pending(): Transfer
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture(scenario: ['amount' => '12.34']);

        return app(TransferApprovalService::class)->approve($this->submitSgd($transfer),
            $checker, manager: app(ProviderTransferManager::class));
    }

    private function principal(Transfer $transfer, array $changes = []): array
    {
        return array_replace(['authCode' => $transfer->external_transfer_id, 'transactionType' => 'Remittance_Debit_External',
            'clientHashId' => 'synthetic-client', 'customerHashId' => 'synthetic-customer', 'walletHashId' => 'synthetic-wallet',
            'authCurrencyCode' => 'USD', 'debit' => true, 'status' => 'Approved', 'settlementStatus' => 'Settled',
            'authAmount' => '12.34', 'settlementAuthAmount' => '12.34'], $changes);
    }

    private function fee(Transfer $transfer, string $amount = '1.25', string $id = 'synthetic-fee'): array
    {
        return $this->principal($transfer, ['authCode' => $id, 'transactionType' => 'Fee_Debit',
            'authAmount' => $amount, 'settlementAuthAmount' => $amount,
            'labels' => ['originalAuthorizationCode' => $transfer->external_transfer_id, 'feeName' => 'REMIT_BANK_FEE']]);
    }

    private function activity(array $rows, string $status = 'PAID', int $httpStatus = 200, ?array $metadata = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request, $options) use ($rows, $status, $httpStatus, $metadata) {
            $this->assertSame('GET', $request->method());
            if (str_contains($request->url(), '/transactions')) {
                $this->assertFalse($options['allow_redirects']);

                return Http::response(array_merge(['content' => $rows, 'totalPages' => count($rows) ? 1 : 0,
                    'totalElements' => count($rows)], $metadata ?? []), $httpStatus);
            }

            return Http::response([['status' => $status]]);
        });
    }

    private function sync(Transfer $transfer): Transfer
    {
        return app(ProviderTransferManager::class)->syncTransferStatus($transfer->provider, $transfer->fresh());
    }

    public function test_status_alone_never_settles_or_releases_and_delayed_evidence_settles_once(): void
    {
        $pending = $this->pending();
        $this->activity([]);
        $completed = $this->sync($pending);
        app(LedgerService::class)->settleTransfer($completed);
        $this->assertSame('completed', $completed->status);
        $this->assertSame('12.34000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->activity([$this->principal($pending)]);
        $this->sync($completed);
        $this->sync($completed);
        $this->assertSame('72.66000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame('not_proven_by_absence_of_fee_rows', $completed->fresh()->raw_data['nium_actual_debit']['charge_completeness']);
    }

    public function test_late_separate_fees_append_once_without_rewriting_the_original_debit(): void
    {
        $transfer = $this->pending();
        $this->activity([$this->principal($transfer)]);
        $this->sync($transfer);
        $before = LedgerEntry::query()->where('entry_type', 'debit')->sole()->toArray();
        $this->activity([$this->principal($transfer), $this->fee($transfer)]);
        $this->sync($transfer);
        $this->sync($transfer);
        $this->assertSame($before, LedgerEntry::query()->where('entry_type', 'debit')->sole()->toArray());
        $this->assertSame('-1.25000000', LedgerEntry::query()->where('entry_type', 'adjustment')->sole()->amount);
        $this->assertSame('71.41000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame('71.41000000', Balance::query()->sole()->available_balance);
        $this->assertSame('13.59000000', $transfer->fresh()->raw_data['nium_actual_debit']['observed_total_debit']);
        $this->assertSame('unexpected_charges_reconciled', $transfer->fresh()->raw_data['nium_actual_debit']['state']);
        $this->activity([$this->principal($transfer), $this->fee($transfer, '2.50')]);
        $this->sync($transfer);
        $this->assertSame('71.41000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame('awaiting_evidence', $transfer->fresh()->raw_data['nium_actual_debit']['state']);
    }

    public static function invalidRows(): array
    {
        return [
            'foreign client' => [['clientHashId' => 'other-client']],
            'foreign customer' => [['customerHashId' => 'other-customer']],
            'foreign wallet' => [['walletHashId' => 'other-wallet']],
            'wrong currency' => [['authCurrencyCode' => 'SGD']],
            'credit' => [['debit' => false]],
            'unsettled' => [['settlementStatus' => 'Unsettled']],
            'declined' => [['status' => 'Declined']],
            'source mismatch' => [['authAmount' => '11', 'settlementAuthAmount' => '11']],
            'missing settled amount' => [['settlementAuthAmount' => null]],
            'changed amount' => [['settlementAuthAmount' => '12.35']],
            'negative amount' => [['settlementAuthAmount' => '-12.34']],
            'excess precision' => [['settlementAuthAmount' => '12.340000001']],
            'foreign reference' => [['authCode' => 'other-payment']],
            'missing reference' => [['authCode' => null]],
            'unsupported type' => [['transactionType' => 'Wallet_Fund_Transfer']],
            'released debit' => [['settlementStatus' => 'Released']],
        ];
    }

    #[DataProvider('invalidRows')]
    public function test_incomplete_or_mismatched_financial_evidence_retains_the_owned_reservation(array $changes): void
    {
        $transfer = $this->pending();
        $this->activity([$this->principal($transfer, $changes)]);
        $this->sync($transfer);
        $this->assertSame('85.00000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame('12.34000000', Balance::query()->sole()->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame('awaiting_evidence', $transfer->fresh()->raw_data['nium_actual_debit']['state']);
    }

    public function test_duplicate_rows_and_truncated_pagination_are_not_complete_evidence(): void
    {
        $transfer = $this->pending();
        foreach ([[$this->principal($transfer), $this->principal($transfer)], [$this->principal($transfer)]] as $rows) {
            $this->activity($rows, metadata: count($rows) === 1 ? ['totalElements' => 2] : null);
            $this->sync($transfer);
            $this->assertDatabaseCount('ledger_entries', 1);
            $this->assertSame('12.34000000', Balance::query()->sole()->reserved_balance);
        }
    }

    public function test_bad_capture_hash_and_stale_operation_cannot_authorize_debits(): void
    {
        $transfer = $this->pending();
        $this->activity([$this->principal($transfer)]);
        $prepared = app(NiumTransferService::class)->prepareTransferStatusSync($transfer->provider, $transfer);
        $log = ApiRequestLog::query()->where('operation', 'payout_wallet_activity')->sole();
        $log->niumExchangeEvidence->update(['response_body_sha256' => str_repeat('0', 64)]);
        DB::transaction(function () use ($transfer, $prepared): void {
            $locked = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            app(NiumTransferService::class)->applyPreparedTransferStatus($locked->provider, $locked, $prepared);
        });
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame('12.34000000', Balance::query()->sole()->reserved_balance);
        $capture = app(NiumPayoutDebitReconciliation::class)->prepare($transfer->fresh());
        $capture['operation_key'] = 'another-operation';
        DB::transaction(fn () => app(NiumPayoutDebitReconciliation::class)->apply(Transfer::query()->lockForUpdate()->findOrFail($transfer->id), $capture));
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_failed_status_and_reversal_evidence_require_controlled_financial_review(): void
    {
        $transfer = $this->pending();
        $this->activity([], 'FAILED');
        $failed = $this->sync($transfer);
        app(LedgerService::class)->releaseTransferHold($failed);
        $this->assertSame('failed', $failed->status);
        $this->assertSame('12.34000000', Balance::query()->sole()->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 1);
        $reversal = $this->fee($transfer);
        $reversal['transactionType'] = 'Remittance_Reversal';
        $reversal['debit'] = false;
        $this->activity([$this->principal($transfer), $reversal], 'FAILED');
        $this->sync($failed);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_authenticated_webhook_status_is_not_financial_evidence_and_is_duplicate_safe(): void
    {
        $transfer = $this->pending();
        $payload = ['eventId' => 'synthetic-paid', 'eventType' => 'remittance.completed', 'data' => ['resource' => [
            'systemReferenceNumber' => $transfer->external_transfer_id, 'transactionId' => 'synthetic-transaction', 'status' => 'COMPLETED']]];
        $this->withHeader('x-partner-key', 'synthetic-only')->postJson('/api/webhooks/providers/nium', $payload)->assertOk();
        $this->withHeader('x-partner-key', 'synthetic-only')->postJson('/api/webhooks/providers/nium', $payload)->assertOk();
        $this->assertSame('completed', $transfer->fresh()->status);
        $this->assertSame('12.34000000', Balance::query()->sole()->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_redirect_timeout_and_missing_reference_preserve_funds_without_repost(): void
    {
        $transfer = $this->pending();
        foreach ([302, 503] as $status) {
            $this->activity([], httpStatus: $status);
            $this->sync($transfer);
            $this->assertDatabaseCount('ledger_entries', 1);
        }
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('Synthetic timeout'));
        $capture = app(NiumPayoutDebitReconciliation::class)->prepare($transfer->fresh());
        $this->assertArrayHasKey('error', $capture);
        $transfer->update(['external_transfer_id' => null]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertArrayHasKey('error', app(NiumPayoutDebitReconciliation::class)->prepare($transfer->fresh()));
        Http::assertNothingSent();
        $this->assertSame('12.34000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
    }

    public function test_provider_balance_sync_does_not_double_apply_a_provider_debit(): void
    {
        $transfer = $this->pending();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([['currency' => 'USD', 'walletHashId' => 'synthetic-wallet', 'availableBalance' => 72.66]])]);
        app(NiumDataSyncService::class)->syncBalances($transfer->provider, $transfer->user);
        $this->assertSame('85.00000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame('12.34000000', Balance::query()->sole()->reserved_balance);
        $this->activity([$this->principal($transfer)]);
        $this->sync($transfer);
        $this->assertSame('72.66000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame('72.66000000', Balance::query()->sole()->available_balance);
    }

    public function test_scheduler_revisits_completed_payouts_for_delayed_fees(): void
    {
        $transfer = $this->pending();
        $this->activity([$this->principal($transfer)]);
        $this->sync($transfer);
        $this->activity([$this->principal($transfer), $this->fee($transfer)]);
        config()->set('services.nium.transfer_reconciliation_enabled', true);
        $this->artisan('nium:reconcile-transfers')->assertExitCode(0);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'adjustment')->count());
        $this->assertSame('71.41000000', Balance::query()->sole()->ledger_balance);
    }

    public static function returnChannels(): array
    {
        return ['status polling' => [false], 'authenticated webhook' => [true]];
    }

    private function returnWebhook(Transfer $transfer): array
    {
        return ['eventId' => 'synthetic-return', 'eventType' => 'remittance.returned', 'data' => ['resource' => [
            'systemReferenceNumber' => $transfer->external_transfer_id, 'status' => 'RETURN']]];
    }

    #[DataProvider('returnChannels')]
    public function test_sgd_return_is_a_failed_lifecycle_without_inventing_a_refund(bool $webhook): void
    {
        $transfer = $this->pending();
        $this->activity([], 'RETURN');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($webhook) {
                $this->withHeader('x-partner-key', 'synthetic-only')
                    ->postJson('/api/webhooks/providers/nium', $this->returnWebhook($transfer))->assertOk();
            } else {
                $this->sync($transfer);
            }
        }
        // The existing scheduler must continue looking for financial evidence after RETURN.
        config()->set('services.nium.transfer_reconciliation_enabled', true);
        $this->artisan('nium:reconcile-transfers')->assertExitCode(0);
        $this->assertSame('failed', $transfer->fresh()->status);
        $this->assertSame('RETURN', $transfer->fresh()->provider_status);
        $this->assertSame('failed', $transfer->fresh()->raw_data['nium_execution']['terminal_status']);
        $this->assertSame('awaiting_evidence', $transfer->fresh()->raw_data['nium_actual_debit']['state']);
        $this->assertSame('85.00000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame('12.34000000', Balance::query()->sole()->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/remittance'));
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
    }

    #[DataProvider('returnChannels')]
    public function test_return_after_paid_keeps_the_settled_history_until_refund_evidence_is_supported(bool $webhook): void
    {
        $transfer = $this->pending();
        $this->activity([$this->principal($transfer)]);
        $this->sync($transfer);
        $before = LedgerEntry::query()->orderBy('id')->get()->toArray();
        $this->activity([], 'RETURN');
        if ($webhook) {
            $this->withHeader('x-partner-key', 'synthetic-only')
                ->postJson('/api/webhooks/providers/nium', $this->returnWebhook($transfer))->assertUnprocessable();
        } else {
            try {
                $this->sync($transfer);
                $this->fail('A returned lifecycle cannot reverse a paid debit without refund evidence.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('conflicts with an existing terminal status', $exception->getMessage());
            }
        }
        $this->assertSame('completed', $transfer->fresh()->status);
        $this->assertSame($before, LedgerEntry::query()->orderBy('id')->get()->toArray());
        $this->assertSame('72.66000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/remittance'));
    }

    #[DataProvider('returnChannels')]
    public function test_return_token_preserves_the_existing_hk_usd_status_behavior(bool $webhook): void
    {
        $transfer = $this->pending();
        $transfer->update(['target_currency' => 'USD']);
        $transfer->beneficiary->update(['country_code' => 'HK', 'currency' => 'USD', 'payout_method' => 'SWIFT']);
        $this->activity([], 'RETURN');
        if ($webhook) {
            $this->withHeader('x-partner-key', 'synthetic-only')
                ->postJson('/api/webhooks/providers/nium', $this->returnWebhook($transfer))->assertOk();
        } else {
            $this->sync($transfer);
        }
        $this->assertSame('pending', $transfer->fresh()->status);
        $this->assertSame('12.34000000', Balance::query()->sole()->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/remittance'));
    }

    public static function missingReferenceStates(): array
    {
        return ['crashed before saving response' => ['submitting', null],
            'unknown with empty reference' => ['submission_unknown', '']];
    }

    #[DataProvider('missingReferenceStates')]
    public function test_scheduler_recovers_a_captured_reference_and_reconciles_once_without_customer_action(string $status, ?string $reference): void
    {
        $transfer = $this->pending();
        $principal = $this->principal($transfer);
        $operation = $transfer->provider_operation_key;
        $transfer->update(['status' => $status, 'external_transfer_id' => $reference]);
        $this->travel(2)->days(); // Reconcile the original dispatch even after FX/pricing expiry.
        $this->activity([$principal]);
        config()->set('services.nium.transfer_reconciliation_enabled', true);
        $this->artisan('nium:reconcile-transfers')->assertExitCode(0);
        $this->artisan('nium:reconcile-transfers')->assertExitCode(0);
        $current = $transfer->fresh();
        $this->assertSame('completed', $current->status);
        $this->assertSame('synthetic-sgd-reference', $current->external_transfer_id);
        $this->assertSame($operation, $current->provider_operation_key);
        $this->assertArrayHasKey('captured_outcome_recovery', $current->raw_data['sgd_local_workflow']);
        $this->assertSame('principal_reconciled', $current->raw_data['nium_actual_debit']['state']);
        $this->assertSame('72.66000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/remittance'));
    }

    public static function invalidRecoveryEvidence(): array
    {
        return ['uncaptured timeout' => ['timeout'], 'invalid response hash' => ['hash'],
            'mismatched request intent' => ['request'], 'foreign wallet' => ['wallet']];
    }

    #[DataProvider('invalidRecoveryEvidence')]
    public function test_scheduler_cannot_guess_a_missing_reference_or_retry_an_unknown_submission(string $case): void
    {
        $transfer = $this->pending();
        $transfer->update(['status' => 'submission_unknown', 'external_transfer_id' => null]);
        $log = ApiRequestLog::query()->where('operation', 'transfer_money')->sole();
        if ($case === 'timeout') {
            $log->update(['is_success' => false, 'response_status' => null]);
            $log->niumExchangeEvidence->update(['response_received' => false, 'raw_response_body' => null]);
        } elseif ($case === 'hash') {
            $log->niumExchangeEvidence->update(['response_body_sha256' => str_repeat('0', 64)]);
        } elseif ($case === 'request') {
            $body = json_decode($log->niumExchangeEvidence->raw_request_body, true, 512, JSON_THROW_ON_ERROR);
            $body['payout']['sourceAmount'] = 99;
            $raw = json_encode($body, JSON_THROW_ON_ERROR);
            $log->niumExchangeEvidence->update(['raw_request_body' => $raw, 'request_body_sha256' => hash('sha256', $raw)]);
        } else {
            $transfer->user->providerAccounts()->update(['external_account_id' => 'another-wallet']);
        }
        $before = LedgerEntry::query()->orderBy('id')->get()->toArray();
        $this->activity([]);
        config()->set('services.nium.transfer_reconciliation_enabled', true);
        $this->artisan('nium:reconcile-transfers')->assertExitCode(1);
        $this->artisan('nium:reconcile-transfers')->assertExitCode(1);
        $this->assertNull($transfer->fresh()->external_transfer_id);
        $this->assertSame('submission_unknown', $transfer->fresh()->status);
        $this->assertSame('12.34000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame($before, LedgerEntry::query()->orderBy('id')->get()->toArray());
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        Http::assertNothingSent();
    }

    public function test_uncaptured_missing_reference_rotates_out_of_a_bounded_reconciliation_batch(): void
    {
        $unknown = $this->pending();
        // updated_at is not fillable; persist the fixture's ordering explicitly.
        $unknown->forceFill(['status' => 'submission_unknown', 'external_transfer_id' => null, 'updated_at' => now()->subMinutes(2)])->save();
        ApiRequestLog::query()->where('operation', 'transfer_money')->sole()->niumExchangeEvidence
            ->update(['response_body_sha256' => str_repeat('0', 64)]);
        $known = $unknown->replicate(['provider_operation_key']);
        $known->transfer_no = 'SYNTHETIC-KNOWN-REFERENCE';
        $known->client_reference = 'SYNTHETIC-KNOWN-REFERENCE';
        $known->external_transfer_id = 'synthetic-known-reference';
        $known->status = 'pending';
        $known->raw_data = [];
        $known->updated_at = now()->subMinute();
        $known->save();
        $this->assertTrue($unknown->fresh()->updated_at->lt($known->fresh()->updated_at));
        $this->activity([], 'PENDING');
        config()->set('services.nium.transfer_reconciliation_enabled', true);
        $this->artisan('nium:reconcile-transfers', ['--limit' => 1])
            ->expectsOutput('Nium reconciliation complete: 0 reconciled, 0 skipped, 1 failed.')
            ->assertExitCode(1);
        Http::assertNothingSent();
        $this->assertTrue($unknown->fresh()->updated_at->gt($known->fresh()->updated_at));
        $this->artisan('nium:reconcile-transfers', ['--limit' => 1])
            ->expectsOutput('Nium reconciliation complete: 1 reconciled, 0 skipped, 0 failed.')
            ->assertExitCode(0);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'synthetic-known-reference'));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/remittance'));
        $this->assertNull($unknown->fresh()->external_transfer_id);
        $this->assertSame('submission_unknown', $unknown->fresh()->status);
        $this->assertSame('12.34000000', Balance::query()->sole()->reserved_balance);
    }

    public function test_exact_provider_numeric_tokens_and_unexpected_overdrawn_fees_are_accounted_without_clamping(): void
    {
        $transfer = $this->pending();
        $principal = $this->principal($transfer);
        $fee = $this->fee($transfer, '72.66000001');
        // Raw JSON numbers exercise lossless parsing, rather than PHP float projections.
        $raw = json_encode(['content' => [$principal, $fee], 'totalPages' => 1, 'totalElements' => 2]);
        $raw = str_replace('"72.66000001"', '72.66000001', $raw);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => Http::response(str_contains($request->url(), '/transactions') ? $raw : '[{"status":"PAID"}]'));
        $this->sync($transfer);
        $this->assertSame('-0.00000001', Balance::query()->sole()->ledger_balance);
        $this->assertSame('-0.00000001', Balance::query()->sole()->available_balance);
        $this->assertSame('85.00000001', $transfer->fresh()->raw_data['nium_actual_debit']['observed_total_debit']);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
    }

    public function test_an_existing_owned_principal_debit_is_verified_without_debiting_it_again(): void
    {
        $transfer = $this->pending();
        Balance::query()->sole()->update(['ledger_balance' => '72.66', 'reserved_balance' => '0']);
        LedgerEntry::query()->create(['balance_id' => Balance::query()->sole()->id, 'user_id' => $transfer->user_id,
            'provider_id' => $transfer->provider_id, 'reference' => "transfer:{$transfer->id}:debit", 'entry_type' => 'debit',
            'currency' => 'USD', 'amount' => '-12.34', 'source_type' => 'transfer', 'source_id' => (string) $transfer->id,
            'raw_data' => ['operation_key' => $transfer->provider_operation_key]]);
        $this->activity([$this->principal($transfer), $this->fee($transfer)]);
        $this->sync($transfer);
        $this->sync($transfer);
        $this->assertSame('71.41000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'adjustment')->count());
    }

    public function test_a_reversal_linked_to_a_posted_fee_requires_review_without_overwriting_history(): void
    {
        $transfer = $this->pending();
        $this->activity([$this->principal($transfer), $this->fee($transfer)]);
        $this->sync($transfer);
        $before = LedgerEntry::query()->get()->toArray();
        $reversal = $this->fee($transfer, id: 'synthetic-fee-reversal');
        $reversal['transactionType'] = 'Fee_Reversal';
        $reversal['debit'] = false;
        $reversal['labels']['originalAuthorizationCode'] = 'synthetic-fee';
        $this->activity([$this->principal($transfer), $this->fee($transfer), $reversal]);
        $this->sync($transfer);
        $this->assertSame('awaiting_evidence', $transfer->fresh()->raw_data['nium_actual_debit']['state']);
        $this->assertSame('71.41000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame($before, LedgerEntry::query()->get()->toArray());
    }

    public function test_unrelated_adjustments_do_not_become_provider_fee_evidence_and_foreign_fee_owners_are_rejected(): void
    {
        $transfer = $this->pending();
        LedgerEntry::query()->create(['balance_id' => Balance::query()->sole()->id, 'user_id' => $transfer->user_id,
            'provider_id' => $transfer->provider_id, 'reference' => 'synthetic-unrelated-adjustment', 'entry_type' => 'adjustment',
            'currency' => 'USD', 'amount' => '-2', 'source_type' => 'transfer', 'source_id' => (string) $transfer->id]);
        $this->activity([$this->principal($transfer), $this->fee($transfer)]);
        $this->sync($transfer);
        $this->assertSame('13.59000000', $transfer->fresh()->raw_data['nium_actual_debit']['observed_total_debit']);
        $fee = LedgerEntry::query()->where('reference', 'like', 'nium:wallet-debit:%')->where('entry_type', 'adjustment')->sole();
        $fee->update(['raw_data' => array_replace($fee->raw_data, ['operation_key' => 'foreign-operation'])]);
        $this->sync($transfer);
        $this->assertSame('awaiting_evidence', $transfer->fresh()->raw_data['nium_actual_debit']['state']);
        $this->assertSame('71.41000000', Balance::query()->sole()->ledger_balance);
    }

    public function test_current_wallet_changes_and_foreign_hold_balance_prevent_financial_mutation(): void
    {
        $transfer = $this->pending();
        $this->activity([$this->principal($transfer)]);
        $transfer->user->providerAccounts()->update(['external_account_id' => 'another-wallet']);
        $capture = app(NiumPayoutDebitReconciliation::class)->prepare($transfer->fresh());
        $this->assertArrayHasKey('error', $capture);
        Http::assertNothingSent();
        $transfer->user->providerAccounts()->update(['external_account_id' => 'synthetic-wallet']);
        Balance::query()->sole()->update(['external_account_id' => 'another-wallet']);
        $this->sync($transfer);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame('12.34000000', Balance::query()->sole()->reserved_balance);
    }

    public function test_late_financial_evidence_can_reconcile_an_already_terminal_payout_despite_an_older_status(): void
    {
        $transfer = $this->pending();
        $this->activity([]);
        $this->sync($transfer);
        $transfer->refresh()->update(['provider_status_at' => now()]);
        $this->activity([$this->principal($transfer)]);
        $prepared = app(NiumTransferService::class)->prepareTransferStatusSync($transfer->provider, $transfer->fresh());
        $prepared['status_at'] = now()->subDay();
        $prepared['status'] = 'pending';
        DB::transaction(function () use ($transfer, $prepared): void {
            $locked = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            app(NiumTransferService::class)->applyPreparedTransferStatus($locked->provider, $locked, $prepared);
        });
        $this->assertSame('completed', $transfer->fresh()->status);
        $this->assertSame('72.66000000', Balance::query()->sole()->ledger_balance);
    }
}
