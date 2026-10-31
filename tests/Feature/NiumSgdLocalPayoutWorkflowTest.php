<?php

namespace Tests\Feature;

use App\Http\Resources\Admin\TransferDetailResource;
use App\Http\Resources\User\BeneficiaryResource;
use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\NiumApiExchangeEvidence;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumSgdLocalPayoutAuthorization;
use App\Services\Nium\NiumSgdLocalPayoutGate;
use App\Services\Nium\NiumSgdLocalPayoutWorkflow;
use App\Services\Nium\NiumSgdLocalRemittancePayload;
use App\Services\Wallet\LedgerService;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumSgdLocalPayoutWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;

    private string $token;

    private int $lockCalls = 0;

    private bool $redirectLock = false;

    private ?\Closure $onLock = null;

    private function authorizedChecker(): User
    {
        $checker = User::factory()->create(['status' => 'active']);
        $checker->roles()->create(['role_code' => 'admin']);

        return $checker;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('services.nium.base_url', 'https://nium.test');
        config()->set('services.nium.client_id', 'client-test');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'synthetic-only']);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'synthetic-only');
        config()->set('services.nium.beneficiary_endpoint', '/api/v2/client/{clientHashId}/customer/{customerHashId}/beneficiaries');
        config()->set('services.nium.supported_corridors', []);
        config()->set('services.nium.payout_fx_enabled', true);
        config()->set('wallet.transfer_controls.require_admin_approval', true);
        config()->set('wallet.transfer_controls.approval_threshold_amount', 0);
        IntegrationProvider::query()->forceCreate(['id' => 10, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $this->maker = User::factory()->create(['id' => 12, 'kyc_status' => 'verified']);
        $this->maker->kycProfile()->create([
            'status' => 'approved', 'applicant_type' => 'business', 'registered_country_code' => 'HK',
            'country_code' => 'HK', 'metadata' => ['nium_region' => 'HK'], 'legal_name' => 'Synthetic HK Corporate',
            'address_line1' => '1 Test Road', 'city' => 'Hong Kong', 'postal_code' => '999077',
        ]);
        UserProviderAccount::query()->forceCreate([
            'id' => 6, 'user_id' => 12, 'provider_id' => 10, 'external_customer_id' => 'customer-test',
            'external_account_id' => 'wallet-test', 'status' => 'active', 'provider_status' => 'clear',
            'reconciliation_status' => 'reconciled', 'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now(),
        ]);
        Beneficiary::query()->forceCreate([
            'id' => 9, 'user_id' => 12, 'provider_id' => 10, 'external_beneficiary_id' => 'fixture-registered-id',
            'beneficiary_type' => 'personal', 'full_name' => 'Synthetic SG Individual', 'country_code' => 'SG',
            'currency' => 'SGD', 'payout_method' => 'LOCAL', 'swift_bic' => 'DBSSSGSGXXX', 'status' => 'active',
        ]);
        $log = ApiRequestLog::query()->create([
            'provider_id' => 10, 'user_id' => 12, 'operation' => 'beneficiary_create', 'external_reference' => '9',
            'client_hash_id' => 'client-test', 'request_method' => 'POST',
            'request_url' => 'https://nium.test/api/v2/client/[REDACTED]/customer/[REDACTED]/beneficiaries',
            'request_body' => ['payout_method' => 'LOCAL'], 'response_status' => 200, 'is_success' => true,
            'response_body' => ['beneficiary_id' => 'fixture-registered-id'],
        ]);
        $raw = json_encode(['beneficiaryHashId' => 'fixture-registered-id', 'status' => 'ACTIVE']);
        NiumApiExchangeEvidence::query()->create([
            'api_request_log_id' => $log->id, 'request_method' => 'POST', 'request_url' => $log->request_url,
            'raw_response_body' => $raw, 'response_body_sha256' => hash('sha256', $raw),
            'response_received' => true, 'capture_version' => 1,
        ]);
        Balance::query()->create([
            'user_id' => 12, 'provider_id' => 10, 'currency' => 'USD', 'available_balance' => '85',
            'ledger_balance' => '85', 'reserved_balance' => '0', 'as_of' => now(),
        ]);
        $this->token = Str::random(64);
        ApiToken::query()->create(['user_id' => 12, 'name' => 'test', 'token_hash' => hash('sha256', $this->token)]);
        $level = DB::transactionLevel();
        Http::fake(function ($request, $options) use ($level) {
            $this->assertSame('GET', $request->method(), 'No Remittance POST is permitted in these preparation tests.');
            if (str_contains($request->url(), 'purposeCodes')) {
                return Http::response([['purposeCode' => 'TEST_PURPOSE', 'description' => 'Synthetic only, not an approved CUG purpose']]);
            }
            if (str_contains($request->url(), 'supportedCorridors')) {
                return Http::response(['content' => [[
                    'destinationCountry' => 'SG', 'destinationCurrency' => 'SGD', 'payoutMethod' => 'LOCAL',
                    'beneficiaryAccountType' => 'INDIVIDUAL', 'customerType' => 'CORPORATE', 'routingCodeType' => 'SWIFT',
                ]]]);
            }
            if (str_contains($request->url(), 'lockExchangeRate')) {
                $this->assertFalse($options['allow_redirects'], 'Creating a hold must never follow redirects.');
                $this->assertSame($level, DB::transactionLevel(), 'Provider I/O must occur outside application claim/attachment transactions.');
                $this->assertSame('USD', $request['sourceCurrency']);
                $this->assertSame('SGD', $request['destinationCurrency']);
                $this->lockCalls++;
                if ($this->onLock !== null) {
                    ($this->onLock)();
                }
                if ($this->redirectLock) {
                    return Http::response('', 302, ['Location' => 'https://nium.test/unreviewed-lock-target']);
                }

                return Http::response([
                    'audit_id' => 4243000 + $this->lockCalls, 'fx_hold_id' => 'synthetic-phase43-only', 'fx_rate' => '1.275939120',
                    'source_currency' => 'USD', 'destination_currency' => 'SGD', 'status' => 'ACTIVE',
                    'hold_expiry_at' => now()->addMinutes(10)->toISOString(),
                ]);
            }
            throw new RuntimeException('Unexpected synthetic provider request.');
        });
    }

    protected function tearDown(): void
    {
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        parent::tearDown();
    }

    private function input(array $overrides = []): array
    {
        return array_replace([
            'provider_id' => 10, 'beneficiary_id' => 9, 'source_currency' => 'USD', 'target_currency' => 'SGD',
            'source_amount' => '5.00', 'purpose_code' => 'TEST_PURPOSE', 'purpose_confirmed' => true,
            'client_reference' => 'OW-SGD-synthetic', 'expected_fee_amount' => '0', 'expected_fee_currency' => 'USD',
        ], $overrides);
    }

    private function prepare(array $overrides = []): Transfer
    {
        $this->withToken($this->token)->postJson('/api/user/users/12/sgd-local-payouts', $this->input($overrides))->assertOk();

        return Transfer::query()->sole();
    }

    private function lock(Transfer $transfer, ?string $uuid = null)
    {
        return $this->withToken($this->token)->postJson("/api/user/users/12/transfers/{$transfer->id}/sgd-local-fx-lock", [
            'request_id' => $uuid ?? Str::uuid()->toString(), 'confirm_fx_lock' => true,
        ]);
    }

    public function test_listing_and_draft_creation_never_lock_fx_or_reserve_money(): void
    {
        app(NiumSgdLocalPayoutGate::class)->assertLocalCandidate($this->maker, IntegrationProvider::query()->sole(), Beneficiary::query()->sole(), 'SGD');
        $admission = (new BeneficiaryResource(Beneficiary::query()->sole()))->resolve()['payout_admission'];
        $this->assertTrue($admission['preparation_allowed']);
        $this->assertFalse($admission['allowed']);
        Http::assertNothingSent();
        $transfer = $this->prepare();
        $this->assertSame('draft', $transfer->status);
        $this->withToken($this->token)->getJson('/api/user/users/12/transfers')->assertOk();
        $this->assertSame(0, $this->lockCalls);
        $this->assertDatabaseCount('fx_quotes', 0);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
    }

    public function test_explicit_fresh_lock_preserves_rate_binding_and_estimate_without_inventing_fees(): void
    {
        $transfer = $this->prepare();
        $uuid = Str::uuid()->toString();
        $response = $this->lock($transfer, $uuid)->assertOk()
            ->assertJsonPath('payout_amounts.fx_rate', '1.275939120')
            ->assertJsonPath('payout_amounts.estimated_conversion_amount', '6.3796956')
            ->assertJsonPath('payout_amounts.transfer_fee', null)
            ->assertJsonPath('payout_amounts.total_wallet_debit', null)
            ->assertJsonPath('payout_workflow.expected_fee.basis', 'user_expectation')
            ->assertJsonPath('payout_workflow.provider_fee_status', 'unavailable')
            ->assertJsonPath('payout_workflow.approval_request_allowed', false)
            ->assertJsonPath('payout_workflow.dispatch_allowed', false)
            ->assertJsonPath('payout_workflow.fx_lock_active', true);
        $this->assertSame(1, $this->lockCalls);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'lockExchangeRate') && $request->header('x-request-id') === [$uuid]);
        foreach (['customer-test', 'wallet-test', 'fixture-registered-id', '4243001', 'provider_context'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $proposal = app(NiumSgdLocalRemittancePayload::class)->forReview($transfer->fresh(), [['code' => 'TEST_PURPOSE']]);
        $this->assertSame([
            'beneficiary' => ['id' => 'fixture-registered-id'],
            'payout' => ['sourceAmount' => 5, 'sourceCurrency' => 'USD', 'auditId' => 4243001],
            'purposeCode' => 'TEST_PURPOSE', 'sourceOfFunds' => 'Corporate Account',
        ], $proposal);
        $admin = (new TransferDetailResource($transfer->fresh()))->resolve();
        $this->assertNull($admin['fee_amount']);
        $this->assertFalse($admin['allowed_actions']['approve']);
        $this->assertFalse($admin['payout_workflow']['dispatch_allowed']);
    }

    public function test_repeated_creation_and_lock_uuid_return_the_existing_operation(): void
    {
        $transfer = $this->prepare();
        $this->prepare();
        $uuid = Str::uuid()->toString();
        $this->lock($transfer, $uuid)->assertOk();
        $this->lock($transfer, $uuid)->assertOk();
        $this->lock($transfer)->assertUnprocessable();
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('fx_quotes', 1);
        $this->assertSame(1, $this->lockCalls);
        $this->withToken($this->token)->postJson('/api/user/users/12/sgd-local-payouts', $this->input(['client_reference' => 'OW-distinct-click']))->assertOk();
        $this->assertDatabaseCount('transfers', 2);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(1, $this->lockCalls);
    }

    public function test_an_interleaved_second_lock_request_cannot_dispatch_another_get(): void
    {
        $transfer = $this->prepare();
        $uuid = Str::uuid()->toString();
        $this->onLock = function () use ($transfer, $uuid): void {
            try {
                app(NiumSgdLocalPayoutWorkflow::class)->requestLock($transfer->fresh(), $uuid);
                $this->fail('A second request must observe the durable locking claim.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('pending or uncertain', $exception->getMessage());
            }
        };
        $this->lock($transfer, $uuid)->assertOk();
        $this->assertSame(1, $this->lockCalls);
    }

    public static function invalidInputs(): array
    {
        return [
            'wrong provider' => [['provider_id' => 11]], 'wrong beneficiary' => [['beneficiary_id' => 37]],
            'zero principal' => [['source_amount' => '0']], 'nonzero expected fee' => [['expected_fee_amount' => '1']],
            'missing purpose' => [['purpose_code' => '']], 'unsupported purpose' => [['purpose_code' => 'UNSUPPORTED']],
            'untruthful purpose' => [['purpose_confirmed' => false]], 'forged fee' => [['fee_amount' => 0]],
            'forged provider evidence' => [['raw_data' => ['provider_fee_confirmed' => true]]],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_preparations_never_acquire_a_hold(array $overrides): void
    {
        $status = ($overrides['beneficiary_id'] ?? null) === 37 ? 404 : 422;
        $this->withToken($this->token)->postJson('/api/user/users/12/sgd-local-payouts', $this->input($overrides))->assertStatus($status);
        $this->assertSame(0, $this->lockCalls);
        $this->assertDatabaseCount('transfers', 0);
    }

    public function test_available_balance_respects_reservations_and_does_not_assume_unknown_fees(): void
    {
        Balance::query()->sole()->update(['ledger_balance' => '85', 'available_balance' => '4.99', 'reserved_balance' => '80.01']);
        $this->withToken($this->token)->postJson('/api/user/users/12/sgd-local-payouts', $this->input())->assertUnprocessable();
        $this->assertSame(0, $this->lockCalls);
        $this->assertSame('80.01000000', Balance::query()->sole()->reserved_balance);
    }

    public function test_route_ownership_and_verified_account_binding_are_enforced(): void
    {
        User::factory()->create(['id' => 13]);
        $this->withToken($this->token)->postJson('/api/user/users/13/sgd-local-payouts', $this->input())->assertForbidden();
        $transfer = $this->prepare();
        UserProviderAccount::query()->sole()->update(['external_account_id' => 'changed-wallet']);
        $this->lock($transfer)->assertUnprocessable();
        $this->assertSame(0, $this->lockCalls);
    }

    public function test_expired_lock_requires_a_new_explicit_uuid_and_cannot_be_approved(): void
    {
        $transfer = $this->prepare();
        $uuid = Str::uuid()->toString();
        $this->lock($transfer, $uuid)->assertOk();
        $this->travel(11)->minutes();
        $this->withToken($this->token)->getJson("/api/user/users/12/transfers/{$transfer->id}")
            ->assertOk()->assertJsonPath('payout_workflow.fx_lock_active', false)->assertJsonPath('payout_amounts.fx_rate', null);
        $this->lock($transfer, $uuid)->assertUnprocessable();
        $this->assertSame(1, $this->lockCalls);
        $this->withToken($this->token)->postJson("/api/user/users/12/transfers/{$transfer->id}/sgd-local-request-approval", ['submission_token' => str_repeat('a', 64), 'accept_payment_terms' => true, 'purpose_confirmed' => true])->assertUnprocessable();
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->lock($transfer)->assertOk();
        $this->assertSame(2, $this->lockCalls);
        $this->travelBack();
    }

    public function test_unknown_lock_outcome_is_not_automatically_or_explicitly_repeated(): void
    {
        $transfer = $this->prepare();
        $this->onLock = fn () => throw new ConnectionException('Synthetic timeout, no live request.');
        $this->lock($transfer)->assertUnprocessable()->assertJsonPath('transfer.payout_workflow.state', 'lock_unknown');
        $this->lock($transfer)->assertUnprocessable();
        $this->assertSame(1, $this->lockCalls);
        $this->assertDatabaseCount('fx_quotes', 0);
    }

    public function test_fx_redirect_is_not_followed_or_retried_and_requires_reconciliation(): void
    {
        $transfer = $this->prepare();
        $this->redirectLock = true;
        $this->lock($transfer)->assertUnprocessable()->assertJsonPath('transfer.payout_workflow.state', 'lock_unknown');
        $this->lock($transfer)->assertUnprocessable();
        $this->assertSame(1, $this->lockCalls);
        $this->assertDatabaseCount('fx_quotes', 0);
    }

    public function test_payment_changed_during_lock_cannot_attach_or_release_the_hold(): void
    {
        $transfer = $this->prepare();
        $this->onLock = fn () => $transfer->update(['source_amount' => '6']);
        $this->lock($transfer)->assertUnprocessable()->assertJsonPath('transfer.payout_workflow.state', 'lock_unknown');
        $this->assertNull($transfer->fresh()->fx_quote_id);
        $this->assertSame(1, $this->lockCalls);
    }

    public function test_missing_fee_terms_block_approval_and_dispatch_without_reserving_money(): void
    {
        $transfer = $this->prepare();
        $this->lock($transfer)->assertOk();
        $this->withToken($this->token)->postJson("/api/user/users/12/transfers/{$transfer->id}/sgd-local-request-approval", ['submission_token' => str_repeat('a', 64), 'accept_payment_terms' => true, 'purpose_confirmed' => true])
            ->assertUnprocessable()->assertJsonPath('message', NiumSgdLocalPayoutGate::BLOCK_REASON);
        try {
            app(TransferApprovalService::class)->approve($transfer->fresh(), $this->authorizedChecker());
            $this->fail('Admin Approval must not authorize unknown fee terms.');
        } catch (RuntimeException $exception) {
            $this->assertSame(NiumSgdLocalPayoutGate::BLOCK_REASON, $exception->getMessage());
        }
        $this->withToken($this->token)->postJson("/api/user/users/12/transfers/{$transfer->id}/submit")->assertUnprocessable();
        $this->assertSame('draft', $transfer->fresh()->status);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(1, $this->lockCalls);
    }

    public function test_global_fx_flag_is_not_release_authorization(): void
    {
        $transfer = $this->prepare();
        config()->set('services.nium.payout_fx_enabled', false);
        $this->lock($transfer)->assertUnprocessable();
        $this->assertSame(0, $this->lockCalls);
        config()->set('services.nium.payout_fx_enabled', true);
        $this->lock($transfer)->assertOk()->assertJsonPath('payout_workflow.dispatch_allowed', false);
    }

    public function test_existing_approval_cannot_bypass_unknown_fee_admission_or_create_a_reservation(): void
    {
        $transfer = $this->prepare();
        $this->lock($transfer)->assertOk();
        $transfer->approvals()->create(['approver_user_id' => User::factory()->create()->id, 'action' => 'approved']);
        $transfer->update(['status' => 'approved']);
        $this->withToken($this->token)->postJson("/api/user/users/12/transfers/{$transfer->id}/submit")->assertUnprocessable();
        $this->assertSame('approved', $transfer->fresh()->status);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame(1, $this->lockCalls);
    }

    public function test_maker_cannot_approve_their_own_controlled_request(): void
    {
        $transfer = $this->prepare();
        $this->lock($transfer)->assertOk();
        $this->expectExceptionMessage('maker cannot approve');
        app(TransferApprovalService::class)->approve($transfer->fresh(), $this->maker);
    }

    public function test_unknown_fx_recovery_request_is_local_idempotent_and_preserves_the_hold_state(): void
    {
        $transfer = $this->prepare();
        $raw = $transfer->raw_data;
        $raw['sgd_local_workflow']['state'] = 'lock_unknown';
        $raw['sgd_local_workflow']['lock_request_id'] = Str::uuid()->toString();
        $transfer->update(['raw_data' => $raw]);
        $sent = count(Http::recorded());
        $url = "/api/user/users/12/transfers/{$transfer->id}/sgd-local-recovery-review";
        $first = $this->withToken($this->token)->postJson($url)->assertOk()
            ->assertJsonPath('payout_workflow.recovery_kind', 'fx_outcome_unknown');
        $at = $first->json('payout_workflow.recovery_review_requested_at');
        $this->assertNotNull($at);
        $this->withToken($this->token)->postJson($url)->assertOk()
            ->assertJsonPath('payout_workflow.recovery_review_requested_at', $at);
        $this->assertSame('lock_unknown', $transfer->fresh()->raw_data['sgd_local_workflow']['state']);
        $this->assertSame($sent, count(Http::recorded()));
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->lock($transfer->fresh())->assertUnprocessable();
    }

    public function test_missing_reference_requires_review_instead_of_a_blind_status_request(): void
    {
        $transfer = $this->prepare();
        $transfer->update(['status' => 'submission_unknown']);
        $this->withToken($this->token)->postJson("/api/user/users/12/transfers/{$transfer->id}/sgd-local-recovery-review")
            ->assertOk()->assertJsonPath('payout_workflow.recovery_kind', 'provider_reference_missing')
            ->assertJsonPath('payout_workflow.can_reconcile_status', false);
        $this->assertSame('submission_unknown', $transfer->fresh()->status);
    }

    public function test_unknown_provider_fee_cannot_be_reserved_by_calling_ledger_directly(): void
    {
        $transfer = $this->prepare();
        $this->expectExceptionMessage(NiumSgdLocalPayoutGate::BLOCK_REASON);
        try {
            app(LedgerService::class)->reserveTransfer($transfer);
        } finally {
            $this->assertDatabaseCount('ledger_entries', 0);
            $this->assertDatabaseCount('transfer_approvals', 0);
        }
    }

    public function test_global_approval_switch_cannot_authorize_a_maker_approved_sgd_payment(): void
    {
        $transfer = $this->prepare();
        $this->lock($transfer)->assertOk();
        $transfer->refresh();
        $snapshot = app(NiumSgdLocalPayoutAuthorization::class)->snapshot($transfer);
        $transfer->update(['status' => 'approved', 'raw_data' => array_merge($transfer->raw_data, ['sgd_local_approval' => $snapshot])]);
        $transfer->approvals()->create(['approver_user_id' => 12, 'action' => 'approved']);
        config()->set('wallet.transfer_controls.require_admin_approval', false);
        $this->expectExceptionMessage('requires Admin Approval');
        app(TransferApprovalService::class)->ensureApprovedForSubmission($transfer->fresh());
    }
}
