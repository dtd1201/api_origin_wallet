<?php

namespace Tests\Feature;

use App\Contracts\NiumRemittancePricing;
use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumPayoutFinancialControls;
use App\Services\Nium\NiumPayoutFxLockContract;
use App\Services\Nium\NiumSgdLocalPayoutWorkflow;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesSgdLocalExecutionFixtures;
use Tests\Concerns\RefreshesCommittedPayoutDatabase;
use Tests\TestCase;

/** Offline adapter contracts. Existing synthetic pricing fixtures are not provider evidence. */
class NiumStandardTransferAdapterTest extends TestCase
{
    use CreatesSgdLocalExecutionFixtures, RefreshesCommittedPayoutDatabase;

    private function fixture(bool $withPricing = true): Transfer
    {
        [, $transfer] = $this->sgdExecutionFixture($withPricing, [
            'user_id' => 44, 'beneficiary_id' => 45, 'account_id' => 61, 'amount' => '7.25',
        ]);
        $transfer->update(['reference_text' => 'STANDARD-REFERENCE']);
        $transfer->beneficiary->update(['account_number' => '0123456789']);
        $this->withToken($this->sgdToken($transfer->user));

        return $transfer->fresh(['user', 'provider', 'beneficiary', 'fxQuote']);
    }

    private function input(Transfer $fixture, bool $existing = false): array
    {
        return ['provider_id' => $fixture->provider_id, 'beneficiary_id' => $fixture->beneficiary_id,
            'source_amount' => $fixture->source_amount, 'purpose_code' => $fixture->purpose_code,
            'reference_text' => $fixture->reference_text,
            'client_reference' => $existing ? $fixture->client_reference : 'STANDARD-NEW'];
    }

    private function url(Transfer $fixture): string
    {
        return "/api/user/users/{$fixture->user_id}/transfers";
    }

    private function submitInput(array $input, string $token): array
    {
        return [...$input, 'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'SGD',
            'review_token' => $token, 'accept_payment_terms' => true, 'purpose_confirmed' => true];
    }

    private function locksSent(): int
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), 'lockExchangeRate'))->count();
    }

    private function assertNoDispatch(): void
    {
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('transfer_approvals', 0);
    }

    private function standardSubmitted(): array
    {
        $fixture = $this->fixture();
        $input = $this->input($fixture);
        $review = $this->postJson($this->url($fixture).'/review', $input)->assertOk();
        $payload = $this->submitInput($input, $review->json('review_token'));
        $this->postJson($this->url($fixture), $payload)->assertCreated()->assertJsonPath('status', 'approval_required');

        return [$fixture, Transfer::query()->findOrFail($review->json('id')), $payload];
    }

    private function checker(): User
    {
        return User::query()->whereHas('roles', fn ($query) => $query->where('role_code', 'admin'))->firstOrFail();
    }

    public function test_standard_customer_acceptance_projects_the_exact_admin_approval_and_resume_contract(): void
    {
        [$fixture, $transfer] = $this->standardSubmitted();
        $receipt = $transfer->raw_data['sgd_local_workflow']['customer_authorization'];
        $instruction = $receipt['instruction'];
        $checker = $this->checker();
        $calls = count(Http::recorded());
        $customerDetail = $this->getJson($this->url($fixture)."/{$transfer->id}")->assertOk();
        $customerList = $this->getJson($this->url($fixture))->assertOk();
        $listed = collect($customerList->json())->firstWhere('id', $transfer->id);
        foreach ([$customerDetail->json(), $listed] as $saved) {
            foreach (['id', 'provider_id', 'beneficiary_id', 'client_reference', 'purpose_code', 'reference_text'] as $field) {
                $this->assertSame($transfer->{$field}, $saved[$field]);
            }
            $this->assertSame('approval_required', $saved['status']);
        }

        $this->withToken($this->sgdToken($checker));
        $url = "/api/admin/transfers/{$transfer->id}";
        foreach (['approval_required', 'approved'] as $status) {
            if ($status === 'approved') {
                // Commit approval without invoking the dispatcher, exactly as the resume boundary.
                $transfer = app(TransferApprovalService::class)->approve($transfer, $checker);
            }
            $detail = $this->getJson($url)->assertOk()
                ->assertJsonPath('status', $status)
                ->assertJsonPath('allowed_actions.approve', true)
                ->assertJsonPath('allowed_actions.reject', true)
                ->assertJsonPath('allowed_actions.sync', true)
                ->assertJsonPath('payout_workflow.state', $status)
                ->assertJsonPath('payout_workflow.checker_approval_allowed', true)
                ->assertJsonPath('payout_workflow.fx_lock_active', true)
                ->assertJsonPath('payout_workflow.dispatch_owner', 'checker_approval')
                ->assertJsonPath('payout_workflow.dispatch_allowed', false)
                ->assertJsonPath('payout_workflow.provider_fee_status', 'confirmed')
                ->assertJsonPath('payout_workflow.blockers', [])
                ->assertJsonPath('payout_workflow.recovery_kind', null)
                ->assertJsonPath('payout_amounts.transfer_fee.amount', '0')
                ->assertJsonPath('payout_amounts.transfer_fee.currency', 'USD')
                ->assertJsonPath('payout_amounts.total_wallet_debit', '7.25')
                ->assertJsonMissingPath('payout_review');
            $workflow = $detail->json('payout_workflow');
            $amounts = $detail->json('payout_amounts');
            $this->assertTrue(NiumPayoutFxLockContract::sameBinding($receipt, $workflow['customer_authorization']));
            $this->assertTrue(NiumPayoutFxLockContract::sameBinding($instruction, $workflow['customer_instruction']));
            $this->assertSame($receipt['submission_token'], $workflow['submission_token']);
            $this->assertSame(NiumSgdLocalPayoutWorkflow::financialTermsToken($instruction), $workflow['submission_token']);
            $this->assertSame('6789', $workflow['customer_instruction']['receiving_account']['last_four']);
            $this->assertSame($transfer->fx_quote_id, $instruction['fx_quote_id']);
            $this->assertSame((string) $transfer->fxQuote->quote_ref, $instruction['audit_id']);
            $this->assertSame($instruction['rate'], $amounts['fx_rate']);
            $this->assertSame(strtotime($instruction['expires_at']), strtotime($amounts['fx_lock_expires_at']));
            $this->assertSame($instruction['source_currency'], $amounts['source_currency']);
            $this->assertSame($instruction['destination_currency'], $amounts['destination_currency']);
            $this->assertSame($instruction['source_amount'], NiumPayoutFxLockContract::decimal($amounts['source_amount']));
            $this->assertGreaterThan(now()->timestamp, strtotime($instruction['financial_terms']['expires_at']));
            $this->assertNull($transfer->fresh()->provider_operation_key);
        }
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(2, $this->locksSent());
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public static function receivingAccountTypeChangeStages(): array
    {
        return ['before customer acceptance' => ['acceptance'], 'before checker approval' => ['approval'],
            'after approval before dispatch' => ['resume']];
    }

    /** Provider-facing account edits invalidate the exact accepted instruction at every boundary. */
    #[DataProvider('receivingAccountTypeChangeStages')]
    public function test_changed_registered_account_type_requires_new_customer_acceptance(string $stage): void
    {
        $fixture = $this->fixture();
        $raw = $fixture->beneficiary->raw_data ?? [];
        $raw['nium']['beneficiaryBankAccountType'] = 'Current';
        $fixture->beneficiary->update(['raw_data' => $raw]);
        $input = $this->input($fixture);
        $review = $this->postJson($this->url($fixture).'/review', $input)->assertOk();
        $payload = $this->submitInput($input, $review->json('review_token'));
        $transfer = Transfer::query()->findOrFail($review->json('id'));
        if ($stage !== 'acceptance') {
            $this->postJson($this->url($fixture), $payload)->assertCreated();
            $transfer = $transfer->fresh();
        }
        $checker = $this->checker();
        if ($stage === 'resume') {
            $transfer = app(TransferApprovalService::class)->approve($transfer, $checker);
        }
        $before = $transfer->fresh()->raw_data;
        // Simulate the persisted result of an allowed same-corridor beneficiary edit.
        // The external beneficiary identity and ordinary account columns remain the same.
        $raw['nium']['beneficiaryBankAccountType'] = 'Savings';
        $transfer->beneficiary->update(['raw_data' => $raw]);
        $calls = count(Http::recorded());
        if ($stage === 'acceptance') {
            $this->postJson($this->url($fixture), $payload)->assertUnprocessable();
        } else {
            $this->withToken($this->sgdToken($checker));
            $this->getJson("/api/admin/transfers/{$transfer->id}")->assertOk()
                ->assertJsonPath('allowed_actions.approve', false)
                ->assertJsonPath('payout_workflow.checker_approval_allowed', false);
            $this->postJson("/api/admin/transfers/{$transfer->id}/approve")->assertUnprocessable();
        }
        $this->assertSame($before, $transfer->fresh()->raw_data);
        $this->assertNull($transfer->fresh()->provider_operation_key);
        $this->assertDatabaseCount('transfer_approvals', $stage === 'resume' ? 1 : 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame($calls, count(Http::recorded()));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public static function savedCustomerReadEndpoints(): array
    {
        return ['detail after reload' => [true], 'activity list after reload' => [false]];
    }

    private function assertPublicSavedWorkflow(array $workflow): void
    {
        $this->assertEqualsCanonicalizing([
            'state', 'dispatch_allowed', 'dispatch_owner', 'customer_accepted_at', 'recovery_required',
            'fx_lock_active', 'fx_lock_enabled', 'expected_fee', 'provider_fee_status',
            'recovery_kind', 'can_request_recovery_review', 'can_recover_captured_outcome',
            'recovery_review_requested_at', 'can_reconcile_status', 'can_restart_review',
            'can_refresh_review', 'can_refresh_pricing', 'approval_request_allowed', 'blockers',
        ], array_keys($workflow));
        $this->assertEqualsCanonicalizing(['amount', 'currency', 'basis'], array_keys($workflow['expected_fee']));
        $this->assertSame('checker_approval', $workflow['dispatch_owner']);
        $this->assertFalse($workflow['dispatch_allowed']);
    }

    private function savedReadState(Transfer $transfer): array
    {
        return [
            'transfer' => $transfer->fresh()->getAttributes(),
            'fx_quote' => $transfer->fxQuote?->fresh()->getAttributes(),
            'balances' => Balance::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'request_logs' => ApiRequestLog::query()->orderBy('id')->get()->map->getAttributes()->all(),
        ];
    }

    /** Saved GETs keep public amounts and capabilities without exposing consent or provider records. */
    #[DataProvider('savedCustomerReadEndpoints')]
    public function test_standard_saved_customer_reads_preserve_the_public_projection(bool $detail): void
    {
        [$fixture, $transfer] = $this->standardSubmitted();
        $before = $this->savedReadState($transfer);
        $calls = count(Http::recorded());
        $response = $this->getJson($this->url($fixture).($detail ? "/{$transfer->id}" : ''))->assertOk();
        $saved = $detail ? $response->json() : collect($response->json())->firstWhere('id', $transfer->id);
        $this->assertSame($transfer->id, $saved['id']);
        $this->assertSame($transfer->client_reference, $saved['client_reference']);
        $this->assertSame('approval_required', $saved['status']);
        foreach (['transfer_no', 'provider_id', 'beneficiary_id', 'purpose_code', 'reference_text',
            'source_amount', 'source_currency', 'target_currency'] as $field) {
            $this->assertSame($transfer->{$field}, $saved[$field]);
        }
        $this->assertPublicSavedWorkflow($saved['payout_workflow']);
        $this->assertSame('approval_required', $saved['payout_workflow']['state']);
        $this->assertSame($transfer->raw_data['sgd_local_workflow']['customer_authorization']['accepted_at'],
            $saved['payout_workflow']['customer_accepted_at']);
        $this->assertFalse($saved['payout_workflow']['recovery_required']);
        $this->assertSame([], $saved['payout_workflow']['blockers']);
        $this->assertSame('USD', $saved['payout_amounts']['source_currency']);
        $this->assertSame('SGD', $saved['payout_amounts']['destination_currency']);
        $this->assertSame('7.25000000', $saved['payout_amounts']['source_amount']);
        $this->assertSame('7.25', $saved['payout_amounts']['total_wallet_debit']);
        $this->assertSame(['amount' => '0', 'currency' => 'USD'], $saved['payout_amounts']['transfer_fee']);
        $this->assertSame($transfer->fxQuote->raw_data['payout_fx_lock_contract']['rate'], $saved['payout_amounts']['fx_rate']);
        $this->assertSame($transfer->fxQuote->expires_at->toISOString(), $saved['payout_amounts']['fx_lock_expires_at']);
        $this->assertNull($saved['target_amount']);
        $this->assertNull($saved['fee_amount']);
        $this->assertNull($saved['fee_currency']);
        $this->assertSame('checker_approval', $saved['payout_workflow']['dispatch_owner']);
        $this->assertFalse($saved['payout_workflow']['dispatch_allowed']);
        // Apply the existing standard review/Submit privacy assertions to reload GETs too.
        foreach (['customer_instruction', 'customer_authorization', 'submission_token', 'financial_terms_token', 'pilot_policy'] as $field) {
            $this->assertArrayNotHasKey($field, $saved['payout_workflow']);
        }
        $encoded = json_encode($saved, JSON_THROW_ON_ERROR);
        foreach (['provider_context', 'audit_id', 'external_beneficiary_id', 'authorization_id',
            'synthetic-client', 'synthetic-customer', 'synthetic-wallet', 'synthetic-external-beneficiary'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        $this->assertArrayNotHasKey('raw_data', $saved);
        $this->assertSame($before, $this->savedReadState($transfer));
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertNoDispatch();
    }

    public static function savedCustomerRecoveryStates(): array
    {
        $cases = [
            'FX locking' => ['draft', 'locking', false, false, 'fx_outcome_unknown', true, false, false, true],
            'FX unknown' => ['draft', 'lock_unknown', false, false, 'fx_outcome_unknown', true, false, false, true],
            'submission in progress' => ['submitting', 'submitting', false, false, 'provider_reference_missing', true, false, false, true],
            'submission unknown' => ['submission_unknown', 'submitting', false, false, 'provider_reference_missing', true, false, false, true],
            'unknown with reference' => ['submission_unknown', 'submitting', true, false, null, false, true, false, true],
            'pending with reference' => ['pending', 'submitting', true, false, null, false, true, false, false],
            'completed with reference' => ['completed', 'submitting', true, false, null, false, true, false, false],
            'failed with reference' => ['failed', 'submitting', true, false, null, false, true, false, false],
            'cancelled with reference' => ['cancelled', 'submitting', true, false, null, false, true, false, false],
            'expired acceptance' => ['approval_required', 'approval_required', false, true, 'fx_expired_review_required', false, false, true, false],
        ];
        $result = [];
        foreach (self::savedCustomerReadEndpoints() as $endpoint => [$detail]) {
            foreach ($cases as $name => $case) {
                $result[$endpoint.' / '.$name] = [$detail, ...$case];
            }
        }

        return $result;
    }

    #[DataProvider('savedCustomerReadEndpoints')]
    public function test_saved_customer_reads_keep_unverified_financial_terms_unavailable(bool $detail): void
    {
        [$fixture, $transfer] = $this->standardSubmitted();
        $this->installSyntheticPricing($transfer, ['status' => 'unavailable']);
        $before = $this->savedReadState($transfer);
        $calls = count(Http::recorded());
        $response = $this->getJson($this->url($fixture).($detail ? "/{$transfer->id}" : ''))->assertOk();
        $saved = $detail ? $response->json() : collect($response->json())->firstWhere('id', $transfer->id);
        $this->assertPublicSavedWorkflow($saved['payout_workflow']);
        $this->assertSame('unavailable', $saved['payout_workflow']['provider_fee_status']);
        $this->assertContains(NiumPayoutFinancialControls::BLOCK_REASON, $saved['payout_workflow']['blockers']);
        $this->assertFalse($saved['payout_workflow']['approval_request_allowed']);
        $this->assertNull($saved['payout_amounts']['transfer_fee']);
        $this->assertNull($saved['payout_amounts']['total_wallet_debit']);
        $this->assertNull($saved['fee_amount']);
        $this->assertNull($saved['fee_currency']);
        $this->assertSame($before, $this->savedReadState($transfer));
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertNoDispatch();
    }

    #[DataProvider('savedCustomerRecoveryStates')]
    public function test_saved_customer_recovery_capabilities_remain_public_and_read_only(
        bool $detail, string $status, string $state, bool $withReference, bool $expired,
        ?string $kind, bool $canRecover, bool $canReconcile, bool $canRefresh, bool $recoveryRequired,
    ): void
    {
        [$fixture, $transfer] = $this->standardSubmitted();
        $raw = $transfer->raw_data;
        $raw['sgd_local_workflow']['state'] = $state;
        $requestedAt = now()->toISOString();
        $raw['sgd_local_workflow']['recovery_review'] = [
            'kind' => $kind ?? 'fx_outcome_unknown', 'requested_at' => $requestedAt, 'identity' => 'PRIVATE-RECOVERY-IDENTITY',
            'operation_key' => 'PRIVATE-OPERATION', 'requested_by' => $transfer->user_id,
        ];
        $raw['sgd_local_workflow']['future_internal_record'] = ['secret' => 'PRIVATE-FUTURE-RECORD'];
        $transfer->update(['status' => $status, 'raw_data' => $raw,
            'external_transfer_id' => $withReference ? 'PRIVATE-PROVIDER-REFERENCE' : null]);
        if ($expired) {
            $transfer->fxQuote->update(['expires_at' => now()->subSecond()]);
        }
        $transfer = $transfer->fresh();
        $before = $this->savedReadState($transfer);
        $calls = count(Http::recorded());
        $internal = app(NiumSgdLocalPayoutWorkflow::class)->presentation($transfer);
        $response = $this->getJson($this->url($fixture).($detail ? "/{$transfer->id}" : ''))->assertOk();
        $saved = $detail ? $response->json() : collect($response->json())->firstWhere('id', $transfer->id);
        $workflow = $saved['payout_workflow'];
        $this->assertPublicSavedWorkflow($workflow);
        $this->assertSame($status, $saved['status']);
        $this->assertSame($state, $workflow['state']);
        $this->assertSame($kind, $workflow['recovery_kind']);
        $this->assertSame($kind !== null, $workflow['can_request_recovery_review']);
        $this->assertSame($canRecover, $workflow['can_recover_captured_outcome']);
        $this->assertSame($canReconcile, $workflow['can_reconcile_status']);
        $this->assertSame($canRefresh, $workflow['can_refresh_review']);
        $this->assertSame($expired, $workflow['can_restart_review']);
        $this->assertSame($recoveryRequired, $workflow['recovery_required']);
        $this->assertSame($kind !== null ? $requestedAt : null, $workflow['recovery_review_requested_at']);
        foreach (['fx_lock_active', 'provider_fee_status', 'can_refresh_review', 'can_refresh_pricing',
            'approval_request_allowed', 'blockers'] as $field) {
            $this->assertSame($internal[$field], $workflow[$field]);
        }
        $this->assertNotEmpty($workflow['blockers']);
        $encoded = json_encode($saved, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-RECOVERY-IDENTITY', 'PRIVATE-OPERATION', 'PRIVATE-FUTURE-RECORD',
            'PRIVATE-PROVIDER-REFERENCE', 'customer_instruction', 'customer_authorization',
            'submission_token', 'financial_terms_token', 'pilot_policy', 'provider_context', 'audit_id',
            'external_beneficiary_id', 'authorization_id', 'raw_data'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        $this->assertSame($before, $this->savedReadState($transfer));
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertNoDispatch();
    }

    public static function globalApprovalSettings(): array
    {
        return ['enabled' => [true, false], 'disabled' => [false, false], 'committed_approval_resume' => [false, true]];
    }

    #[DataProvider('globalApprovalSettings')]
    public function test_standard_submit_hands_off_the_exact_instruction_to_one_admin_dispatch(bool $globalApproval, bool $resume): void
    {
        config()->set('wallet.transfer_controls.require_admin_approval', $globalApproval);
        config()->set('wallet.transfer_controls.approval_threshold_amount', 1000);
        [$fixture, $transfer, $payload] = $this->standardSubmitted();
        $receipt = $transfer->raw_data['sgd_local_workflow']['customer_authorization'];
        $review = $transfer->raw_data['sgd_local_workflow']['review'];
        $quoteId = $transfer->fx_quote_id;
        $audit = (int) $transfer->fxQuote->quote_ref;
        $this->assertNoDispatch();
        $checker = $this->checker();
        if ($resume) {
            $approved = app(TransferApprovalService::class)->approve($transfer, $checker);
            $this->assertSame('approved', $approved->status);
            $this->assertNull($approved->provider_operation_key);
            Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        }
        $url = "/api/admin/transfers/{$transfer->id}/approve";
        $this->withToken($this->sgdToken($checker))->postJson($url, ['note' => 'Synthetic independent approval'])
            ->assertOk()->assertJsonPath('transfer.status', 'pending');
        $current = $transfer->fresh();
        $operation = $current->provider_operation_key;
        $approval = $current->approvals()->sole();
        $this->assertSame($checker->id, $approval->approver_user_id);
        $this->assertSame($approval->id, $current->raw_data['sgd_local_workflow']['approval_id']);
        $this->assertSame($review['snapshot'], $current->raw_data['sgd_local_approval']);
        $this->assertSame($receipt, $current->raw_data['sgd_local_workflow']['customer_authorization']);
        $this->assertSame($review, $current->raw_data['sgd_local_workflow']['review']);
        $this->assertSame($quoteId, $current->fx_quote_id);
        $this->assertSame($operation, $current->fxQuote->raw_data['consumed_operation_key']);
        $this->assertSame($current->id, $current->fxQuote->raw_data['consumed_transfer_id']);
        $this->assertNotNull($current->raw_data['nium_execution']['dispatch_started_at']);
        $hold = LedgerEntry::query()->where('entry_type', 'hold')->sole();
        $this->assertSame($operation, $hold->raw_data['operation_key']);
        $this->assertSame($receipt['instruction']['financial_terms'], $hold->raw_data['financial_authorization']);
        $this->assertSame('-7.25000000', $hold->amount);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->hasHeader('x-request-id', substr($operation, 5))
            && $request->data() === [
                'beneficiary' => ['id' => 'synthetic-external-beneficiary'],
                'payout' => ['sourceAmount' => 7.25, 'sourceCurrency' => 'USD', 'auditId' => $audit],
                'purposeCode' => 'SYNTHETIC_PURPOSE', 'sourceOfFunds' => 'Corporate Account',
            ]);

        $this->postJson($url)->assertOk()->assertJsonPath('transfer.status', 'pending');
        try {
            app(ProviderTransferManager::class)->submitTransfer($current->provider, $current->fresh());
            $this->fail('A dispatched instruction must never dispatch again.');
        } catch (RuntimeException) {
        }
        $this->withToken($this->sgdToken($fixture->user))->postJson($this->url($fixture), $payload)
            ->assertOk()->assertJsonPath('id', $current->id);
        $this->assertSame($operation, $current->fresh()->provider_operation_key);
        $this->assertSame($receipt, $current->fresh()->raw_data['sgd_local_workflow']['customer_authorization']);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
        $this->assertSame(2, $this->locksSent());
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->assertDatabaseCount('ledger_entries', 1);

        // Reconcile through Production after dispatch; expired pricing/FX cannot duplicate settlement.
        $this->installSyntheticPricing($current, ['status' => 'unavailable']);
        $this->travel(11)->minutes();
        $this->fakeSgdProvider('completed');
        $manager = app(ProviderTransferManager::class);
        $manager->syncTransferStatus($current->provider, $current->fresh());
        $manager->syncTransferStatus($current->provider, $current->fresh());
        $this->assertSame('completed', $current->fresh()->status);
        $this->assertSame('principal_reconciled', $current->fresh()->raw_data['nium_actual_debit']['state']);
        $this->assertSame('-7.25000000', LedgerEntry::query()->where('entry_type', 'debit')->sole()->amount);
        $this->assertSame('77.75000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->travelBack();
    }

    public function test_standard_instruction_requires_an_active_independent_checker_with_global_approval_disabled(): void
    {
        config()->set('wallet.transfer_controls.require_admin_approval', false);
        [$fixture, $transfer] = $this->standardSubmitted();
        $url = "/api/admin/transfers/{$transfer->id}/approve";
        // Give the maker Admin access so rejection exercises checker independence, not middleware.
        $fixture->user->roles()->create(['role_code' => 'admin']);
        $this->postJson($url)->assertUnprocessable()
            ->assertJsonPath('message', 'The payment maker cannot approve their own controlled payout.');
        $outsider = User::factory()->create(['status' => 'active']);
        $this->withToken($this->sgdToken($outsider))->postJson($url)->assertForbidden();
        $checker = User::query()->where('id', '!=', $fixture->user_id)
            ->whereHas('roles', fn ($query) => $query->where('role_code', 'admin'))->firstOrFail();
        $checker->update(['status' => 'inactive']);
        $this->withToken($this->sgdToken($checker))->postJson($url)->assertUnprocessable()
            ->assertJsonPath('message', 'An active authorized independent checker is required.');
        try {
            app(ProviderTransferManager::class)->submitTransfer($transfer->provider, $transfer->fresh());
            $this->fail('Disabling global approval must not permit unapproved SGD dispatch.');
        } catch (RuntimeException) {
        }
        $this->assertSame('approval_required', $transfer->fresh()->status);
        $this->assertNull($transfer->fresh()->provider_operation_key);
        $this->assertSame(2, $this->locksSent());
        $this->assertNoDispatch();
    }

    public static function approvalInstructionChanges(): array
    {
        $cases = [];
        foreach (['beneficiary', 'receiving_account', 'amount', 'purpose', 'reference', 'fx', 'fx_replaced', 'fx_expired',
            'pricing', 'pricing_unavailable', 'consent', 'review_snapshot'] as $change) {
            foreach ([false, true] as $approved) {
                $cases[$change.($approved ? '_after_approval' : '_before_approval')] = [$change, $approved];
            }
        }
        $cases['approval_snapshot'] = ['approval_snapshot', true];

        return $cases;
    }

    #[DataProvider('approvalInstructionChanges')]
    public function test_standard_approval_and_resume_reject_changed_accepted_instructions(string $change, bool $approved): void
    {
        [$fixture, $transfer] = $this->standardSubmitted();
        $checker = $this->checker();
        if ($approved) {
            // Simulate a crash after approval commit, before the separate dispatch claim.
            $transfer = app(TransferApprovalService::class)->approve($transfer, $checker);
        }
        switch ($change) {
            case 'beneficiary':
                $beneficiary = $transfer->beneficiary->replicate();
                $beneficiary->save();
                $transfer->update(['beneficiary_id' => $beneficiary->id]);
                break;
            case 'receiving_account':
                $transfer->beneficiary->update(['account_number' => 'CHANGED-ACCOUNT']);
                break;
            case 'amount':
                $transfer->update(['source_amount' => '8']);
                break;
            case 'purpose':
                $transfer->update(['purpose_code' => 'ALTERNATE_SYNTHETIC_PURPOSE']);
                break;
            case 'reference':
                $transfer->update(['reference_text' => 'CHANGED-REFERENCE']);
                break;
            case 'fx':
                $transfer->update(['fx_quote_id' => null]);
                break;
            case 'fx_replaced':
                $transfer->update(['fx_quote_id' => $fixture->fx_quote_id]);
                break;
            case 'fx_expired':
                $transfer->fxQuote->update(['expires_at' => now()->subSecond()]);
                break;
            case 'pricing':
                $this->installSyntheticPricing($transfer, ['pricing_version' => 'CHANGED-SYNTHETIC-TERMS']);
                break;
            case 'pricing_unavailable':
                $this->installSyntheticPricing($transfer, ['status' => 'unavailable']);
                break;
            default:
                $raw = $transfer->raw_data;
                if ($change === 'consent') {
                    $raw['sgd_local_workflow']['customer_authorization']['payment_terms_accepted'] = false;
                } elseif ($change === 'review_snapshot') {
                    $raw['sgd_local_workflow']['review']['snapshot']['source_amount'] = '8';
                } else {
                    $raw['sgd_local_approval']['source_amount'] = '8';
                }
                $transfer->update(['raw_data' => $raw]);
        }
        $before = $transfer->fresh()->raw_data;
        $this->withToken($this->sgdToken($checker))->postJson("/api/admin/transfers/{$transfer->id}/approve")
            ->assertUnprocessable();
        if ($approved) {
            try {
                app(ProviderTransferManager::class)->submitTransfer($transfer->provider, $transfer->fresh());
                $this->fail('The dispatcher must also reject a changed accepted instruction.');
            } catch (RuntimeException|ValidationException) {
            }
        }
        $this->assertSame($approved ? 'approved' : 'approval_required', $transfer->fresh()->status);
        $this->assertSame($before, $transfer->fresh()->raw_data);
        $this->assertNull($transfer->fresh()->provider_operation_key);
        $this->assertDatabaseCount('transfer_approvals', $approved ? 1 : 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('fx_quotes', 2);
        $this->assertSame(2, $this->locksSent());
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public static function revokedCheckerStates(): array
    {
        return ['inactive' => ['inactive'], 'permission_revoked' => ['permission_revoked']];
    }

    #[DataProvider('revokedCheckerStates')]
    public function test_standard_dispatch_rechecks_the_recorded_checker_after_approval(string $change): void
    {
        [, $transfer] = $this->standardSubmitted();
        $checker = $this->checker();
        $approved = app(TransferApprovalService::class)->approve($transfer, $checker);
        if ($change === 'inactive') {
            $checker->update(['status' => 'inactive']);
        } else {
            $checker->roles()->delete();
        }
        // A different currently authorized checker cannot resume a revoked approval.
        $replacement = User::factory()->create(['status' => 'active']);
        $replacement->roles()->create(['role_code' => 'admin']);
        $this->withToken($this->sgdToken($replacement))->postJson("/api/admin/transfers/{$transfer->id}/approve")
            ->assertUnprocessable();
        try {
            app(ProviderTransferManager::class)->submitTransfer($approved->provider, $approved->fresh());
            $this->fail('A revoked checker must invalidate dispatch authorization.');
        } catch (RuntimeException) {
        }
        $this->assertNull($transfer->fresh()->provider_operation_key);
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(2, $this->locksSent());
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_standard_unknown_dispatch_retains_its_hold_and_duplicate_approval_cannot_retry(): void
    {
        [, $transfer] = $this->standardSubmitted();
        $this->fakeSgdProvider('timeout');
        $url = "/api/admin/transfers/{$transfer->id}/approve";
        $this->withToken($this->sgdToken($this->checker()))->postJson($url)->assertOk()
            ->assertJsonPath('transfer.status', 'submission_unknown');
        $current = $transfer->fresh();
        $this->postJson($url)->assertOk()->assertJsonPath('transfer.status', 'submission_unknown');
        try {
            app(ProviderTransferManager::class)->submitTransfer($current->provider, $current->fresh());
            $this->fail('An uncertain dispatch must never be retried.');
        } catch (RuntimeException) {
        }
        $this->assertSame($current->provider_operation_key, $transfer->fresh()->provider_operation_key);
        $this->assertSame($current->provider_operation_key, $current->fxQuote->raw_data['consumed_operation_key']);
        $this->assertSame('7.25000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame('77.75000000', Balance::query()->sole()->available_balance);
        $this->assertSame('85.00000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertDatabaseCount('fx_quotes', 2);
    }

    public function test_standard_review_uses_a_durable_preparation_and_submit_records_production_consent(): void
    {
        $fixture = $this->fixture();
        $input = $this->input($fixture);
        $review = $this->postJson($this->url($fixture).'/review', $input)->assertOk()
            ->assertJsonPath('status', 'draft')->assertJsonPath('source_currency', 'USD')
            ->assertJsonPath('destination_currency', 'SGD')->assertJsonPath('terms.fee_amount', '0')
            ->assertJsonPath('terms.total_wallet_debit', '7.25')
            ->assertJsonPath('payout_workflow.dispatch_owner', 'checker_approval');
        $transfer = Transfer::query()->findOrFail($review->json('id'));
        $this->assertNotNull($transfer->fx_quote_id);
        $this->assertSame('locked', $transfer->raw_data['sgd_local_workflow']['state']);
        $this->assertArrayNotHasKey('customer_authorization', $transfer->raw_data['sgd_local_workflow']);
        $again = $this->postJson($this->url($fixture).'/review', $input)->assertOk()->assertJsonPath('id', $transfer->id);
        $this->assertSame(2, $this->locksSent()); // One fixture hold, one standard preparation hold.
        $this->assertDatabaseCount('transfers', 2);
        $this->assertNoDispatch();

        $payload = $this->submitInput($input, $review->json('review_token'));
        $response = $this->postJson($this->url($fixture), $payload)->assertCreated()
            ->assertJsonPath('id', $transfer->id)->assertJsonPath('status', 'approval_required');
        $receipt = $transfer->fresh()->raw_data['sgd_local_workflow']['customer_authorization'];
        $this->assertSame($fixture->user_id, $receipt['accepted_by']);
        $this->assertSame('authenticated_customer_submit', $receipt['basis']);
        $this->assertSame($transfer->id, $receipt['instruction']['transfer_id']);
        $this->assertSame($transfer->fx_quote_id, $receipt['instruction']['fx_quote_id']);
        $this->assertSame('STANDARD-REFERENCE', $receipt['instruction']['reference_text']);
        $this->assertTrue($receipt['payment_terms_accepted']);
        $this->assertTrue($receipt['purpose_confirmed']);
        $this->assertNull($transfer->fresh()->provider_operation_key);
        $this->postJson($this->url($fixture), $this->submitInput($input, $again->json('review_token')))
            ->assertOk()->assertJsonPath('id', $transfer->id);
        $this->assertSame($receipt, $transfer->fresh()->raw_data['sgd_local_workflow']['customer_authorization']);
        foreach ([$review->json(), $response->json()] as $public) {
            $this->assertEqualsCanonicalizing(['state', 'dispatch_allowed', 'dispatch_owner',
                'customer_accepted_at', 'recovery_required'], array_keys($public['payout_workflow']));
            $encoded = json_encode($public, JSON_THROW_ON_ERROR);
            foreach (['provider_context', 'audit_id', 'submission_token', 'financial_terms', 'authorization_id',
                'synthetic-client', 'synthetic-customer', 'synthetic-wallet', 'synthetic-external-beneficiary'] as $private) {
                $this->assertStringNotContainsString($private, $encoded);
            }
            $this->assertArrayNotHasKey('raw_data', $public);
        }
        $this->assertNull($review->json('payout_workflow.customer_accepted_at'));
        $this->assertFalse($review->json('payout_workflow.recovery_required'));
        $this->assertSame($receipt['accepted_at'], $response->json('payout_workflow.customer_accepted_at'));
        $saved = $this->getJson($this->url($fixture)."/{$transfer->id}")->assertOk();
        foreach ($response->json('payout_workflow') as $field => $value) {
            $this->assertSame($value, $saved->json('payout_workflow.'.$field));
        }
        $this->assertSame($response->json('payout_amounts'), $saved->json('payout_amounts'));
        $this->assertSame(2, $this->locksSent());
        $this->assertNoDispatch();
    }

    public function test_accepted_submit_replays_after_expiry_and_after_approval_without_replacing_terms(): void
    {
        $fixture = $this->fixture();
        $input = $this->input($fixture);
        $review = $this->postJson($this->url($fixture).'/review', $input)->assertOk();
        $payload = $this->submitInput($input, $review->json('review_token'));
        $this->postJson($this->url($fixture), $payload)->assertCreated();
        $transfer = Transfer::query()->findOrFail($review->json('id'));
        $receipt = $transfer->raw_data['sgd_local_workflow']['customer_authorization'];
        $transfer->update(['status' => 'approved']); // Simulate approval committed before dispatch.
        $this->travel(11)->minutes();
        $this->installSyntheticPricing($fixture, ['status' => 'unavailable']);
        $calls = count(Http::recorded());
        $this->postJson($this->url($fixture), $payload)->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertSame($receipt, $transfer->fresh()->raw_data['sgd_local_workflow']['customer_authorization']);
        $this->assertDatabaseCount('transfers', 2);
        $this->assertSame(2, $this->locksSent());
        $this->assertNoDispatch();
    }

    public static function instructionChanges(): array
    {
        return [['beneficiary_id', 999], ['source_amount', '8'], ['purpose_code', 'ALTERNATE_SYNTHETIC_PURPOSE'],
            ['reference_text', 'CHANGED'], ['client_reference', 'CHANGED']];
    }

    #[DataProvider('instructionChanges')]
    public function test_changed_review_or_submit_input_cannot_reuse_an_instruction(string $field, mixed $value): void
    {
        $fixture = $this->fixture();
        $input = $this->input($fixture);
        $review = $this->postJson($this->url($fixture).'/review', $input)->assertOk();
        if ($field === 'beneficiary_id') {
            $other = $fixture->beneficiary->replicate();
            $other->save();
            $value = $other->id;
        }
        $changed = [...$input, $field => $value];
        $this->postJson($this->url($fixture), $this->submitInput($changed, $review->json('review_token')))->assertStatus(409);
        if ($field !== 'client_reference') {
            $this->postJson($this->url($fixture).'/review', $changed)->assertStatus(409);
        }
        $this->assertSame('draft', Transfer::query()->findOrFail($review->json('id'))->status);
        $this->assertSame(2, $this->locksSent());
        $this->assertDatabaseCount('transfers', 2);
        $this->assertNoDispatch();
    }

    public function test_receiving_account_or_financial_terms_changed_after_review_cannot_be_accepted(): void
    {
        $fixture = $this->fixture();
        $input = $this->input($fixture);
        $review = $this->postJson($this->url($fixture).'/review', $input)->assertOk();
        $payload = $this->submitInput($input, $review->json('review_token'));
        $fixture->beneficiary->update(['account_number' => 'CHANGED-RECEIVING-ACCOUNT']);
        $this->postJson($this->url($fixture), $payload)->assertUnprocessable();
        $fixture->beneficiary->update(['account_number' => '0123456789']);
        $this->installSyntheticPricing($fixture, ['pricing_version' => 'CHANGED-SYNTHETIC-TERMS']);
        $this->postJson($this->url($fixture), $payload)->assertUnprocessable();
        $this->assertArrayNotHasKey('customer_authorization', Transfer::query()->findOrFail($review->json('id'))->raw_data['sgd_local_workflow']);
        $this->assertSame(2, $this->locksSent());
        $this->assertNoDispatch();
    }

    public function test_consent_missing_tampered_or_cross_customer_review_cannot_submit(): void
    {
        $fixture = $this->fixture();
        $input = $this->input($fixture);
        $review = $this->postJson($this->url($fixture).'/review', $input)->assertOk();
        $payload = $this->submitInput($input, $review->json('review_token'));
        foreach (['review_token', 'accept_payment_terms', 'purpose_confirmed'] as $required) {
            $invalid = $payload;
            unset($invalid[$required]);
            $this->postJson($this->url($fixture), $invalid)->assertUnprocessable();
        }
        $this->postJson($this->url($fixture), [...$payload, 'review_token' => 'tampered'])->assertUnprocessable();
        // Pass profile.complete so the own-URL request exercises review-token ownership.
        $other = User::factory()->create(['status' => 'active', 'kyc_status' => 'verified']);
        $this->withToken($this->sgdToken($other))->postJson($this->url($fixture), $payload)->assertForbidden();
        $this->postJson("/api/user/users/{$other->id}/transfers", $payload)->assertForbidden();
        $this->withToken($this->sgdToken($fixture->user));
        $this->postJson($this->url($fixture), [...$payload, 'accept_payment_terms' => false])->assertUnprocessable();
        $this->postJson($this->url($fixture).'/'.$review->json('id').'/submit')->assertUnprocessable();
        $this->assertSame(2, $this->locksSent());
        $this->assertNoDispatch();
    }

    public static function foreignReviewStates(): array
    {
        return ['reviewed' => [false], 'accepted' => [true]];
    }

    #[DataProvider('foreignReviewStates')]
    public function test_foreign_review_token_cannot_be_submitted_or_replayed_or_disclose_a_transfer(bool $accepted): void
    {
        $fixture = $this->fixture();
        $input = $this->input($fixture);
        $review = $this->postJson($this->url($fixture).'/review', $input)->assertOk();
        $payload = $this->submitInput($input, $review->json('review_token'));
        if ($accepted) {
            $this->postJson($this->url($fixture), $payload)->assertCreated();
        }
        $transfer = Transfer::query()->findOrFail($review->json('id'));
        $before = $transfer->getAttributes();
        $calls = count(Http::recorded());
        $other = User::factory()->create(['status' => 'active', 'kyc_status' => 'verified']);
        $this->withToken($this->sgdToken($other));
        $url = "/api/user/users/{$other->id}/transfers";

        // Repetition cannot accept/replay a foreign token; ownership precedes instruction conflicts.
        foreach ([$payload, $payload, [...$payload, 'source_amount' => '8']] as $foreign) {
            $this->postJson($url, $foreign)->assertForbidden()
                ->assertJsonMissingPath('id')->assertJsonMissingPath('transfer')
                ->assertJsonMissingPath('raw_data')->assertJsonMissingPath('review_token')
                ->assertJsonMissingPath('payout_amounts')->assertJsonMissingPath('payout_workflow');
        }
        $this->assertSame($before, $transfer->fresh()->getAttributes());
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertDatabaseCount('transfers', 2);
        $this->assertSame(2, $this->locksSent());
        $this->assertNoDispatch();
    }

    public function test_expired_unaccepted_fx_requires_review_and_old_token_cannot_accept_replacement(): void
    {
        $fixture = $this->fixture();
        $input = $this->input($fixture);
        $review = $this->postJson($this->url($fixture).'/review', $input)->assertOk();
        $old = $this->submitInput($input, $review->json('review_token'));
        $this->travel(11)->minutes();
        Balance::query()->update(['as_of' => now()]);
        $this->postJson($this->url($fixture), $old)->assertUnprocessable();
        $this->assertSame(2, $this->locksSent());
        $fresh = $this->postJson($this->url($fixture).'/review', $input)->assertOk()->assertJsonPath('id', $review->json('id'));
        $this->postJson($this->url($fixture), $old)->assertUnprocessable();
        $this->postJson($this->url($fixture), $this->submitInput($input, $fresh->json('review_token')))->assertCreated();
        $this->assertSame(3, $this->locksSent());
        $this->assertDatabaseCount('transfers', 2);
        $this->assertNoDispatch();
    }

    public function test_uncertain_fx_uses_existing_captured_recovery_without_another_lock(): void
    {
        $fixture = $this->fixture();
        $quoteId = $fixture->fx_quote_id;
        $raw = $fixture->raw_data;
        $raw['sgd_local_workflow']['state'] = 'lock_unknown';
        $fixture->update(['fx_quote_id' => null, 'fx_rate' => null, 'raw_data' => $raw]);
        $input = $this->input($fixture, true);
        $this->postJson($this->url($fixture).'/review', $input)->assertUnprocessable()
            ->assertJsonPath('transfer.id', $fixture->id)->assertJsonPath('transfer.payout_workflow.recovery_required', true);
        $this->postJson($this->url($fixture).'/review', $input)->assertUnprocessable();
        $calls = count(Http::recorded());
        $this->postJson($this->url($fixture)."/{$fixture->id}/sgd-local-recover-captured")->assertOk();
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertSame($quoteId, $fixture->fresh()->fx_quote_id);
        $review = $this->postJson($this->url($fixture).'/review', $input)->assertOk()->assertJsonPath('id', $fixture->id);
        $this->postJson($this->url($fixture), $this->submitInput($input, $review->json('review_token')))->assertCreated();
        $this->assertSame(1, $this->locksSent());
        $this->assertDatabaseCount('transfers', 1);
        $this->assertNoDispatch();
    }

    public function test_receipt_replay_tolerates_database_json_object_key_reordering(): void
    {
        $fixture = $this->fixture();
        $input = $this->input($fixture);
        $review = $this->postJson($this->url($fixture).'/review', $input)->assertOk();
        $payload = $this->submitInput($input, $review->json('review_token'));
        $this->postJson($this->url($fixture), $payload)->assertCreated();
        $transfer = Transfer::query()->findOrFail($review->json('id'));
        $raw = $transfer->raw_data;
        $receipt = &$raw['sgd_local_workflow']['customer_authorization'];
        $receipt['instruction'] = array_reverse($receipt['instruction'], true);
        $receipt['instruction']['financial_terms'] = array_reverse($receipt['instruction']['financial_terms'], true);
        $transfer->update(['raw_data' => $raw]);
        $this->postJson($this->url($fixture), $payload)->assertOk()->assertJsonPath('id', $transfer->id);
        $this->assertSame(2, $this->locksSent());
        $this->assertNoDispatch();
    }

    public function test_first_review_timeout_returns_the_durable_preparation_and_cannot_repeat_fx(): void
    {
        $fixture = $this->fixture();
        $attempts = 0;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$attempts) {
            if (str_contains($request->url(), 'purposeCodes')) {
                return Http::response([['purposeCode' => 'SYNTHETIC_PURPOSE', 'description' => 'Synthetic only']]);
            }
            if (str_contains($request->url(), 'supportedCorridors')) {
                return Http::response(['content' => [['destinationCountry' => 'SG', 'destinationCurrency' => 'SGD',
                    'payoutMethod' => 'LOCAL', 'beneficiaryAccountType' => 'INDIVIDUAL',
                    'customerType' => 'CORPORATE', 'routingCodeType' => 'SWIFT']]]);
            }
            if (str_contains($request->url(), 'lockExchangeRate')) {
                $attempts++;
                throw new ConnectionException('Synthetic uncertain FX; no network');
            }
            throw new RuntimeException('Unexpected offline adapter request');
        });
        $input = $this->input($fixture);
        $failed = $this->postJson($this->url($fixture).'/review', $input)->assertUnprocessable()
            ->assertJsonPath('transfer.payout_workflow.state', 'lock_unknown')
            ->assertJsonPath('transfer.payout_workflow.recovery_required', true);
        $this->assertNotNull($failed->json('transfer.id'));
        $this->postJson($this->url($fixture).'/review', $input)->assertUnprocessable()
            ->assertJsonPath('transfer.id', $failed->json('transfer.id'));
        $this->assertSame(1, $attempts);
        $this->assertDatabaseCount('transfers', 2);
        $this->assertDatabaseCount('fx_quotes', 1);
        $this->assertNoDispatch();
    }

    public function test_unavailable_pricing_cannot_reset_an_expired_durable_preparation(): void
    {
        $fixture = $this->fixture(false);
        $before = $fixture->raw_data;
        $this->travel(11)->minutes();
        $this->postJson($this->url($fixture).'/review', $this->input($fixture, true))->assertUnprocessable()
            ->assertJsonPath('message', NiumPayoutFinancialControls::BLOCK_REASON);
        $this->assertSame($before, $fixture->fresh()->raw_data);
        $this->assertSame(1, $this->locksSent());
        $this->assertDatabaseCount('fx_quotes', 1);
        $this->assertNoDispatch();
    }

    public function test_unavailable_real_pricing_blocks_before_new_preparation_or_fx_and_client_claims_cannot_override_it(): void
    {
        $fixture = $this->fixture(false);
        $input = $this->input($fixture);
        $this->postJson($this->url($fixture).'/review', $input)->assertUnprocessable()
            ->assertJsonPath('message', NiumPayoutFinancialControls::BLOCK_REASON)->assertJsonPath('transfer', null);
        $this->postJson($this->url($fixture).'/review', [...$input,
            'fee_amount' => '0', 'total_wallet_debit' => '7.25', 'provider_verified' => true,
            'raw_data' => ['owner_report' => 'zero fees'], 'accept_uncapped_charges' => true,
        ])->assertUnprocessable();
        $this->assertSame(1, $this->locksSent());
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('fx_quotes', 1);
        $this->assertNoDispatch();
    }

    public static function hkAmounts(): array
    {
        return [['5'], ['10']];
    }

    #[DataProvider('hkAmounts')]
    public function test_hk_swift_customer_detail_and_activity_list_keep_the_existing_projection(string $amount): void
    {
        $transfer = $this->fixture();
        $transfer->beneficiary->update(['country_code' => 'HK', 'currency' => 'USD', 'payout_method' => 'SWIFT']);
        $transfer->update(['source_amount' => $amount, 'target_currency' => 'USD',
            'target_amount' => null, 'fx_quote_id' => null, 'fx_rate' => null]);
        $transfer = $transfer->fresh();
        $this->mock(NiumRemittancePricing::class)->shouldNotReceive('quote');
        $before = $this->savedReadState($transfer);
        $calls = count(Http::recorded());
        $detail = $this->getJson($this->url($transfer)."/{$transfer->id}")->assertOk();
        $list = $this->getJson($this->url($transfer))->assertOk();
        foreach ([$detail->json(), collect($list->json())->firstWhere('id', $transfer->id)] as $public) {
            foreach (['id', 'transfer_no', 'provider_id', 'beneficiary_id', 'source_amount', 'source_currency',
                'target_currency', 'target_amount', 'fx_rate', 'fee_amount', 'fee_currency', 'fx_quote_id',
                'purpose_code', 'reference_text', 'client_reference', 'status'] as $field) {
                $this->assertSame($transfer->{$field}, $public[$field]);
            }
            $this->assertArrayNotHasKey('payout_amounts', $public);
            $this->assertArrayNotHasKey('payout_workflow', $public);
            $this->assertArrayNotHasKey('raw_data', $public);
            $this->assertStringNotContainsString('synthetic-external-beneficiary', json_encode($public, JSON_THROW_ON_ERROR));
        }
        $this->assertSame($before, $this->savedReadState($transfer));
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertNoDispatch();
    }

    /** An Admin role must never allow the HK payment maker to act as checker. */
    #[DataProvider('hkAmounts')]
    public function test_hk_swift_maker_with_admin_permission_cannot_approve_their_own_payment(string $amount): void
    {
        $fixture = $this->fixture();
        $this->mock(NiumRemittancePricing::class)->shouldNotReceive('quote');
        config()->set('wallet.transfer_controls.require_admin_approval', true);
        config()->set('wallet.transfer_controls.approval_threshold_amount', 0);
        $beneficiary = Beneficiary::query()->create(['user_id' => $fixture->user_id, 'provider_id' => $fixture->provider_id,
            'external_beneficiary_id' => 'synthetic-hk-beneficiary', 'beneficiary_type' => 'business',
            'full_name' => 'Synthetic HK', 'country_code' => 'HK', 'currency' => 'USD', 'payout_method' => 'SWIFT',
            'status' => 'active', 'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT']]]);
        $payload = [...$this->input($fixture), 'beneficiary_id' => $beneficiary->id, 'source_amount' => $amount,
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'USD'];
        $created = $this->postJson($this->url($fixture), $payload)->assertCreated()->assertJsonPath('status', 'approval_required');
        $transfer = Transfer::query()->findOrFail($created->json('id'));
        $fixture->user->roles()->create(['role_code' => 'admin']);
        $this->withToken($this->sgdToken($fixture->user));
        $url = "/api/admin/transfers/{$transfer->id}";
        $calls = count(Http::recorded());
        $this->getJson($url)->assertOk()->assertJsonPath('allowed_actions.approve', false);
        $this->postJson($url.'/approve')->assertUnprocessable();
        $this->assertSame('approval_required', $transfer->fresh()->status);
        $this->assertNull($transfer->fresh()->provider_operation_key);
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertNoDispatch();
    }

    #[DataProvider('hkAmounts')]
    public function test_hk_swift_standard_creation_and_approval_keep_the_existing_payload(string $amount): void
    {
        $fixture = $this->fixture();
        $this->mock(NiumRemittancePricing::class)->shouldNotReceive('quote');
        config()->set('wallet.transfer_controls.require_admin_approval', true);
        config()->set('wallet.transfer_controls.approval_threshold_amount', 0);
        $beneficiary = Beneficiary::query()->create(['user_id' => $fixture->user_id, 'provider_id' => $fixture->provider_id,
            'external_beneficiary_id' => 'synthetic-hk-beneficiary', 'beneficiary_type' => 'business',
            'full_name' => 'Synthetic HK', 'country_code' => 'HK', 'currency' => 'USD', 'payout_method' => 'SWIFT',
            'status' => 'active', 'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT']]]);
        $payload = [...$this->input($fixture), 'beneficiary_id' => $beneficiary->id, 'source_amount' => $amount,
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'USD'];
        $created = $this->postJson($this->url($fixture), $payload)->assertCreated()->assertJsonPath('status', 'approval_required');
        $this->postJson($this->url($fixture), $payload)->assertOk()->assertJsonPath('id', $created->json('id'));
        $hkTransfer = Transfer::query()->findOrFail($created->json('id'));
        $before = $hkTransfer->getAttributes();
        $calls = count(Http::recorded());
        $detail = $this->getJson($this->url($fixture)."/{$hkTransfer->id}")->assertOk();
        foreach ([$created->json(), $detail->json()] as $public) {
            foreach (['id', 'transfer_no', 'provider_id', 'beneficiary_id', 'source_amount', 'source_currency',
                'target_currency', 'target_amount', 'fx_rate', 'fee_amount', 'fee_currency', 'fx_quote_id',
                'purpose_code', 'reference_text', 'client_reference', 'status'] as $field) {
                $this->assertSame($hkTransfer->{$field}, $public[$field]);
            }
            $this->assertArrayNotHasKey('payout_amounts', $public);
            $this->assertArrayNotHasKey('payout_workflow', $public);
            $this->assertArrayNotHasKey('raw_data', $public);
            $this->assertStringNotContainsString('synthetic-hk-beneficiary', json_encode($public, JSON_THROW_ON_ERROR));
        }
        $this->assertSame($before, $hkTransfer->fresh()->getAttributes());
        $this->assertSame($calls, count(Http::recorded()));
        $checker = User::query()->whereHas('roles', fn ($query) => $query->where('role_code', 'admin'))->firstOrFail();
        $this->withToken($this->sgdToken($checker))->postJson('/api/admin/transfers/'.$created->json('id').'/approve')->assertOk();
        $this->postJson('/api/admin/transfers/'.$created->json('id').'/approve')->assertOk();
        $posts = Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/remittance'));
        $this->assertCount(1, $posts);
        $expectedBody = '{"beneficiary":{"id":"synthetic-hk-beneficiary"},"payout":{"sourceAmount":'.$amount.',"sourceCurrency":"USD","destinationCurrency":"USD","payoutMethod":"SWIFT","tradeOrderID":"STANDARD-REFERENCE","swiftFeeType":"SHA"},"purposeCode":"SYNTHETIC_PURPOSE","sourceOfFunds":"Corporate Account","customerComments":"STANDARD-REFERENCE"}';
        $this->assertSame($expectedBody, $posts->sole()[0]->body());
        $this->assertSame(1, $this->locksSent());
        $this->assertDatabaseCount('fx_quotes', 1);
        $this->assertDatabaseCount('ledger_entries', 1);
        $current = Transfer::query()->findOrFail($created->json('id'));
        $operation = $current->provider_operation_key;
        $this->assertNotNull($operation);
        $this->assertSame($operation, LedgerEntry::query()->sole()->raw_data['operation_key']);
        $this->assertTrue($posts->sole()[0]->hasHeader('x-request-id', substr($operation, 5)));
        $this->assertDatabaseCount('transfer_approvals', 1);
        try {
            app(ProviderTransferManager::class)->submitTransfer($current->provider, $current->fresh());
            $this->fail('An HK SWIFT dispatch must not be repeated.');
        } catch (RuntimeException) {
        }
        $this->assertSame(1, ApiRequestLog::query()->where('operation', 'transfer_money')->count());
        $this->fakeSgdProvider('completed');
        $manager = app(ProviderTransferManager::class);
        $manager->syncTransferStatus($current->provider, $current->fresh());
        $manager->syncTransferStatus($current->provider, $current->fresh());
        $this->assertSame('completed', $current->fresh()->status);
        $this->assertSame($operation, $current->fresh()->provider_operation_key);
        $this->assertSame('-'.number_format((float) $amount, 8, '.', ''), LedgerEntry::query()->where('entry_type', 'debit')->sole()->amount);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }
}
