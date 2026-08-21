<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Nium\NiumTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class NiumTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-partner-key');
    }

    public function test_submit_transfer_creates_nium_remittance_and_updates_transfer(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);

        config()->set('wallet.transfer_controls.require_admin_approval', false);

        $user = User::factory()->create(['kyc_status' => 'verified']);
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'cust_hash_123',
            'external_account_id' => 'wallet_hash_123',
            'status' => 'active',
            'provider_status' => 'clear',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
            'provider_ids_verified_at' => now(),
        ]);

        Balance::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'currency' => 'USD',
            'available_balance' => 1000,
            'ledger_balance' => 1000,
            'as_of' => now(),
        ]);

        $beneficiary = Beneficiary::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_beneficiary_id' => 'bnf_hash_123',
            'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe',
            'country_code' => 'IN',
            'currency' => 'INR',
            'status' => 'active',
        ]);

        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-NIUM123456',
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id,
            'transfer_type' => 'bank',
            'source_currency' => 'USD',
            'target_currency' => 'INR',
            'source_amount' => 100,
            'purpose_code' => 'IR001',
            'reference_text' => 'Invoice 42',
            'status' => 'draft',
            'raw_data' => [
                'nium' => [
                    'sourceOfFunds' => 'Personal Savings',
                    'payoutMethod' => 'LOCAL',
                ],
            ],
        ]);

        $quote = FxQuote::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'quote_ref' => '112',
            'source_currency' => 'USD',
            'target_currency' => 'INR',
            'source_amount' => 100,
            'target_amount' => 8300,
            'net_rate' => 83,
            'fee_amount' => 1,
            'expires_at' => now()->addMinutes(5),
            'raw_data' => ['provider_fx_type' => 'lock_and_hold', 'audit_id' => '112'],
        ]);
        $transfer->update(['fx_quote_id' => $quote->id, 'target_amount' => 8300, 'fx_rate' => 83, 'fee_amount' => 1]);

        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.com');
        config()->set('services.nium.client_id', 'client_hash_123');
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => 'nium-api-key',
        ]);

        Http::fake([
            'https://gateway.sandbox.nium.com/api/v1/client/client_hash_123/customer/cust_hash_123/wallet/wallet_hash_123/remittance' => Http::response([
                'message' => 'Transfer accepted',
                'payment_id' => 'pay_123',
                'system_reference_number' => 'RT6431795378',
            ], 200),
        ]);

        $updated = app(NiumTransferService::class)->submitTransfer(
            $provider,
            $transfer->fresh(['provider', 'user', 'beneficiary'])
        );

        $this->assertSame('RT6431795378', $updated->external_transfer_id);
        $this->assertSame('pay_123', $updated->external_payment_id);
        $this->assertSame('pending', $updated->status);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://gateway.sandbox.nium.com/api/v1/client/client_hash_123/customer/cust_hash_123/wallet/wallet_hash_123/remittance'
                && $request->hasHeader('x-api-key', 'nium-api-key')
                && $data['beneficiary']['id'] === 'bnf_hash_123'
                && $data['payout']['sourceCurrency'] === 'USD'
                && $data['payout']['sourceAmount'] === 100.0
                && $data['payout']['destinationCurrency'] === 'INR'
                && $data['payout']['payoutMethod'] === 'LOCAL'
                && $data['payout']['auditId'] === 112
                && $data['purposeCode'] === 'IR001'
                && $data['sourceOfFunds'] === 'Personal Savings';
        });

        $this->assertNotEmpty($updated->provider_operation_key);

        $log = ApiRequestLog::query()->where('related_transfer_id', $transfer->id)->sole();
        $this->assertSame('transfer_money', $log->operation);
        $this->assertSame($updated->provider_operation_key, $log->external_reference);
        $this->assertSame(200, $log->response_status);
        $this->assertSame('response_received', $log->transport_outcome);
        $this->assertSame(substr(hash('sha256', 'bnf_hash_123'), 0, 16), $log->request_body['beneficiary_id_fingerprint']);
        $this->assertSame('LOCAL', $log->request_body['payout_method']);
        $this->assertSame('USD', $log->request_body['source_currency']);
        $this->assertSame('INR', $log->request_body['destination_currency']);
        $this->assertSame('IR001', $log->request_body['purpose_code']);
        $this->assertSame('Personal Savings', $log->request_body['source_of_funds']);
        $this->assertContains('beneficiary.id', $log->request_body['payload_keys']);
        $this->assertContains('payout.payoutMethod', $log->request_body['payload_keys']);
        $this->assertSame('RT6431795378', $log->response_body['system_reference_number']);
        $this->assertSame('pay_123', $log->response_body['payment_id']);
    }

    public function test_sync_transfer_status_queries_nium_audit_and_updates_transfer(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'cust_hash_123',
            'external_account_id' => 'wallet_hash_123',
            'status' => 'active',
            'provider_status' => 'clear',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
            'provider_ids_verified_at' => now(),
        ]);

        $beneficiary = Beneficiary::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_beneficiary_id' => 'bnf_hash_123',
            'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe',
            'country_code' => 'IN',
            'currency' => 'INR',
            'status' => 'active',
        ]);

        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-NIUM123456',
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id,
            'external_transfer_id' => 'RT6431795378',
            'transfer_type' => 'bank',
            'source_currency' => 'USD',
            'target_currency' => 'INR',
            'source_amount' => 100,
            'status' => 'pending',
        ]);

        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.com');
        config()->set('services.nium.client_id', 'client_hash_123');
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => 'nium-api-key',
        ]);

        Http::fake([
            'https://gateway.sandbox.nium.com/api/v1/client/client_hash_123/customer/cust_hash_123/wallet/wallet_hash_123/remittance/RT6431795378/audit' => Http::response([
                [
                    'status' => 'PENDING',
                ],
                [
                    'status' => 'COMPLETED',
                    'paymentReferenceNumber' => 'pay_123',
                    'lastUpdatedAt' => now()->toISOString(),
                ],
            ], 200),
        ]);

        $updated = app(NiumTransferService::class)->syncTransferStatus(
            $provider,
            $transfer->fresh(['provider', 'user', 'beneficiary'])
        );

        $this->assertSame('completed', $updated->status);
        $this->assertSame('pay_123', $updated->external_payment_id);
        $this->assertNotNull($updated->completed_at);
    }

    public function test_same_transfer_submitted_twice_sends_one_provider_post(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        Http::fake(['*' => Http::response(['systemReferenceNumber' => 'RT-ONCE', 'paymentId' => 'PAY-ONCE'])]);

        app(NiumTransferService::class)->submitTransfer($provider, $transfer);

        try {
            app(NiumTransferService::class)->submitTransfer($provider, $transfer->fresh());
            $this->fail('A second provider submission should be rejected.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        Http::assertSentCount(1);
    }

    public function test_same_currency_transfer_does_not_require_fx_lock(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'target_currency' => 'USD',
            'fx_quote_id' => null,
            'fx_rate' => null,
        ]);
        Http::fake(['*' => Http::response(['systemReferenceNumber' => 'RT-SAME-CURRENCY'])]);

        $updated = app(NiumTransferService::class)->submitTransfer(
            $provider,
            $transfer->fresh(['provider', 'user', 'beneficiary'])
        );

        $this->assertSame('pending', $updated->status);
        Http::assertSent(fn ($request): bool => ! array_key_exists('auditId', $request->data()['payout']));
    }

    public function test_cross_currency_transfer_without_fx_lock_uses_live_transfer_money_contract(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'fx_quote_id' => null,
            'fx_rate' => null,
        ]);
        Http::fake(['*' => Http::response(['systemReferenceNumber' => 'RT-LIVE-FX'])]);

        $updated = app(NiumTransferService::class)->submitTransfer(
            $provider,
            $transfer->fresh(['provider', 'user', 'beneficiary'])
        );

        $this->assertSame('pending', $updated->status);
        Http::assertSent(fn ($request): bool => ! array_key_exists('auditId', $request->data()['payout']));
    }

    public function test_swift_transfer_requires_and_sends_configured_fee_type(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'purpose_code' => 'IR01811',
            'raw_data' => [
                'nium' => [
                    'sourceOfFunds' => 'Corporate Account',
                    'payoutMethod' => 'SWIFT',
                    'payout' => ['swiftFeeType' => 'SHA'],
                ],
            ],
        ]);
        Http::fake(['*' => Http::response(['systemReferenceNumber' => 'RT-SWIFT'])]);

        app(NiumTransferService::class)->submitTransfer($provider, $transfer);

        Http::assertSent(fn ($request): bool => $request->data()['payout']['payoutMethod'] === 'SWIFT'
            && $request->data()['payout']['swiftFeeType'] === 'SHA'
            && $request->data()['purposeCode'] === 'IR01811'
            && $request->data()['sourceOfFunds'] === 'Corporate Account');
    }

    public function test_provider_error_appends_operational_data_without_replacing_nium_fixture_data(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');
        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'purpose_code' => 'IR01811',
            'raw_data' => [
                'nium' => [
                    'sourceOfFunds' => 'Corporate Account',
                    'payoutMethod' => 'SWIFT',
                    'payout' => ['swiftFeeType' => 'SHA'],
                ],
            ],
        ]);
        Http::fake(['*' => Http::response([
            'status' => 'BAD_REQUEST',
            'code' => 'invalid_request',
            'errorCode' => 'REM_400_001',
            'message' => 'Invalid transfer request.',
            'errors' => [[
                'field' => 'purposeCode',
                'code' => 'invalid_enum',
                'message' => 'Invalid purposeCode value.',
                'rejectedValue' => 'must-not-be-logged',
            ]],
            'validationErrors' => [[
                'field' => 'payout.swiftFeeType',
                'message' => 'Validation failed for swiftFeeType.',
            ]],
        ], 400)]);

        try {
            app(NiumTransferService::class)->submitTransfer($provider, $transfer);
            $this->fail('Expected the provider error to reject the transfer.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Invalid transfer request.', $exception->getMessage());
        }

        $rawData = (array) $transfer->fresh()->raw_data;
        $this->assertSame('Corporate Account', $rawData['nium']['sourceOfFunds']);
        $this->assertSame('SWIFT', $rawData['nium']['payoutMethod']);
        $this->assertSame('SHA', $rawData['nium']['payout']['swiftFeeType']);
        $this->assertSame('BAD_REQUEST', $rawData['provider_status']);
        $this->assertSame('invalid_request', $rawData['provider_error_code']);
        $this->assertNotEmpty($rawData['provider_operation_key']);

        $log = ApiRequestLog::query()->where('related_transfer_id', $transfer->id)->sole();
        $this->assertSame('invalid_request', $log->response_body['code']);
        $this->assertSame('REM_400_001', $log->response_body['errorCode']);
        $this->assertSame('Invalid transfer request.', $log->response_body['message']);
        $this->assertSame('purposeCode', $log->response_body['errors'][0]['field']);
        $this->assertSame('Invalid purposeCode value.', $log->response_body['errors'][0]['message']);
        $this->assertSame('payout.swiftFeeType', $log->response_body['validationErrors'][0]['field']);
        $this->assertStringNotContainsString('must-not-be-logged', json_encode($log->response_body, JSON_THROW_ON_ERROR));
    }

    public function test_swift_transfer_without_fee_type_fails_before_http(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'raw_data' => [
                'nium' => [
                    'sourceOfFunds' => 'Corporate Account',
                    'payoutMethod' => 'SWIFT',
                ],
            ],
        ]);
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires swiftFeeType');

        try {
            app(NiumTransferService::class)->submitTransfer($provider, $transfer);
        } finally {
            Http::assertNothingSent();
            $this->assertSame('draft', $transfer->fresh()->status);
        }
    }

    public function test_timeout_after_provider_acceptance_marks_unknown_and_never_posts_again(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('Timed out after provider acceptance.');
        });

        $unknown = app(NiumTransferService::class)->submitTransfer($provider, $transfer);
        $this->assertSame('submission_unknown', $unknown->status);

        try {
            app(NiumTransferService::class)->submitTransfer($provider, $unknown->fresh());
            $this->fail('An unknown submission must not retry the provider POST.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $this->assertSame(1, $attempts);
    }

    public function test_evidence_persistence_failure_marks_unknown_and_never_posts_again(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        Http::fake(['*' => Http::response(['systemReferenceNumber' => 'RT-EVIDENCE-UNKNOWN'])]);
        ApiRequestLog::creating(static function (): void {
            throw new RuntimeException('Synthetic evidence persistence failure.');
        });

        $unknown = app(NiumTransferService::class)->submitTransfer($provider, $transfer);

        $this->assertSame('submission_unknown', $unknown->status);

        try {
            app(NiumTransferService::class)->submitTransfer($provider, $unknown->fresh());
            $this->fail('An evidence persistence failure must not retry the provider POST.');
        } catch (RuntimeException) {
            // State validation rejects before provider HTTP.
        }

        Http::assertSentCount(1);
    }

    public function test_invalid_state_sends_zero_http_requests(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer(['status' => 'pending']);
        Http::fake();

        $this->expectException(RuntimeException::class);

        try {
            app(NiumTransferService::class)->submitTransfer($provider, $transfer);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_audit_sorts_by_timestamp_and_does_not_regress_terminal_state(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'status' => 'completed',
            'external_transfer_id' => 'RT-ORDERED',
            'provider_status_at' => now(),
            'completed_at' => now(),
        ]);
        Http::fake(['*' => Http::response([
            ['status' => 'COMPLETED', 'lastUpdatedAt' => now()->toISOString()],
            ['status' => 'PENDING', 'lastUpdatedAt' => now()->subMinute()->toISOString()],
        ])]);

        $updated = app(NiumTransferService::class)->syncTransferStatus($provider, $transfer);

        $this->assertSame('completed', $updated->status);
    }

    private function makeSubmittableTransfer(array $overrides = []): array
    {
        config()->set('wallet.transfer_controls.require_admin_approval', false);
        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.test');
        config()->set('services.nium.client_id', 'client_hash_test');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-key']);

        $provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $user = User::factory()->create(['kyc_status' => 'verified']);
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'customer-test',
            'external_account_id' => 'wallet-test',
            'status' => 'active',
            'provider_status' => 'clear',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
            'provider_ids_verified_at' => now(),
        ]);
        Balance::query()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id, 'currency' => 'USD',
            'available_balance' => 1000, 'ledger_balance' => 1000, 'as_of' => now(),
        ]);
        $beneficiary = Beneficiary::query()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id, 'external_beneficiary_id' => 'beneficiary-test',
            'beneficiary_type' => 'personal', 'full_name' => 'Test Payee', 'country_code' => 'IN',
            'currency' => 'INR', 'status' => 'active',
        ]);
        $quote = FxQuote::query()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id, 'quote_ref' => (string) random_int(1000, 9999),
            'source_currency' => 'USD', 'target_currency' => 'INR', 'source_amount' => 10,
            'target_amount' => 830, 'net_rate' => 83, 'fee_amount' => 1, 'expires_at' => now()->addMinutes(5),
            'raw_data' => ['provider_fx_type' => 'lock_and_hold'],
        ]);
        $transfer = Transfer::query()->create(array_merge([
            'transfer_no' => 'TRF-'.strtoupper(uniqid()), 'user_id' => $user->id, 'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id, 'fx_quote_id' => $quote->id, 'transfer_type' => 'bank',
            'source_currency' => 'USD', 'target_currency' => 'INR', 'source_amount' => 10,
            'target_amount' => 830, 'fx_rate' => 83, 'fee_amount' => 1, 'status' => 'draft',
            'purpose_code' => 'IR001',
            'raw_data' => ['nium' => ['sourceOfFunds' => 'Corporate Account', 'payoutMethod' => 'LOCAL']],
        ], $overrides));

        return [$provider, $transfer->fresh(['provider', 'user', 'beneficiary', 'fxQuote'])];
    }
}
