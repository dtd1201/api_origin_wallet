<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumSgdLocalZeroFeePolicy;
use App\Services\Nium\NiumPayoutFinancialControls;
use App\Services\Nium\NiumSgdAdmissionFence;
use App\Services\Nium\NiumPayoutFxLockService;
use App\Services\Nium\NiumService;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumSgdStandardTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;
    private User $admin;
    private IntegrationProvider $provider;
    private Beneficiary $beneficiary;
    private Balance $balance;
    private array $payload;
    private string $customerToken;
    private string $fenceDirectory;
    private ?\Closure $duringQuote = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Http::preventStrayRequests();
        // Isolated synthetic admission only; never touches the operator's admission directory.
        $this->fenceDirectory = sys_get_temp_dir().'/origin-sgd-contract-'.Str::uuid();
        mkdir($this->fenceDirectory, 0700);
        file_put_contents($this->fenceDirectory.'/drain.lock', '');
        file_put_contents($this->fenceDirectory.'/state', "OPEN v1\n");
        config()->set('sgd_admission.directory', $this->fenceDirectory);
        config()->set('services.nium.sgd_local_zero_fee.enabled', false);
        config()->set('services.nium.base_url', 'https://nium.test');
        config()->set('services.nium.client_id', 'synthetic-client');
        config()->set('services.nium.health_endpoint', '/api/v1/client/{clientHashId}');
        config()->set('services.nium.customer_create_endpoint', '/api/v1/client/{clientHashId}/customer');
        config()->set('services.nium.customer_list_endpoint', '/api/v1/client/{clientHashId}/customers');
        config()->set('services.nium.customer_get_endpoint', '/api/v1/client/{clientHashId}/customer/{customerHashId}');
        config()->set('wallet.ledger.enabled', true);
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-only']);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-only');
        config()->set('services.nium.payout_fx_enabled', true);
        config()->set('services.nium.quote_endpoint', '/api/v1/client/{clientHashId}/quotes');
        config()->set('services.nium.beneficiary_validation_schema_endpoint', '/validationSchemas');
        config()->set('services.nium.wallet_balance_endpoint', '/balances');
        config()->set('services.nium.supported_corridors', [[
            'destinationCountry' => 'SG', 'destinationCurrency' => 'SGD', 'payoutMethod' => 'LOCAL',
            'beneficiaryAccountType' => 'INDIVIDUAL', 'customerType' => 'CORPORATE', 'routingCodeType' => 'SWIFT',
        ]]);
        $this->provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $this->customer = User::factory()->create(['kyc_status' => 'verified']);
        $this->customer->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        $this->customer->kycProfile()->create([
            'status' => 'approved', 'applicant_type' => 'business', 'registered_country_code' => 'HK',
            'country_code' => 'HK', 'legal_name' => 'Synthetic HK Company', 'address_line1' => '1 Test Road', 'city' => 'Hong Kong',
        ]);
        $this->customer->providerAccounts()->create([
            'provider_id' => $this->provider->id, 'external_customer_id' => 'synthetic-customer', 'external_account_id' => 'synthetic-wallet',
            'status' => 'active', 'provider_status' => 'clear', 'reconciliation_status' => 'reconciled',
            'customer_id_verified_at' => now(), 'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now(),
        ]);
        $this->beneficiary = $this->customer->beneficiaries()->create([
            'provider_id' => $this->provider->id, 'external_beneficiary_id' => 'synthetic-local-beneficiary',
            'beneficiary_type' => 'individual', 'full_name' => 'Synthetic SG Individual', 'country_code' => 'SG', 'currency' => 'SGD',
            'payout_method' => 'LOCAL', 'swift_bic' => 'DBSSSGSG', 'account_number' => '123456789', 'status' => 'active',
            'raw_data' => ['nium' => ['payoutMethod' => 'LOCAL']],
        ]);
        $this->balance = Balance::query()->create([
            'user_id' => $this->customer->id, 'provider_id' => $this->provider->id, 'external_account_id' => 'synthetic-wallet',
            'currency' => 'USD', 'available_balance' => 100, 'ledger_balance' => 100, 'as_of' => now(),
        ]);
        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->roles()->create(['role_code' => 'admin']);
        $this->customerToken = $this->token($this->customer);
        $this->withToken($this->customerToken);
        $this->payload = [
            'provider_id' => $this->provider->id, 'beneficiary_id' => $this->beneficiary->id,
            'source_amount' => '5.00', 'purpose_code' => 'TEST_TRUTHFUL', 'reference_text' => 'SYNTHETIC-INSTRUCTION',
            'client_reference' => 'OW-'.Str::uuid(),
        ];
        Http::fake([
            '*purposeCodes*' => Http::response([['purposeCode' => 'TEST_TRUTHFUL', 'description' => 'Synthetic purpose']]),
            '*validationSchemas*' => Http::response([[
                'type' => 'object', 'required' => ['beneficiaryAccountNumber'],
                'properties' => ['beneficiaryAccountNumber' => ['type' => 'string', 'minLength' => 6]],
            ]]),
            '*lockExchangeRate*' => Http::response([
                'audit_id' => '112', 'fx_rate' => '1.32', 'hold_expiry_at' => now()->addMinutes(10)->toISOString(),
                'source_currency' => 'USD', 'destination_currency' => 'SGD', 'status' => 'ACTIVE',
            ]),
            '*quotes' => function ($request) {
                if ($this->duringQuote !== null) {
                    return ($this->duringQuote)($request);
                }
                $saved = Transfer::query()->where('client_reference', $this->payload['client_reference'])->firstOrFail();
                $this->assertNotNull($saved->id);
                $this->assertNotEmpty($saved->transfer_no);
                $this->assertSame('draft', $saved->status);
                $this->assertSame('locking', $saved->raw_data['sgd_local_workflow']['state']);
                $this->assertSame('at_conversion_time', $request['executionType']);
                $this->assertSame('immediate', $request['conversionSchedule']);
                $this->assertSame('15_mins', $request['lockPeriod']);
                $this->assertSame('payout', $request['quoteType']);
                $this->assertSame('EXECUTABLE', $request['quoteIntent']);
                $this->assertSame('synthetic-customer', $request['customerHashId']);
                $this->assertSame($saved->raw_data['sgd_local_workflow']['lock_request_id'], $request->header('x-request-id')[0]);
                $this->assertDatabaseCount('ledger_entries', 0);

                return Http::response([
                    'id' => 'synthetic-payout-quote', 'quoteType' => 'payout', 'quoteIntent' => 'EXECUTABLE',
                    'executionType' => 'at_conversion_time', 'conversionSchedule' => 'immediate',
                    'sourceAmount' => '5', 'sourceCurrencyCode' => 'USD', 'destinationCurrencyCode' => 'SGD',
                    'netExchangeRate' => '1.276738080', 'expiryTime' => now()->addSeconds(226)->toISOString(),
                ]);
            },
            '*remittance' => Http::response(['systemReferenceNumber' => 'MUST-NOT-DISPATCH']),
        ]);
    }

    private function token(User $user): string
    {
        $token = Str::random(80);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 'test-only', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);

        return $token;
    }

    protected function tearDown(): void
    {
        if (isset($this->fenceDirectory)) {
            unlink($this->fenceDirectory.'/state');
            unlink($this->fenceDirectory.'/drain.lock');
            rmdir($this->fenceDirectory);
        }
        parent::tearDown();
    }

    private function authorizeSyntheticBusinessTerms(array $changes = []): void
    {
        config()->set('services.nium.sgd_local_zero_fee', array_replace([
            'enabled' => true, 'version' => 'synthetic-v1',
            'confirmation_reference' => 'SYNTHETIC-CONFIRMATION', 'confirmed_by' => 'synthetic-business-owner',
            'confirmed_at' => now()->subMinute()->toISOString(), 'effective_from' => now()->subMinute()->toISOString(),
            'expires_at' => now()->addHour()->toISOString(),
            'provider_context' => ['base_url' => 'https://nium.test', 'client' => 'synthetic-client',
                'customer' => 'synthetic-customer', 'wallet' => 'synthetic-wallet'],
            'terms' => NiumSgdLocalZeroFeePolicy::TERMS,
        ], $changes));
    }

    private function accept(): Transfer
    {
        $review = $this->postJson($this->url().'/review', $this->payload)->assertOk()->json();
        $payload = [...$this->createPayload($review['review_token']), 'transfer_id' => $review['id']];
        $response = $this->postJson($this->url(), $payload)->assertCreated()->assertJsonPath('status', 'approval_required');
        // Exact replay returns the same instruction, including after response loss.
        $this->postJson($this->url(), $payload)->assertOk()->assertJsonPath('id', $response->json('id'));

        return Transfer::query()->findOrFail($response->json('id'));
    }

    private function createPayload(string $reviewToken): array
    {
        return [...$this->payload, 'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'SGD',
            'review_token' => $reviewToken, 'accept_payment_terms' => true, 'purpose_confirmed' => true];
    }

    private function url(): string
    {
        return '/api/user/users/'.$this->customer->id.'/transfers';
    }

    private function posts(): int
    {
        return collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/remittance'))->count();
    }

    private function assertNoDispatchOrReservation(): void
    {
        $this->assertSame(0, $this->posts());
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame('100.00000000', $this->balance->fresh()->available_balance);
        $this->assertSame('0.00000000', $this->balance->fresh()->reserved_balance);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'lockExchangeRate'));
    }

    public function test_closed_admission_denies_review_submit_approval_and_low_level_quote(): void
    {
        $this->authorizeSyntheticBusinessTerms();
        $transfer = $this->accept();
        file_put_contents($this->fenceDirectory.'/state', "CLOSED v1\n");
        $this->postJson($this->url().'/review', $this->payload)->assertUnprocessable()
            ->assertJsonPath('message', NiumSgdAdmissionFence::CLOSED)
            ->assertJsonPath('transfer.id', $transfer->id);
        $this->postJson($this->url(), $this->createPayload('unused'))->assertUnprocessable();
        $this->withToken($this->token($this->admin))->postJson('/api/admin/transfers/'.$transfer->id.'/approve')->assertUnprocessable();
        $calls = count(Http::recorded());
        try {
            app(NiumService::class)->post('/api/v1/client/synthetic-client/quotes', [
                'destinationCurrencyCode' => 'SGD',
            ], $this->customer);
            $this->fail('Closed admission must reject transport.');
        } catch (RuntimeException $exception) {
            $this->assertSame(NiumSgdAdmissionFence::CLOSED, $exception->getMessage());
        }
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertNoDispatchOrReservation();
    }

    public function test_missing_admission_configuration_fails_before_provider_io_or_draft_creation(): void
    {
        $this->authorizeSyntheticBusinessTerms();
        config()->set('sgd_admission.directory', null);
        $this->postJson($this->url().'/review', $this->payload)->assertUnprocessable()
            ->assertJsonPath('message', NiumSgdAdmissionFence::CLOSED);
        $this->assertDatabaseCount('transfers', 0);
        Http::assertNothingSent();
        $this->assertNoDispatchOrReservation();
    }

    public function test_review_saves_instruction_and_binds_modern_quote_with_actual_provider_expiry(): void
    {
        $this->authorizeSyntheticBusinessTerms();
        $review = $this->postJson($this->url().'/review', $this->payload)->assertOk()->json();
        $saved = Transfer::query()->findOrFail($review['id']);
        $this->assertSame($saved->transfer_no, $review['transfer_no']);
        $this->assertSame('draft', $review['status']);
        $this->assertSame('locked', $review['payout_workflow']['state']);
        $this->assertSame($saved->id, $saved->fxQuote->raw_data['workflow_transfer_id']);
        $this->assertSame(226, (int) now()->startOfSecond()->diffInSeconds($saved->fxQuote->expires_at));
        $this->assertSame('0', $review['terms']['fee_amount']);
        $this->assertSame('5', $review['terms']['total_wallet_debit']);
        $this->assertNull($review['payout_amounts']['beneficiary_credit']);
        $this->postJson($this->url().'/review', [...$this->payload, 'transfer_id' => $saved->id])
            ->assertOk()->assertJsonPath('id', $saved->id);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('fx_quotes', 1);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertNoDispatchOrReservation();
    }

    public function test_exact_customer_consent_requires_independent_admin_even_when_global_approval_is_disabled(): void
    {
        config()->set('wallet.transfer_controls.require_admin_approval', false);
        $this->authorizeSyntheticBusinessTerms();
        $transfer = $this->accept();
        $receipt = $transfer->raw_data['sgd_local_workflow']['customer_authorization'];
        $this->assertSame($this->customer->id, $receipt['accepted_by']);
        $this->assertSame('authenticated_customer_submit', $receipt['basis']);
        $this->assertTrue($receipt['payment_terms_accepted']);
        $this->assertTrue($receipt['purpose_confirmed']);
        $this->assertSame($transfer->id, $receipt['instruction']['transfer_id']);
        $this->assertSame(NiumSgdLocalZeroFeePolicy::BASIS, $receipt['instruction']['financial_terms']['admission_basis']);
        $this->assertSame('5', $receipt['instruction']['financial_terms']['total_wallet_debit']);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->postJson($this->url().'/'.$transfer->id.'/submit')->assertUnprocessable();

        $this->withToken($this->token($this->admin));
        $detail = $this->getJson('/api/admin/transfers/'.$transfer->id)->assertOk();
        $detail->assertJsonPath('allowed_actions.approve', true)
            ->assertJsonPath('payout_workflow.checker_approval_allowed', true)
            ->assertJsonPath('payout_workflow.submission_token', $receipt['submission_token'])
            ->assertJsonPath('payout_workflow.customer_authorization.instruction.transfer_id', $transfer->id);
        $this->postJson('/api/admin/transfers/'.$transfer->id.'/approve')->assertOk()->assertJsonPath('transfer.status', 'approved');
        $this->postJson('/api/admin/transfers/'.$transfer->id.'/approve')->assertOk()->assertJsonPath('transfer.status', 'approved');
        $approved = $transfer->fresh();
        $this->assertSame($approved->raw_data['sgd_local_workflow']['review']['snapshot'], $approved->raw_data['sgd_local_approval']);
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->assertNoDispatchOrReservation();
    }

    public function test_acceptance_replay_after_expiry_or_approval_preserves_original_receipt(): void
    {
        $this->authorizeSyntheticBusinessTerms();
        $review = $this->postJson($this->url().'/review', $this->payload)->assertOk()->json();
        $input = [...$this->createPayload($review['review_token']), 'transfer_id' => $review['id']];
        $id = $this->postJson($this->url(), $input)->assertCreated()->json('id');
        $before = Transfer::query()->findOrFail($id)->raw_data;
        app(TransferApprovalService::class)->approve(Transfer::query()->findOrFail($id), $this->admin);
        $this->postJson($this->url(), $input)->assertOk()->assertJsonPath('status', 'approved');
        $this->travel(5)->minutes();
        $this->postJson($this->url(), $input)->assertOk()->assertJsonPath('id', $id);
        $this->assertSame($before['sgd_local_workflow']['customer_authorization'],
            Transfer::query()->findOrFail($id)->raw_data['sgd_local_workflow']['customer_authorization']);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('fx_quotes', 1);
        $this->assertNoDispatchOrReservation();
    }

    public static function changedInstruction(): array
    {
        return [
            'amount' => [['source_amount' => '6']],
            'purpose' => [['purpose_code' => 'OTHER']],
            'reference' => [['reference_text' => 'OTHER']],
            'client reference' => [['client_reference' => 'OTHER']],
            'transfer id' => [['transfer_id' => 999999]],
        ];
    }

    #[DataProvider('changedInstruction')]
    public function test_submit_cannot_rebind_review_to_different_input(array $changes): void
    {
        $this->authorizeSyntheticBusinessTerms();
        $review = $this->postJson($this->url().'/review', $this->payload)->assertOk()->json();
        $input = [...$this->createPayload($review['review_token']), 'transfer_id' => $review['id']];
        $this->postJson($this->url(), array_replace($input, $changes))->assertConflict();
        $this->assertSame('draft', Transfer::query()->findOrFail($review['id'])->status);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertNoDispatchOrReservation();
    }

    public function test_changed_beneficiary_or_scoped_confirmation_requires_new_customer_review(): void
    {
        $this->authorizeSyntheticBusinessTerms();
        $review = $this->postJson($this->url().'/review', $this->payload)->assertOk()->json();
        $input = $this->createPayload($review['review_token']);
        $this->beneficiary->update(['account_number' => '987654321']);
        $this->postJson($this->url(), $input)->assertUnprocessable()->assertJsonPath('transfer.id', $review['id']);
        $this->beneficiary->update(['account_number' => '123456789']);
        config()->set('services.nium.sgd_local_zero_fee.confirmation_reference', 'CHANGED-CONFIRMATION');
        $this->postJson($this->url(), $input)->assertUnprocessable()->assertJsonPath('transfer.id', $review['id']);
        $this->assertSame('draft', Transfer::query()->findOrFail($review['id'])->status);
        $this->assertNoDispatchOrReservation();
    }

    public function test_token_cannot_be_used_with_another_owned_beneficiary(): void
    {
        $this->authorizeSyntheticBusinessTerms();
        $review = $this->postJson($this->url().'/review', $this->payload)->assertOk()->json();
        $other = $this->beneficiary->replicate();
        $other->external_beneficiary_id = 'synthetic-other-beneficiary';
        $other->save();
        $this->postJson($this->url(), [...$this->createPayload($review['review_token']), 'beneficiary_id' => $other->id])
            ->assertConflict();
        $this->assertNull(Transfer::query()->findOrFail($review['id'])->raw_data['sgd_local_workflow']['customer_authorization'] ?? null);
        $this->assertNoDispatchOrReservation();
    }

    public function test_tampered_foreign_and_expired_unaccepted_tokens_cannot_record_consent(): void
    {
        $this->authorizeSyntheticBusinessTerms();
        $review = $this->postJson($this->url().'/review', $this->payload)->assertOk()->json();
        $this->postJson($this->url(), $this->createPayload('forged'))->assertUnprocessable()->assertJsonPath('transfer.id', $review['id']);
        $this->postJson($this->url(), [...$this->createPayload($review['review_token']), 'accept_payment_terms' => false])->assertUnprocessable();
        // Pass profile.complete so this request exercises review-token ownership.
        $other = User::factory()->create(['status' => 'active', 'kyc_status' => 'verified']);
        $this->withToken($this->token($other))->postJson('/api/user/users/'.$other->id.'/transfers',
            $this->createPayload($review['review_token']))->assertForbidden();
        $this->withToken($this->customerToken);
        $this->travel(5)->minutes();
        $this->postJson($this->url(), $this->createPayload($review['review_token']))->assertUnprocessable()->assertJsonPath('transfer.id', $review['id']);
        $this->assertNull(Transfer::query()->findOrFail($review['id'])->raw_data['sgd_local_workflow']['customer_authorization'] ?? null);
        $this->assertNoDispatchOrReservation();
    }

    public function test_quote_timeout_keeps_saved_draft_and_never_automatically_repeats_fx(): void
    {
        $this->authorizeSyntheticBusinessTerms();
        $this->duringQuote = fn () => throw new \Illuminate\Http\Client\ConnectionException('synthetic timeout');
        $failure = $this->postJson($this->url().'/review', $this->payload)->assertUnprocessable();
        $id = $failure->json('transfer.id');
        $this->assertNotNull($id);
        $failure->assertJsonPath('transfer.payout_workflow.state', 'lock_unknown');
        $calls = count(Http::recorded());
        $this->postJson($this->url().'/review', [...$this->payload, 'transfer_id' => $id])->assertUnprocessable()
            ->assertJsonPath('transfer.id', $id);
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertDatabaseCount('transfers', 1);
        $this->assertNoDispatchOrReservation();
    }

    public function test_no_fee_authority_is_inferred_from_zero_storage_or_pilot_flags(): void
    {
        config()->set('services.nium.payout_pilot.enabled', true);
        $this->postJson($this->url().'/review', $this->payload)->assertUnprocessable()
            ->assertJsonPath('message', NiumPayoutFinancialControls::BLOCK_REASON);
        $this->authorizeSyntheticBusinessTerms(['expires_at' => now()->subSecond()->toISOString()]);
        $this->postJson($this->url().'/review', $this->payload)->assertUnprocessable();
        $this->authorizeSyntheticBusinessTerms(['provider_context' => ['base_url' => 'https://nium.test',
            'client' => 'synthetic-client', 'customer' => 'synthetic-customer', 'wallet' => 'OTHER']]);
        $this->postJson($this->url().'/review', $this->payload)->assertUnprocessable();
        $this->assertDatabaseCount('transfers', 0);
        $this->assertDatabaseCount('fx_quotes', 0);
        $this->assertNoDispatchOrReservation();
    }

    public function test_client_pricing_quote_and_authority_fields_cannot_supply_authorization(): void
    {
        $this->authorizeSyntheticBusinessTerms();
        foreach (['fx_quote_id' => 99, 'fx_rate' => '1.2', 'fee_amount' => '0',
            'total_wallet_debit' => '5', 'provider_verified' => true, 'raw_data' => ['authority' => true],
            'customer_authorization' => ['accepted_by' => $this->customer->id], 'accept_uncapped_charges' => true] as $field => $value) {
            $this->postJson($this->url().'/review', [...$this->payload, $field => $value])->assertUnprocessable();
            $this->postJson($this->url(), [...$this->createPayload('untrusted'), $field => $value])->assertUnprocessable();
        }
        $this->assertDatabaseCount('transfers', 0);
        $this->assertDatabaseCount('fx_quotes', 0);
        $this->assertNoDispatchOrReservation();
    }

    public function test_generic_quote_and_unbound_low_level_quote_are_denied_even_with_open_test_fence(): void
    {
        $this->authorizeSyntheticBusinessTerms();
        $this->postJson('/api/user/users/'.$this->customer->id.'/fx-quotes', [
            'provider_id' => $this->provider->id, 'source_currency' => 'USD', 'target_currency' => 'SGD', 'source_amount' => '5',
        ])->assertUnprocessable();
        try {
            app(NiumService::class)->post('/api/v1/client/synthetic-client/quotes',
                ['destinationCurrencyCode' => 'SGD'], $this->customer);
            $this->fail('A durable saved claim is mandatory.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('fx_quotes', 0);
        }
        $this->assertDatabaseCount('transfers', 0);
        $this->assertNoDispatchOrReservation();
    }

    public function test_maker_inactive_admin_and_permissioned_non_admin_cannot_approve(): void
    {
        $this->authorizeSyntheticBusinessTerms();
        $transfer = $this->accept();
        $this->customer->roles()->create(['role_code' => 'admin']);
        $inactive = User::factory()->create(['status' => 'inactive']);
        $inactive->roles()->create(['role_code' => 'admin']);
        $nonAdmin = \Mockery::mock(User::class)->makePartial();
        $nonAdmin->forceFill(['id' => 999999, 'status' => 'active']);
        $nonAdmin->shouldReceive('isAdmin')->andReturn(false);
        $nonAdmin->shouldReceive('hasPermission')->andReturn(true);
        foreach ([$this->customer, $inactive, $nonAdmin] as $checker) {
            try {
                app(TransferApprovalService::class)->approve($transfer, $checker);
                $this->fail('An active independent Admin is required.');
            } catch (RuntimeException) {
                $this->assertDatabaseCount('transfer_approvals', 0);
            }
        }
        $request = \Illuminate\Http\Request::create('/synthetic');
        $request->setUserResolver(fn () => $nonAdmin);
        $projection = (new \App\Http\Resources\Admin\TransferDetailResource($transfer))->resolve($request);
        $this->assertFalse($projection['allowed_actions']['approve']);
        $this->assertFalse($projection['payout_workflow']['checker_approval_allowed']);
        $this->assertNoDispatchOrReservation();
    }

    public function test_customer_projection_is_private_and_generic_admin_create_cannot_bypass_consent(): void
    {
        $this->authorizeSyntheticBusinessTerms();
        $transfer = $this->accept();
        $detail = $this->getJson($this->url().'/'.$transfer->id)->assertOk();
        $detail->assertJsonPath('payout_workflow.customer_accepted_at',
            $transfer->raw_data['sgd_local_workflow']['customer_authorization']['accepted_at']);
        foreach (['raw_data', 'external_payment_id', 'external_transfer_id'] as $field) {
            $this->assertArrayNotHasKey($field, $detail->json());
        }
        foreach (['customer_instruction', 'customer_authorization', 'submission_token', 'provider_context'] as $field) {
            $this->assertArrayNotHasKey($field, $detail->json('payout_workflow'));
        }
        $this->withToken($this->token($this->admin))->postJson('/api/admin/transfers', [
            ...$this->payload, 'transfer_no' => 'BYPASS-SGD', 'user_id' => $this->customer->id,
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'SGD', 'status' => 'approved',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('transfers', 1);
        $this->assertNoDispatchOrReservation();
    }

    public function test_transfer_with_external_payment_id_cannot_restart_an_expired_review(): void
    {
        $this->authorizeSyntheticBusinessTerms();
        $transfer = $this->accept();
        $transfer->update(['external_payment_id' => 'SYNTHETIC-EXISTING-PAYMENT']);
        $before = $transfer->fresh()->raw_data;
        $this->travel(5)->minutes();
        $this->postJson($this->url().'/review', [...$this->payload, 'transfer_id' => $transfer->id])->assertConflict();
        $this->assertSame($before, $transfer->fresh()->raw_data);
        $this->assertDatabaseCount('fx_quotes', 1);
        $this->assertNoDispatchOrReservation();
    }

    public function test_changed_account_after_consent_blocks_admin_snapshot_approval(): void
    {
        $this->authorizeSyntheticBusinessTerms();
        $transfer = $this->accept();
        $this->beneficiary->update(['account_number' => '0000987654321']);
        $this->withToken($this->token($this->admin));
        $this->getJson('/api/admin/transfers/'.$transfer->id)->assertOk()
            ->assertJsonPath('allowed_actions.approve', false)->assertJsonPath('payout_workflow.checker_approval_allowed', false);
        $this->postJson('/api/admin/transfers/'.$transfer->id.'/approve')->assertUnprocessable();
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertNoDispatchOrReservation();
    }

    public function test_expired_quote_prevents_admin_approval_without_a_new_fx_request(): void
    {
        $this->authorizeSyntheticBusinessTerms();
        $transfer = $this->accept();
        $this->travel(4)->minutes(); // Provider expiry is 226 seconds; source snapshot remains under five minutes old.
        $this->withToken($this->token($this->admin));
        $this->getJson('/api/admin/transfers/'.$transfer->id)->assertOk()->assertJsonPath('allowed_actions.approve', false);
        $this->postJson('/api/admin/transfers/'.$transfer->id.'/approve')->assertUnprocessable();
        $this->assertSame('approval_required', $transfer->fresh()->status);
        $this->assertDatabaseCount('fx_quotes', 1);
        $this->assertDatabaseCount('transfer_approvals', 0);
        $this->assertNoDispatchOrReservation();
    }

    public function test_review_reuses_existing_balance_sync_for_stale_bound_wallet_only(): void
    {
        $this->authorizeSyntheticBusinessTerms();
        $this->balance->update(['as_of' => now()->subHour()]);
        Http::fake(['*balances' => Http::response([[
            'currency' => 'USD', 'walletHashId' => 'synthetic-wallet', 'availableBalance' => '20',
        ]])]);
        $transfer = $this->accept();
        $this->assertSame('approval_required', $transfer->status);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/balances'));
        $this->assertSame('20.00000000', $this->balance->fresh()->available_balance);
        $this->assertSame('0.00000000', $this->balance->fresh()->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(0, $this->posts());
    }

    public function test_bound_wallet_freshness_and_provider_available_guard_are_preserved(): void
    {
        $this->authorizeSyntheticBusinessTerms();
        $this->balance->update(['reserved_balance' => '4', 'raw_data' => [
            'provider_available_balance' => '8', 'provider_balance_as_of' => now()->toISOString(),
        ]]);
        $this->postJson($this->url().'/review', $this->payload)->assertUnprocessable();
        $this->assertDatabaseCount('fx_quotes', 0);
        $this->assertDatabaseCount('transfers', 0);
        $this->assertSame('4.00000000', $this->balance->fresh()->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_transport_and_submission_eligibility_keep_sgd_dispatch_on_hold_after_approval(): void
    {
        $this->authorizeSyntheticBusinessTerms();
        $transfer = $this->accept();
        $approved = app(TransferApprovalService::class)->approve($transfer, $this->admin);
        try {
            app(TransferApprovalService::class)->ensureApprovedForSubmission($approved);
            $this->fail('Wire HOLD must apply even after independent approval.');
        } catch (RuntimeException $exception) {
            $this->assertSame(NiumSgdAdmissionFence::DISPATCH_HOLD, $exception->getMessage());
        }
        try {
            app(NiumService::class)->post('/remittance', [
                'beneficiary' => ['id' => 'synthetic-local-beneficiary'],
                'payout' => ['sourceCurrency' => 'USD', 'destinationCurrency' => 'SGD', 'payoutMethod' => 'LOCAL'],
            ], $this->customer, $approved->id);
            $this->fail('Transport must hold SGD remittance.');
        } catch (RuntimeException $exception) {
            $this->assertSame(NiumSgdAdmissionFence::DISPATCH_HOLD, $exception->getMessage());
        }
        $this->assertNoDispatchOrReservation();
    }
}
