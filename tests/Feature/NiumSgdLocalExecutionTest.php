<?php

namespace Tests\Feature;

use App\Contracts\NiumRemittancePricing;
use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumPayoutFxLockService;
use App\Services\Nium\NiumSgdLocalCugScope;
use App\Services\Nium\NiumSgdLocalOutcomeRecovery;
use App\Services\Nium\NiumSgdLocalPayoutAmounts;
use App\Services\Nium\NiumSgdLocalPayoutGate;
use App\Services\Nium\NiumSgdLocalPayoutWorkflow;
use App\Services\Nium\NiumSgdLocalRemittancePayload;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesSgdLocalExecutionFixtures;
use Tests\Concerns\RefreshesCommittedPayoutDatabase;
use Tests\TestCase;

class NiumSgdLocalExecutionTest extends TestCase
{
    use CreatesSgdLocalExecutionFixtures, RefreshesCommittedPayoutDatabase;

    public function test_registered_local_execution_uses_dynamic_owned_ids_decimal_principal_and_selected_purpose(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture(scenario: [
            'provider_id' => 31, 'user_id' => 72, 'account_id' => 41, 'beneficiary_id' => 55,
            'amount' => '12.34', 'purpose' => 'ALTERNATE_SYNTHETIC_PURPOSE',
        ]);
        $workflow = app(NiumSgdLocalPayoutWorkflow::class);
        $this->assertTrue(app(NiumSgdLocalCugScope::class)->canPrepare($transfer->beneficiary));
        $this->withToken($this->sgdToken($transfer->user))->postJson("/api/user/users/72/transfers/{$transfer->id}/sgd-local-request-approval", $this->reviewToken($transfer))
            ->assertOk()->assertJsonPath('payout_amounts.total_wallet_debit', '12.34');
        $audit = (int) $transfer->fresh()->fxQuote->quote_ref;
        $pending = app(TransferApprovalService::class)->approve($transfer->fresh(), $checker, manager: app(ProviderTransferManager::class));
        $this->assertSame('pending', $pending->status);
        $this->assertSame('12.34000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame('72.66000000', Balance::query()->sole()->available_balance);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->data() === [
            'beneficiary' => ['id' => $transfer->beneficiary->external_beneficiary_id],
            'payout' => ['sourceAmount' => 12.34, 'sourceCurrency' => 'USD', 'auditId' => $audit],
            'purposeCode' => 'ALTERNATE_SYNTHETIC_PURPOSE', 'sourceOfFunds' => 'Corporate Account',
        ]);
        $pending->update(['status' => 'submission_unknown', 'external_transfer_id' => null]);
        $calls = count(Http::recorded());
        $recovered = app(NiumSgdLocalOutcomeRecovery::class)->recover($pending);
        $this->assertSame('submission_unknown', $recovered->status);
        $this->assertSame('synthetic-sgd-reference', $recovered->external_transfer_id);
        $this->assertSame($calls, count(Http::recorded()));
        $this->fakeSgdProvider('completed');
        app(ProviderTransferManager::class)->syncTransferStatus($provider, $recovered);
        $this->assertSame('principal_reconciled', $recovered->fresh()->raw_data['nium_actual_debit']['state'],
            json_encode($recovered->fresh()->raw_data['nium_actual_debit']));
        $this->assertSame('-12.34000000', LedgerEntry::query()->where('entry_type', 'debit')->sole()->amount);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        // A completed payout no longer permanently prohibits another intentional payment.
        $next = $workflow->prepare($transfer->user, ['provider_id' => 31, 'beneficiary_id' => 55,
            'source_currency' => 'USD', 'target_currency' => 'SGD', 'source_amount' => '7.25',
            'purpose_code' => 'ALTERNATE_SYNTHETIC_PURPOSE', 'client_reference' => 'NEXT-INTENTIONAL-PAYMENT',
            'expected_fee_amount' => '0', 'expected_fee_currency' => 'USD']);
        $this->assertNotSame($pending->id, $next->id);
        $next = $workflow->requestLock($next, Str::uuid()->toString());
        $this->assertSame('approval_required', $this->submitSgd($next)->status);
        // Reusable verified configuration authorizes a separately reviewed intent.
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
    }

    public function test_captured_fx_recovery_binds_dynamic_owner_and_principal_without_new_io(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false, ['provider_id' => 31, 'user_id' => 72,
            'account_id' => 41, 'beneficiary_id' => 55, 'amount' => '7.25']);
        $quote = $transfer->fxQuote;
        $raw = $transfer->raw_data;
        $raw['sgd_local_workflow']['state'] = 'lock_unknown';
        $transfer->update(['fx_quote_id' => null, 'fx_rate' => null, 'raw_data' => $raw]);
        $quote->delete();
        $calls = count(Http::recorded());
        $current = app(NiumSgdLocalOutcomeRecovery::class)->recover($transfer);
        $this->assertSame(72, $current->fxQuote->user_id);
        $this->assertSame(31, $current->fxQuote->provider_id);
        $this->assertSame('7.25000000', $current->fxQuote->source_amount);
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_ordinary_registered_payment_uses_verified_pricing_without_any_document_bundle(): void
    {
        [, $transfer] = $this->sgdExecutionFixture();
        config()->set('services.nium.sgd_local_review_bundle', ['path' => '/not-used', 'sha256' => 'not-used']);
        $requested = $this->submitSgd($transfer);
        $this->assertSame('approval_required', $requested->status);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(0, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
    }

    public function test_numeric_wire_principal_never_silently_rounds_the_approved_amount(): void
    {
        $this->assertSame(12.34, NiumSgdLocalRemittancePayload::wireAmount('12.34000000'));
        $this->assertSame(0.00000001, NiumSgdLocalRemittancePayload::wireAmount('0.00000001'));
        $this->expectException(RuntimeException::class);
        NiumSgdLocalRemittancePayload::wireAmount('999999999999.99999999');
    }

    public function test_maker_request_is_persisted_idempotently_and_checker_dispatches_the_bound_intent(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture();
        $makerToken = $this->sgdToken($transfer->user);
        $url = "/api/user/users/12/transfers/{$transfer->id}/sgd-local-request-approval";
        $this->withToken($makerToken)->postJson($url, $this->reviewToken($transfer->fresh()))->assertOk()
            ->assertJsonPath('status', 'approval_required')->assertJsonPath('payout_amounts.total_wallet_debit', '5')
            ->assertJsonPath('payout_amounts.transfer_fee.amount', '0');
        $revision = $transfer->fresh()->raw_data['sgd_local_workflow']['review_revision'];
        $this->withToken($makerToken)->postJson($url, $this->reviewToken($transfer->fresh()))->assertOk();
        $this->assertSame($revision, $transfer->fresh()->raw_data['sgd_local_workflow']['review_revision']);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->withToken($makerToken)->postJson("/api/user/users/12/transfers/{$transfer->id}/submit")->assertUnprocessable();
        $approvedAudit = (int) $transfer->fresh()->fxQuote->quote_ref;
        $this->withToken($this->sgdToken($checker))->postJson("/api/admin/transfers/{$transfer->id}/approve")
            ->assertOk();
        $current = $transfer->fresh();
        $this->assertSame('pending', $current->status);
        $this->assertDatabaseCount('transfer_approvals', 1);
        $hold = LedgerEntry::query()->sole();
        $this->assertSame('-5.00000000', $hold->amount);
        $this->assertSame($current->provider_operation_key, $hold->raw_data['operation_key']);
        $this->assertSame('5', $hold->raw_data['financial_authorization']['total_wallet_debit']);
        $this->assertSame('5', $hold->raw_data['financial_authorization']['business_policy']['reservation_amount']);
        $this->assertSame('80.00000000', Balance::query()->sole()->available_balance);
        $this->assertSame('5.00000000', Balance::query()->sole()->reserved_balance);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->data() === [
            'beneficiary' => ['id' => 'synthetic-external-beneficiary'],
            'payout' => ['sourceAmount' => 5, 'sourceCurrency' => 'USD', 'auditId' => $approvedAudit],
            'purposeCode' => 'SYNTHETIC_PURPOSE', 'sourceOfFunds' => 'Corporate Account',
        ] && $r->hasHeader('x-request-id', substr($current->provider_operation_key, 5)));
        app(TransferApprovalService::class)->approve($current, $checker, manager: app(ProviderTransferManager::class));
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
    }

    public function test_nonzero_confirmed_charge_cannot_expand_principal_only_reservation_policy(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(scenario: ['amount' => '12.34']);
        $this->installSyntheticPricing($transfer, ['fee_amount' => '1.25']);
        $this->expectExceptionMessage(NiumSgdLocalPayoutGate::BLOCK_REASON);
        try {
            $this->submitSgd($transfer);
        } finally {
            $this->assertSame('draft', $transfer->fresh()->status);
            $this->assertDatabaseCount('ledger_entries', 0);
            $this->assertDatabaseCount('transfer_approvals', 0);
            $this->assertSame(0, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        }
    }

    public function test_dynamic_principal_only_policy_is_separate_from_unknown_provider_terms(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false, ['provider_id' => 31, 'user_id' => 72,
            'account_id' => 41, 'beneficiary_id' => 55, 'amount' => '12.34', 'purpose' => 'ALTERNATE_SYNTHETIC_PURPOSE']);
        $expected = ['basis' => 'internal_business_policy', 'currency' => 'USD', 'reservation_amount' => '12.34',
            'expected_principal_debit' => '12.34', 'expected_fee_amount' => '0'];
        $this->assertSame($expected, $transfer->raw_data['sgd_local_workflow']['business_policy']);
        $presentation = app(NiumSgdLocalPayoutWorkflow::class)->presentation($transfer);
        $this->assertSame($expected, $presentation['business_policy']);
        $this->assertSame('unavailable', $presentation['provider_fee_status']);
        $this->assertFalse($presentation['approval_request_allowed']);
        $this->assertNull($presentation['financial_terms_token']);
        $amounts = app(NiumSgdLocalPayoutAmounts::class)->forTransfer($transfer);
        $this->assertNull($amounts['total_wallet_debit']);
        $this->assertNull($amounts['transfer_fee']);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_captured_fx_response_can_restore_the_existing_lock_without_another_get(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $quoteId = $transfer->fx_quote_id;
        $raw = $transfer->raw_data;
        $raw['sgd_local_workflow']['state'] = 'lock_unknown';
        $transfer->update(['fx_quote_id' => null, 'fx_rate' => null, 'raw_data' => $raw]);
        $calls = count(Http::recorded());
        $this->withToken($this->sgdToken($transfer->user))->postJson("/api/user/users/12/transfers/{$transfer->id}/sgd-local-recover-captured")
            ->assertOk()->assertJsonPath('payout_workflow.state', 'locked')->assertJsonPath('payout_workflow.fx_lock_active', true);
        $this->assertSame($quoteId, $transfer->fresh()->fx_quote_id);
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertDatabaseCount('fx_quotes', 1);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_checker_can_resume_a_committed_approval_only_before_any_dispatch_claim(): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture();
        $request = $this->submitSgd($transfer);
        $approved = app(TransferApprovalService::class)->approve($request, $checker);
        $this->assertSame('approved', $approved->status);
        $this->assertTrue(app(NiumSgdLocalPayoutWorkflow::class)->presentation($approved)['checker_approval_allowed']);
        $this->assertNull($approved->provider_operation_key);
        $this->withToken($this->sgdToken($checker))->postJson("/api/admin/transfers/{$approved->id}/approve")->assertOk();
        $this->assertSame('pending', $approved->fresh()->status);
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
    }

    public function test_captured_fx_can_restore_a_quote_that_was_never_persisted(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $quote = $transfer->fxQuote;
        $raw = $transfer->raw_data;
        $raw['sgd_local_workflow']['state'] = 'lock_unknown';
        $transfer->update(['fx_quote_id' => null, 'fx_rate' => null, 'raw_data' => $raw]);
        $quote->delete();
        $calls = count(Http::recorded());
        $this->withToken($this->sgdToken($transfer->user))->postJson("/api/user/users/12/transfers/{$transfer->id}/sgd-local-recover-captured")
            ->assertOk()->assertJsonPath('payout_workflow.state', 'locked');
        $this->assertSame((string) $quote->quote_ref, (string) $transfer->fresh()->fxQuote->quote_ref);
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertDatabaseCount('fx_quotes', 1);
    }

    public function test_recovery_and_review_reset_routes_reject_another_customer(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $other = User::factory()->create(['status' => 'active']);
        $token = $this->sgdToken($other);
        foreach (['sgd-local-refresh-pricing', 'sgd-local-recover-captured', 'sgd-local-restart-review', 'sgd-local-recovery-review'] as $action) {
            $this->withToken($token)->postJson("/api/user/users/12/transfers/{$transfer->id}/{$action}", ['confirm_new_review' => true])
                ->assertForbidden();
        }
    }

    public function test_pricing_change_after_maker_review_and_checker_permission_revocation_block_dispatch(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture();
        $requested = $this->submitSgd($transfer);
        $originalPricing = app(NiumRemittancePricing::class);
        $this->installSyntheticPricing($requested, ['pricing_version' => 'DIFFERENT-SYNTHETIC-PRICING']);
        try {
            app(TransferApprovalService::class)->approve($requested, $checker);
            $this->fail('A different pricing version must require a new maker review');
        } catch (ValidationException) {
        }
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->app->instance(NiumRemittancePricing::class, $originalPricing);
        $approved = app(TransferApprovalService::class)->approve($requested->fresh(), $checker);
        $checker->roles()->delete();
        $this->expectException(RuntimeException::class);
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $approved);
        } finally {
            $this->assertDatabaseCount('ledger_entries', 0);
            $this->assertSame(0, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        }
    }

    public function test_captured_success_restores_missing_reference_but_keeps_unknown_funds_until_reconciliation(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture();
        $request = $this->submitSgd($transfer);
        $pending = app(TransferApprovalService::class)->approve($request, $checker, manager: app(ProviderTransferManager::class));
        // Simulate an application crash after capture, before persisting the reference.
        $pending->update(['status' => 'submission_unknown', 'external_transfer_id' => null]);
        $calls = count(Http::recorded());
        $this->withToken($this->sgdToken($pending->user))->postJson("/api/user/users/12/transfers/{$pending->id}/sgd-local-recover-captured")
            ->assertOk()->assertJsonPath('status', 'submission_unknown')->assertJsonPath('payout_workflow.can_reconcile_status', true);
        $this->assertSame('synthetic-sgd-reference', $pending->fresh()->external_transfer_id);
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertSame('5.00000000', Balance::query()->sole()->reserved_balance);
        $this->fakeSgdProvider('completed');
        app(ProviderTransferManager::class)->syncTransferStatus($provider, $pending->fresh());
        $this->assertSame('completed', $pending->fresh()->status);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
    }

    public function test_uncaptured_or_tampered_response_cannot_guess_a_reference_or_release_funds(): void
    {
        [, $transfer, $checker] = $this->sgdExecutionFixture();
        $request = $this->submitSgd($transfer);
        $this->fakeSgdProvider('timeout');
        $unknown = app(TransferApprovalService::class)->approve($request, $checker, manager: app(ProviderTransferManager::class));
        $url = "/api/user/users/12/transfers/{$unknown->id}/sgd-local-recover-captured";
        $token = $this->sgdToken($unknown->user);
        $this->withToken($token)->postJson($url)->assertUnprocessable();
        $log = ApiRequestLog::query()->where('operation', 'transfer_money')->sole();
        $log->update(['is_success' => true, 'response_status' => 200]);
        $log->niumExchangeEvidence->update(['response_received' => true,
            'raw_response_body' => '{"systemReferenceNumber":"forged"}', 'response_body_sha256' => 'wrong']);
        $this->withToken($token)->postJson($url)->assertUnprocessable();
        $this->assertNull($unknown->fresh()->external_transfer_id);
        $this->assertSame('5.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
    }

    public static function unknownOutcomes(): array
    {
        return array_map(fn ($s) => [$s], ['301', '302', '307', '308', 'timeout', 'missing_reference', '409', '500']);
    }

    #[DataProvider('unknownOutcomes')]
    public function test_uncertain_sgd_dispatch_retains_owned_funds_and_never_repeats(string $outcome): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture();
        $requested = $this->submitSgd($transfer);
        $this->fakeSgdProvider($outcome);
        $unknown = app(TransferApprovalService::class)->approve($requested, $checker, manager: app(ProviderTransferManager::class));
        $this->assertSame('submission_unknown', $unknown->status);
        $this->assertSame('5.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
        app(TransferApprovalService::class)->approve($unknown, $checker, manager: app(ProviderTransferManager::class));
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $unknown);
            $this->fail('Unknown payment must not repeat');
        } catch (RuntimeException) {
        }
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $this->withToken($this->sgdToken($unknown->user))->postJson("/api/user/users/12/transfers/{$unknown->id}/sgd-local-recovery-review")
            ->assertOk()->assertJsonPath('payout_workflow.recovery_kind', 'provider_reference_missing')
            ->assertJsonPath('payout_workflow.can_reconcile_status', false);
        $this->assertSame('5.00000000', Balance::query()->sole()->reserved_balance);
    }

    public static function expiredRestartStatuses(): array
    {
        return [['draft'], ['approval_required']];
    }

    #[DataProvider('expiredRestartStatuses')]
    public function test_expired_review_restart_preserves_transfer_and_appends_history(string $status): void
    {
        [, $transfer] = $this->sgdExecutionFixture();
        $workflow = app(NiumSgdLocalPayoutWorkflow::class);
        if ($status === 'approval_required') {
            $transfer = $this->submitSgd($transfer);
        }
        $token = $this->sgdToken($transfer->user);
        $url = "/api/user/users/12/transfers/{$transfer->id}/sgd-local-restart-review";
        $history = [];
        for ($cycle = 0; $cycle < 2; $cycle++) {
            $before = $transfer->fresh();
            $quote = $before->fxQuote;
            $quoteAttributes = $quote->getAttributes();
            $oldWorkflow = $before->raw_data['sgd_local_workflow'];
            $this->travel(11)->minutes();
            $this->withToken($token)->getJson("/api/user/users/12/transfers/{$transfer->id}")
                ->assertOk()->assertJsonPath('payout_workflow.can_restart_review', true);
            $calls = count(Http::recorded());
            $this->withToken($token)->postJson($url, ['confirm_new_review' => true])
                ->assertOk()->assertJsonPath('id', $transfer->id)->assertJsonPath('status', 'draft')
                ->assertJsonPath('payout_workflow.state', 'draft')
                ->assertJsonPath('payout_workflow.can_restart_review', false);
            $draft = $transfer->fresh();
            $currentWorkflow = $draft->raw_data['sgd_local_workflow'];
            $this->assertNull($draft->fx_quote_id);
            $this->assertNull($draft->fx_rate);
            $this->assertSame($before->client_reference, $draft->client_reference);
            $this->assertSame($oldWorkflow['intent'], $currentWorkflow['intent']);
            $expectedRaw = $before->raw_data;
            unset($expectedRaw['sgd_local_workflow'], $expectedRaw['sgd_local_approval']);
            $actualRaw = $draft->raw_data;
            unset($actualRaw['sgd_local_workflow']);
            $this->assertSame($expectedRaw, $actualRaw);
            $this->assertSame($history, array_slice($currentWorkflow['review_history'], 0, $cycle));
            $entry = $currentWorkflow['review_history'][$cycle];
            $this->assertSame($quote->id, $entry['fx_quote_id']);
            $this->assertSame($oldWorkflow['lock_request_id'], $entry['lock_request_id']);
            $this->assertSame($oldWorkflow['review'] ?? null, $entry['review']);
            foreach (['review', 'review_revision', 'approval_id', 'lock_request_id', 'lock_requested_at', 'recovery_review'] as $key) {
                $this->assertArrayNotHasKey($key, $currentWorkflow);
            }
            $this->assertArrayNotHasKey('sgd_local_approval', $draft->raw_data);
            $this->assertSame($quoteAttributes, $quote->fresh()->getAttributes());
            $history = $currentWorkflow['review_history'];
            $this->assertCount($cycle + 1, $history);
            $this->withToken($token)->postJson($url, ['confirm_new_review' => true])->assertUnprocessable();
            $this->assertSame($draft->getAttributes(), $draft->fresh()->getAttributes());
            $this->assertSame($calls, count(Http::recorded()), 'Restart must use local evidence only.');
            $this->assertDatabaseCount('transfers', 1);
            $this->assertDatabaseCount('transfer_approvals', 0);
            $this->assertDatabaseCount('ledger_entries', 0);
            $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
            $transfer = $workflow->requestLock($draft, Str::uuid()->toString());
            $this->assertSame($draft->id, $transfer->id);
            $this->assertNotSame($quote->id, $transfer->fx_quote_id);
        }
        $this->assertDatabaseCount('fx_quotes', 3);
        Balance::query()->update(['as_of' => now()]); // Explicit synthetic source-wallet resynchronization.
        $this->assertSame('approval_required', $this->submitSgd($transfer)->status);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->travelBack();
    }

    public static function blockedDraftRestarts(): array
    {
        return array_map(fn ($case) => [$case], [
            'active_fx', 'missing_quote', 'missing_expiry', 'consumed_fx', 'payment_operation',
            'external_reference', 'locking', 'lock_unknown', 'submitted', 'changed_wallet',
            'changed_intent', 'reservation', 'debit', 'release',
        ]);
    }

    #[DataProvider('blockedDraftRestarts')]
    public function test_draft_restart_rejects_unsafe_conditions_without_mutation(string $case): void
    {
        [, $transfer] = $this->sgdExecutionFixture();
        $this->travel(11)->minutes();
        $quote = $transfer->fxQuote;
        if ($case === 'active_fx') {
            $this->travelBack();
        } elseif ($case === 'missing_quote') {
            $transfer->update(['fx_quote_id' => null]);
        } elseif ($case === 'missing_expiry') {
            $quote->update(['expires_at' => null]);
        } elseif ($case === 'consumed_fx') {
            $quote->update(['raw_data' => array_merge($quote->raw_data, ['consumed_at' => now()->toISOString()])]);
        } elseif ($case === 'payment_operation') {
            $transfer->update(['provider_operation_key' => Str::uuid()->toString()]);
        } elseif ($case === 'external_reference') {
            $transfer->update(['external_transfer_id' => 'synthetic-existing-payment']);
        } elseif (in_array($case, ['locking', 'lock_unknown'], true)) {
            $raw = $transfer->raw_data;
            $raw['sgd_local_workflow']['state'] = $case;
            $transfer->update(['raw_data' => $raw]);
        } elseif ($case === 'submitted') {
            $transfer->update(['status' => 'pending']);
        } elseif ($case === 'changed_wallet') {
            UserProviderAccount::query()->sole()->update(['external_account_id' => 'different-wallet']);
        } elseif ($case === 'changed_intent') {
            $transfer->update(['source_amount' => '6']);
        } else {
            LedgerEntry::query()->create([
                'balance_id' => Balance::query()->sole()->id, 'user_id' => $transfer->user_id,
                'provider_id' => $transfer->provider_id, 'reference' => 'synthetic-existing-ledger-entry',
                'entry_type' => $case === 'reservation' ? 'hold' : $case, 'currency' => 'USD', 'amount' => '-5',
                'source_type' => 'transfer', 'source_id' => (string) $transfer->id,
            ]);
            Balance::query()->sole()->update(['available_balance' => '80', 'reserved_balance' => '5']);
        }
        $before = $transfer->fresh()->getAttributes();
        $quoteBefore = $quote->fresh()->getAttributes();
        $balanceBefore = Balance::query()->sole()->getAttributes();
        $ledgerBefore = LedgerEntry::all()->toArray();
        $calls = count(Http::recorded());
        $this->withToken($this->sgdToken($transfer->user))->postJson(
            "/api/user/users/12/transfers/{$transfer->id}/sgd-local-restart-review", ['confirm_new_review' => true],
        )->assertUnprocessable();
        $this->assertSame($before, $transfer->fresh()->getAttributes());
        $this->assertSame($quoteBefore, $quote->fresh()->getAttributes());
        $this->assertSame($balanceBefore, Balance::query()->sole()->getAttributes());
        $this->assertSame($ledgerBefore, LedgerEntry::all()->toArray());
        $this->assertSame($calls, count(Http::recorded()));
        $this->travelBack();
    }

    public function test_expired_draft_restart_requires_authentication_ownership_and_explicit_confirmation(): void
    {
        [, $transfer] = $this->sgdExecutionFixture();
        $this->travel(11)->minutes();
        $before = $transfer->fresh()->getAttributes();
        $calls = count(Http::recorded());
        $url = "/api/user/users/12/transfers/{$transfer->id}/sgd-local-restart-review";
        $this->postJson($url, ['confirm_new_review' => true])->assertUnauthorized();
        $other = User::factory()->create(['status' => 'active']);
        $this->withToken($this->sgdToken($other))->postJson($url, ['confirm_new_review' => true])->assertForbidden();
        $this->withToken($this->sgdToken($transfer->user))->postJson($url)->assertUnprocessable();
        $this->assertSame($before, $transfer->fresh()->getAttributes());
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->travelBack();
    }

    public function test_expired_approved_review_restarts_explicitly_preserves_history_and_requires_new_checker(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture();
        $workflow = app(NiumSgdLocalPayoutWorkflow::class);
        $requested = $this->submitSgd($transfer);
        $approved = app(TransferApprovalService::class)->approve($requested, $checker);
        $oldApproval = $approved->raw_data['sgd_local_workflow']['approval_id'];
        $oldAudit = $approved->fxQuote->quote_ref;
        $this->travel(11)->minutes();
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $approved->fresh());
            $this->fail('Expired FX must block dispatch');
        } catch (RuntimeException) {
        }
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertNull($approved->fresh()->provider_operation_key);
        $token = $this->sgdToken($approved->user);
        $this->withToken($token)->postJson("/api/user/users/12/transfers/{$approved->id}/sgd-local-restart-review", ['confirm_new_review' => true])
            ->assertOk()->assertJsonPath('status', 'draft');
        $draft = $approved->fresh();
        $this->assertNull($draft->fx_quote_id);
        $this->assertArrayNotHasKey('sgd_local_approval', $draft->raw_data);
        $this->assertSame($oldApproval, $draft->raw_data['sgd_local_workflow']['review_history'][0]['approval_id']);
        $this->assertDatabaseCount('transfer_approvals', 1);
        $locked = $workflow->requestLock($draft, Str::uuid()->toString());
        $this->assertNotSame($oldAudit, $locked->fxQuote->quote_ref);
        Balance::query()->update(['as_of' => now()]); // A new FX lock does not refresh source-wallet funding.
        $newRequest = $this->submitSgd($locked);
        $this->assertSame('approval_required', $newRequest->status);
        $pending = app(TransferApprovalService::class)->approve($newRequest, $checker, manager: app(ProviderTransferManager::class));
        $this->assertSame('pending', $pending->status);
        $this->assertNotSame($oldApproval, $pending->raw_data['sgd_local_workflow']['approval_id']);
        $this->assertDatabaseCount('transfer_approvals', 2);
        $this->travelBack();
    }

    public function test_maker_or_unauthorized_checker_cannot_approve_even_with_global_approval_disabled(): void
    {
        [, $transfer] = $this->sgdExecutionFixture();
        $requested = $this->submitSgd($transfer);
        config()->set('wallet.transfer_controls.require_admin_approval', false);
        foreach ([$requested->user, User::factory()->create(['status' => 'active'])] as $checker) {
            try {
                app(TransferApprovalService::class)->approve($requested, $checker);
                $this->fail('An independent permitted checker is mandatory');
            } catch (RuntimeException) {
            }
        }
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public static function invalidPricing(): array
    {
        return [
            'unknown' => [['status' => 'unavailable']],
            'unverified charge completeness' => [['charges_complete' => false]],
            'debit allocation' => [['debit_basis' => 'unknown']],
            'fee currency' => [['fee_currency' => 'SGD']],
            'negative fee' => [['fee_amount' => '-1']],
            'float fee' => [['fee_amount' => 0]],
            'numeric debit' => [['total_wallet_debit' => 5]],
            'wrong all-in debit' => [['total_wallet_debit' => '6']],
            'fee precision' => [['fee_amount' => '0.000000001']],
            'customer context' => [['intent' => ['provider_context' => ['wallet' => 'another-wallet']]]],
            'principal' => [['intent' => ['source_amount' => '6']]],
            'purpose' => [['intent' => ['purpose_code' => 'ANOTHER_PURPOSE']]],
            'external beneficiary' => [['intent' => ['external_beneficiary_id' => '9']]],
            'missing pricing version' => [['pricing_version' => '']],
            'expiry' => [['expires_at' => '2000-01-01T00:00:00Z']],
            'relative expiry' => [['expires_at' => 'tomorrow']],
            'invalid calendar expiry' => [['expires_at' => '2036-11-31T00:00:00Z']],
        ];
    }

    #[DataProvider('invalidPricing')]
    public function test_verified_pricing_must_establish_exact_bound_all_in_debit(array $changes): void
    {
        [, $transfer] = $this->sgdExecutionFixture();
        $this->installSyntheticPricing($transfer, $changes);
        $this->expectExceptionMessage(NiumSgdLocalPayoutGate::BLOCK_REASON);
        try {
            $this->submitSgd($transfer);
        } finally {
            $this->assertSame('draft', $transfer->fresh()->status);
            $this->assertDatabaseCount('transfer_approvals', 0);
            $this->assertDatabaseCount('ledger_entries', 0);
        }
    }

    public function test_customer_fee_metadata_and_legacy_bundle_config_cannot_authorize_financial_execution(): void
    {
        [, $transfer] = $this->sgdExecutionFixture(false);
        $raw = $transfer->raw_data;
        $raw['provider_fee_confirmed'] = true;
        $raw['sgd_local_workflow']['business_policy']['basis'] = 'provider_confirmed';
        $raw['sgd_local_workflow']['business_policy']['total_wallet_debit'] = '5';
        $transfer->update(['fee_amount' => 0, 'raw_data' => $raw]);
        config()->set('services.nium.sgd_local_review_bundle', ['path' => '/missing', 'sha256' => str_repeat('0', 64)]);
        $this->expectExceptionMessage(NiumSgdLocalPayoutGate::BLOCK_REASON);
        $this->submitSgd($transfer->fresh());
    }

    public static function terminalOutcomes(): array
    {
        return [['completed', 'debit'], ['failed', 'hold']];
    }

    #[DataProvider('terminalOutcomes')]
    public function test_reconciliation_uses_original_owned_debit_after_fx_or_pricing_expires(string $outcome, string $entry): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture();
        $request = $this->submitSgd($transfer);
        $pending = app(TransferApprovalService::class)->approve($request, $checker, manager: app(ProviderTransferManager::class));
        $this->installSyntheticPricing($pending, ['status' => 'unavailable']);
        $this->travel(11)->minutes();
        $this->fakeSgdProvider($outcome);
        $manager = app(ProviderTransferManager::class);
        $manager->syncTransferStatus($provider, $pending);
        $manager->syncTransferStatus($provider, $pending->fresh());
        $this->assertSame($outcome, $pending->fresh()->status);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', $entry)->count());
        $this->assertSame($outcome === 'completed' ? '0.00000000' : '5.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame($outcome === 'completed' ? '80.00000000' : '85.00000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
        $this->travelBack();
    }

    public function test_used_audit_cannot_be_claimed_again_and_a_shared_audit_rolls_back_reservation(): void
    {
        [$provider, $transfer, $checker] = $this->sgdExecutionFixture();
        $request = $this->submitSgd($transfer);
        $approved = app(TransferApprovalService::class)->approve($request, $checker);
        $copy = $approved->replicate();
        $copy->transfer_no = 'SYNTHETIC-DUPLICATE';
        $copy->client_reference = null;
        $copy->save();
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $approved);
            $this->fail('An audit bound to another transfer must not dispatch');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already bound', $e->getMessage());
        }
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertNull($approved->fresh()->provider_operation_key);
        $copy->delete();
        $pending = app(ProviderTransferManager::class)->submitTransfer($provider, $approved->fresh());
        $this->assertSame($pending->provider_operation_key, $pending->fxQuote->raw_data['consumed_operation_key']);
        $this->expectException(RuntimeException::class);
        DB::transaction(fn () => app(NiumPayoutFxLockService::class)->consumeForSubmission($pending, 'different-operation'));
    }
}
