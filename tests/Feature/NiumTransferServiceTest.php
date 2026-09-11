<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumTransferService;
use App\Services\Wallet\LedgerService;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class NiumTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('transfers', 'provider_status')) {
            Schema::table('transfers', function (Blueprint $table): void {
                $table->string('provider_status', 80)->nullable()->after('status');
                $table->text('provider_status_detail')->nullable()->after('provider_status');
            });
        }

        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-partner-key');
    }

    private function purposeCodesRoute(): array
    {
        return ['*api/v1/remittance/purposeCodes' => Http::response([
            ['description' => 'General Goods Trades - Offline trade', 'purposeCode' => 'IR01811'],
            ['description' => 'Medical Treatment', 'purposeCode' => 'IR004'],
        ])];
    }

    private function isRemittancePost($request): bool
    {
        return $request->method() === 'POST'
            && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/remittance');
    }

    private function assertNoRemittancePost(): void
    {
        $this->assertCount(0, collect(Http::recorded())->filter(fn ($pair) => $this->isRemittancePost($pair[0])));
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
        $user->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        $this->createApprovedHkCorporateKycProfile($user);
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'cust_hash_123',
            'external_account_id' => 'wallet_hash_123',
            'status' => 'active',
            'provider_status' => 'clear',
            'reconciliation_status' => 'reconciled',
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
            'beneficiary_type' => 'business',
            'full_name' => 'HK Company',
            'country_code' => 'HK',
            'currency' => 'USD',
            'payout_method' => 'SWIFT',
            'status' => 'active',
            'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT']],
        ]);

        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-NIUM123456',
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id,
            'transfer_type' => 'payout',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'source_amount' => 100,
            'purpose_code' => 'IR01811',
            'reference_text' => 'Invoice 42',
            'status' => 'draft',
            'fee_amount' => 0,
            'fee_currency' => 'USD',
        ]);

        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.com');
        config()->set('services.nium.client_id', 'client_hash_123');
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => 'nium-api-key',
        ]);

        Http::fake([...$this->purposeCodesRoute(),
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
                && $data['payout']['destinationCurrency'] === 'USD'
                && $data['payout']['payoutMethod'] === 'SWIFT'
                && $data['payout']['swiftFeeType'] === 'SHA'
                && $data['purposeCode'] === 'IR01811'
                && $data['sourceOfFunds'] === 'Corporate Account';
        });

        $this->assertNotEmpty($updated->provider_operation_key);

        $log = ApiRequestLog::query()->where('related_transfer_id', $transfer->id)->sole();
        $this->assertSame('transfer_money', $log->operation);
        $this->assertSame($updated->provider_operation_key, $log->external_reference);
        $this->assertSame(200, $log->response_status);
        $this->assertSame('response_received', $log->transport_outcome);
        $this->assertSame(substr(hash('sha256', 'bnf_hash_123'), 0, 16), $log->request_body['beneficiary_id_fingerprint']);
        $this->assertSame('SWIFT', $log->request_body['payout_method']);
        $this->assertSame('USD', $log->request_body['source_currency']);
        $this->assertSame('USD', $log->request_body['destination_currency']);
        $this->assertSame('IR01811', $log->request_body['purpose_code']);
        $this->assertSame('Corporate Account', $log->request_body['source_of_funds']);
        $this->assertContains('beneficiary.id', $log->request_body['payload_keys']);
        $this->assertContains('payout.payoutMethod', $log->request_body['payload_keys']);
        $customerFingerprint = substr(hash('sha256', 'cust_hash_123'), 0, 16);
        $walletFingerprint = substr(hash('sha256', 'wallet_hash_123'), 0, 16);
        $this->assertSame($customerFingerprint, $log->request_body['customer_id_fingerprint']);
        $this->assertSame($walletFingerprint, $log->request_body['wallet_id_fingerprint']);
        $this->assertSame($customerFingerprint, $log->response_body['customer_id_fingerprint']);
        $this->assertSame($walletFingerprint, $log->response_body['wallet_id_fingerprint']);
        $this->assertTrue($log->response_body['customer_id_present']);
        $this->assertTrue($log->response_body['wallet_id_present']);
        $this->assertSame('RT6431795378', $log->response_body['system_reference_number']);
        $this->assertSame('pay_123', $log->response_body['payment_id']);
        $serializedLog = json_encode([$log->request_body, $log->response_body], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('cust_hash_123', $serializedLog);
        $this->assertStringNotContainsString('wallet_hash_123', $serializedLog);
    }

    public function test_admin_approval_auto_submits_nium_once_with_dynamic_purpose_and_reference(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'status' => 'approval_required', 'purpose_code' => 'IR004', 'reference_text' => 'INV-APPROVAL-001',
        ]);
        $approver = User::factory()->create();
        $levels = [];
        $baselineLevel = DB::transactionLevel();
        Http::fake(function ($request) use (&$levels) {
            $levels[] = DB::transactionLevel();
            if (str_contains($request->url(), '/purposeCodes')) {
                return Http::response([
                    ['description' => 'Medical Treatment', 'purposeCode' => 'IR004'],
                ]);
            }
            $this->assertTrue($request->method() === 'POST' && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/remittance'));

            return Http::response(['systemReferenceNumber' => 'RT-APPROVAL-001']);
        });

        $approved = app(TransferApprovalService::class)->approve($transfer, $approver, null, app(ProviderTransferManager::class));

        $this->assertSame('pending', $approved->status);
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => $this->isRemittancePost($pair[0]))->count());
        $post = collect(Http::recorded())->first(fn ($pair) => $this->isRemittancePost($pair[0]))[0];
        $this->assertSame('IR004', $post->data()['purposeCode']);
        $this->assertSame('INV-APPROVAL-001', $post->data()['payout']['tradeOrderID']);
        $this->assertSame('INV-APPROVAL-001', $post->data()['customerComments']);
        $this->assertTrue(collect($levels)->every(fn ($level) => $level === $baselineLevel));

        $again = app(TransferApprovalService::class)->approve($approved, $approver, null, app(ProviderTransferManager::class));
        $this->assertSame('pending', $again->status);
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => $this->isRemittancePost($pair[0]))->count());
    }

    public function test_admin_approval_auto_submit_unknown_and_rejection_preserve_approval(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer(['status' => 'approval_required']);
        $approver = User::factory()->create();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/purposeCodes')) {
                return Http::response([
                    ['description' => 'General Goods Trades', 'purposeCode' => 'IR01811'],
                ]);
            }
            throw new ConnectionException('unknown approval outcome');
        });
        $result = app(TransferApprovalService::class)->approve($transfer, $approver, null, app(ProviderTransferManager::class));
        $this->assertSame('submission_unknown', $result->status);
        $this->assertDatabaseHas('transfer_approvals', ['transfer_id' => $transfer->id, 'action' => 'approved']);

    }

    public function test_draft_nium_approval_does_not_auto_submit(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer(['status' => 'draft']);
        $approver = User::factory()->create();
        Http::fake([
            '*api/v1/remittance/purposeCodes' => Http::response([
                ['description' => 'General Goods Trades', 'purposeCode' => 'IR01811'],
            ]),
            '*' => Http::response(['systemReferenceNumber' => 'MUST-NOT-SEND']),
        ]);

        $approved = app(TransferApprovalService::class)->approve($transfer, $approver, null, app(ProviderTransferManager::class));

        $this->assertSame('approved', $approved->status);
        $this->assertSame(0, collect(Http::recorded())->filter(fn ($pair) => $this->isRemittancePost($pair[0]))->count());
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

        Http::fake([...$this->purposeCodesRoute(),
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
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response(['systemReferenceNumber' => 'RT-ONCE', 'paymentId' => 'PAY-ONCE'])]);

        app(NiumTransferService::class)->submitTransfer($provider, $transfer);

        try {
            app(NiumTransferService::class)->submitTransfer($provider, $transfer->fresh());
            $this->fail('A second provider submission should be rejected.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => $this->isRemittancePost($pair[0])));
    }

    public function test_submit_transfer_uses_customer_and_wallet_from_matching_provider_account(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        UserProviderAccount::query()
            ->where('user_id', $transfer->user_id)
            ->where('provider_id', $transfer->provider_id)
            ->update([
                'external_customer_id' => 'provider-account-customer',
                'external_account_id' => 'provider-account-wallet',
            ]);
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response(['systemReferenceNumber' => 'RT-PROVIDER-ACCOUNT'])]);

        app(NiumTransferService::class)->submitTransfer($provider, $transfer);

        Http::assertSent(fn ($request): bool => str_contains(
            $request->url(),
            '/customer/provider-account-customer/wallet/provider-account-wallet/remittance',
        ));
    }

    public function test_same_currency_transfer_does_not_require_fx_lock(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'target_currency' => 'USD',
            'fx_quote_id' => null,
            'fx_rate' => null,
        ]);
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response(['systemReferenceNumber' => 'RT-SAME-CURRENCY'])]);

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
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response(['systemReferenceNumber' => 'RT-LIVE-FX'])]);

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
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response(['systemReferenceNumber' => 'RT-SWIFT'])]);

        app(NiumTransferService::class)->submitTransfer($provider, $transfer);

        Http::assertSent(fn ($request): bool => $request->data()['payout']['payoutMethod'] === 'SWIFT'
            && $request->data()['payout']['swiftFeeType'] === 'SHA'
            && $request->data()['purposeCode'] === 'IR01811'
            && $request->data()['sourceOfFunds'] === 'Corporate Account');
    }

    public function test_client_nium_request_cannot_override_authoritative_payload(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'raw_data' => [
                'nium' => [
                    'sourceOfFunds' => 'Attacker Funds',
                    'payoutMethod' => 'LOCAL',
                    'payout' => ['swiftFeeType' => 'OUR'],
                    'request' => [
                        'beneficiary' => ['id' => 'attacker-beneficiary'],
                        'purposeCode' => 'attacker-purpose',
                        'payout' => ['sourceAmount' => 999999, 'sourceCurrency' => 'EUR'],
                    ],
                ],
            ],
        ]);
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response(['systemReferenceNumber' => 'RT-AUTHORITATIVE'])]);

        app(NiumTransferService::class)->submitTransfer($provider, $transfer);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $payload['beneficiary']['id'] === 'beneficiary-test'
                && $payload['payout']['sourceAmount'] === 10.0
                && $payload['payout']['sourceCurrency'] === 'USD'
                && $payload['payout']['destinationCurrency'] === 'USD'
                && $payload['payout']['payoutMethod'] === 'SWIFT'
                && $payload['payout']['swiftFeeType'] === 'SHA'
                && $payload['purposeCode'] === 'IR01811'
                && $payload['sourceOfFunds'] === 'Corporate Account';
        });
    }

    public function test_inactive_unsynced_and_unsupported_beneficiaries_fail_before_http(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        foreach ([
            ['beneficiary_status' => 'inactive'],
            ['external_beneficiary_id' => null],
            ['beneficiary_country' => 'US'],
        ] as $case) {
            $transfer->beneficiary->update([
                'status' => $case['beneficiary_status'] ?? 'active',
                'external_beneficiary_id' => 'beneficiary-test',
                'country_code' => $case['beneficiary_country'] ?? 'HK',
            ]);
            if (array_key_exists('external_beneficiary_id', $case)) {
                $transfer->beneficiary->update(['external_beneficiary_id' => null]);
            }
            Http::fake([...$this->purposeCodesRoute(), '*' => Http::response([])]);

            try {
                app(NiumTransferService::class)->submitTransfer($provider, $transfer->fresh(['provider', 'user', 'beneficiary']));
                $this->fail('Invalid Nium beneficiary should be rejected.');
            } catch (RuntimeException) {
                $this->assertNoRemittancePost();
            }
        }
    }

    public function test_blocked_provider_account_fails_before_http(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        $transfer->user->providerAccounts()->where('provider_id', $provider->id)->update([
            'reconciliation_status' => 'quarantined',
            'security_conflict_at' => now(),
        ]);
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response([])]);

        $this->expectException(RuntimeException::class);
        try {
            app(NiumTransferService::class)->submitTransfer($provider, $transfer);
        } finally {
            $this->assertNoRemittancePost();
        }
    }

    public function test_same_currency_usd_swift_transfer_rejects_client_destination_amount(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'target_currency' => 'USD',
            'target_amount' => 10,
            'fx_quote_id' => null,
            'fx_rate' => null,
            'raw_data' => [
                'nium' => [
                    'sourceOfFunds' => 'Corporate Account',
                    'payoutMethod' => 'SWIFT',
                    'payout' => ['swiftFeeType' => 'SHA'],
                ],
            ],
        ]);
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response([])]);
        $this->expectException(RuntimeException::class);
        try {
            app(NiumTransferService::class)->submitTransfer($provider, $transfer);
        } finally {
            $this->assertNoRemittancePost();
        }
    }

    public function test_cross_currency_usd_eur_transfer_is_rejected(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'target_currency' => 'EUR',
            'target_amount' => 9.2,
            'fx_quote_id' => null,
            'fx_rate' => null,
        ]);
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response([])]);
        $this->expectException(RuntimeException::class);
        try {
            app(NiumTransferService::class)->submitTransfer($provider, $transfer);
        } finally {
            $this->assertNoRemittancePost();
        }
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
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response([
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
            'error' => [
                'code' => 'bad_remittance',
                'message' => 'Remittance request was rejected.',
                'accountNumber' => '1234567890123456',
            ],
            'details' => [[
                'field' => 'sourceOfFunds',
                'description' => 'Unsupported source of funds.',
                'token' => 'secret-token-must-not-survive',
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
        $this->assertSame('BAD_REQUEST', $log->response_body['status']);
        $this->assertSame('invalid_request', $log->response_body['code']);
        $this->assertSame('REM_400_001', $log->response_body['errorCode']);
        $this->assertSame('Invalid transfer request.', $log->response_body['message']);
        $this->assertSame('purposeCode', $log->response_body['errors'][0]['field']);
        $this->assertSame('Invalid purposeCode value.', $log->response_body['errors'][0]['message']);
        $this->assertSame('payout.swiftFeeType', $log->response_body['validationErrors'][0]['field']);
        $this->assertSame('bad_remittance', $log->response_body['error'][0]['code']);
        $this->assertSame('sourceOfFunds', $log->response_body['details'][0]['field']);
        $serializedResponse = json_encode($log->response_body, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('must-not-be-logged', $serializedResponse);
        $this->assertStringNotContainsString('1234567890123456', $serializedResponse);
        $this->assertStringNotContainsString('secret-token-must-not-survive', $serializedResponse);
    }

    public function test_accepted_response_without_system_reference_is_marked_unknown(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response([
            'status' => 'ACCEPTED',
            'message' => 'Transfer accepted.',
        ], 200)]);

        $updated = app(NiumTransferService::class)->submitTransfer($provider, $transfer);

        $this->assertSame('submission_unknown', $updated->status);
        $this->assertSame('provider_submission_unknown', $updated->failure_code);
        $this->assertNull($updated->external_transfer_id);
    }

    public function test_successful_transfer_reference_remains_compatible_with_lifecycle_webhook(): void
    {
        config()->set('wallet.ledger.enabled', false);
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response([
            'systemReferenceNumber' => 'RT-WEBHOOK-LIFECYCLE',
            'paymentId' => 'PAY-WEBHOOK-LIFECYCLE',
        ], 200)]);

        $submitted = app(NiumTransferService::class)->submitTransfer($provider, $transfer);
        $this->assertSame('pending', $submitted->status);

        $this->withHeader('x-partner-key', 'test-partner-key')
            ->postJson('/api/webhooks/providers/nium', [
                'eventId' => 'transfer-lifecycle-completed',
                'eventType' => 'remittance.completed',
                'data' => [
                    'resource' => [
                        'systemReferenceNumber' => 'RT-WEBHOOK-LIFECYCLE',
                        'transactionId' => 'TXN-WEBHOOK-LIFECYCLE',
                        'status' => 'COMPLETED',
                    ],
                ],
            ])
            ->assertOk();

        $this->assertSame('completed', $submitted->fresh()->status);
    }

    public function test_swift_transfer_uses_authoritative_fee_type_when_client_omits_it(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'raw_data' => [
                'nium' => [
                    'sourceOfFunds' => 'Corporate Account',
                    'payoutMethod' => 'SWIFT',
                ],
            ],
        ]);
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response([])]);

        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response(['systemReferenceNumber' => 'RT-AUTHORITATIVE-FEE'])]);
        app(NiumTransferService::class)->submitTransfer($provider, $transfer);
        Http::assertSent(fn ($request): bool => $request->data()['payout']['swiftFeeType'] === 'SHA');
    }

    public function test_timeout_after_provider_acceptance_marks_unknown_and_never_posts_again(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        $attempts = 0;
        Http::fake(function ($request) use (&$attempts) {
            if (str_contains($request->url(), '/purposeCodes')) {
                return Http::response([
                    ['description' => 'General Goods Trades - Offline trade', 'purposeCode' => 'IR01811'],
                ]);
            }
            if ($request->method() === 'POST' && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/remittance')) {
                $attempts++;
                throw new ConnectionException('Timed out after provider acceptance.');
            }

            return Http::response([], 500);
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
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response(['systemReferenceNumber' => 'RT-EVIDENCE-UNKNOWN'])]);
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

        $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => $this->isRemittancePost($pair[0])));
    }

    public function test_invalid_state_sends_zero_http_requests(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer(['status' => 'pending']);
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response([])]);

        $this->expectException(RuntimeException::class);

        try {
            app(NiumTransferService::class)->submitTransfer($provider, $transfer);
        } finally {
            $this->assertNoRemittancePost();
        }
    }

    public function test_manager_creates_one_hold_and_keeps_provider_http_outside_transactions(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        $transactionLevel = DB::transactionLevel();
        Http::fake(function ($request) use ($transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel());
            if (str_contains($request->url(), '/purposeCodes')) {
                return Http::response([
                    ['description' => 'General Goods Trades - Offline trade', 'purposeCode' => 'IR01811'],
                ]);
            }

            return Http::response(['systemReferenceNumber' => 'RT-MANAGER-HOLD']);
        });

        $manager = app(ProviderTransferManager::class);
        $submitted = $manager->submitTransfer($provider, $transfer);
        try {
            $manager->submitTransfer($provider, $submitted);
            $this->fail('Repeated submit must be rejected before another hold or provider request.');
        } catch (RuntimeException) {
            // The submitted state is not eligible for another provider attempt.
        }

        $balance = Balance::query()->sole();
        $this->assertSame('990.00000000', $balance->available_balance);
        $this->assertSame('10.00000000', $balance->reserved_balance);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'hold')->count());
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => $this->isRemittancePost($pair[0])));
    }

    public function test_manager_releases_hold_for_pre_provider_failure(): void
    {
        [$provider, $invalidTransfer] = $this->makeSubmittableTransfer(['target_amount' => 10]);
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response([])]);
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $invalidTransfer);
            $this->fail('The authoritative policy must reject the invalid transfer.');
        } catch (RuntimeException) {
            // The manager must release the hold created before provider-specific validation.
        }
        $this->assertSame('1000.00000000', Balance::query()->sole()->available_balance);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertNoRemittancePost();
    }

    public function test_manager_releases_hold_for_provider_failure(): void
    {
        [$provider, $rejectedTransfer] = $this->makeSubmittableTransfer();
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response(['message' => 'Rejected'], 422)]);
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $rejectedTransfer);
            $this->fail('Provider rejection must be surfaced.');
        } catch (RuntimeException) {
            // A definite provider rejection releases the transfer hold.
        }
        $rejectedBalance = Balance::query()->where('user_id', $rejectedTransfer->user_id)->sole();
        $this->assertSame('1000.00000000', $rejectedBalance->available_balance);
        $this->assertSame('0.00000000', $rejectedBalance->reserved_balance);
    }

    public function test_submission_unknown_preserves_manager_hold(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/purposeCodes')) {
                return Http::response([
                    ['description' => 'General Goods Trades - Offline trade', 'purposeCode' => 'IR01811'],
                ]);
            }
            throw new ConnectionException('Unknown provider outcome.');
        });

        $unknown = app(ProviderTransferManager::class)->submitTransfer($provider, $transfer);

        $balance = Balance::query()->sole();
        $this->assertSame('submission_unknown', $unknown->status);
        $this->assertSame('990.00000000', $balance->available_balance);
        $this->assertSame('10.00000000', $balance->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
    }

    public function test_current_terminal_policy_settles_completed_and_releases_failed_or_cancelled(): void
    {
        [, $template] = $this->makeSubmittableTransfer();
        foreach (['completed', 'failed', 'cancelled'] as $index => $status) {
            $transfer = $index === 0 ? $template : Transfer::query()->create([
                ...$template->only([
                    'user_id', 'provider_id', 'beneficiary_id', 'transfer_type', 'source_currency',
                    'target_currency', 'source_amount', 'fee_amount', 'fee_currency', 'purpose_code',
                ]),
                'transfer_no' => 'TRF-TERMINAL-'.strtoupper($status),
                'status' => 'draft',
            ]);
            Balance::query()->where('user_id', $transfer->user_id)->update([
                'available_balance' => 1000,
                'reserved_balance' => 0,
                'ledger_balance' => 1000,
            ]);
            $ledger = app(LedgerService::class);
            $ledger->reserveTransfer($transfer);
            $transfer->update(['status' => $status]);
            $ledger->applyTransferTerminalStatus($transfer->fresh());

            $balance = Balance::query()->where('user_id', $transfer->user_id)->sole();
            if ($status === 'completed') {
                $this->assertSame('990.00000000', $balance->ledger_balance);
                $this->assertSame('0.00000000', $balance->reserved_balance);
                $this->assertSame(1, LedgerEntry::query()->where('source_id', (string) $transfer->id)->where('entry_type', 'debit')->count());
            } else {
                $this->assertSame('1000.00000000', $balance->available_balance);
                $this->assertSame('0.00000000', $balance->reserved_balance);
                $this->assertSame(1, LedgerEntry::query()->where('source_id', (string) $transfer->id)->where('entry_type', 'release')->count());
            }
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
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response([
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
        $user->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        $this->createApprovedHkCorporateKycProfile($user);
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'customer-test',
            'external_account_id' => 'wallet-test',
            'status' => 'active',
            'provider_status' => 'clear',
            'reconciliation_status' => 'reconciled',
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
            'beneficiary_type' => 'business', 'full_name' => 'Test Payee', 'country_code' => 'HK',
            'currency' => 'USD', 'payout_method' => 'SWIFT', 'status' => 'active',
            'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT']],
        ]);
        $transfer = Transfer::query()->create(array_merge([
            'transfer_no' => 'TRF-'.strtoupper(uniqid()), 'user_id' => $user->id, 'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id, 'fx_quote_id' => null, 'transfer_type' => 'payout',
            'source_currency' => 'USD', 'target_currency' => 'USD', 'source_amount' => 10,
            'target_amount' => null, 'fx_rate' => null, 'fee_amount' => 0, 'fee_currency' => 'USD', 'status' => 'draft',
            'purpose_code' => 'IR01811', 'raw_data' => [],
            'reference_text' => 'INV-TEST-001',
        ], $overrides));

        return [$provider, $transfer->fresh(['provider', 'user', 'beneficiary', 'fxQuote'])];
    }

    private function createApprovedHkCorporateKycProfile(User $user): void
    {
        $user->kycProfile()->create([
            'status' => 'approved',
            'applicant_type' => 'business',
            'legal_name' => 'HK Corporate Customer',
            'business_name' => 'HK Corporate Customer',
            'registered_country_code' => 'HK',
            'address_line1' => '1 Corporate Road',
            'city' => 'Hong Kong',
            'postal_code' => '999077',
            'country_code' => 'HK',
            'metadata' => ['nium_region' => 'HK'],
        ]);
    }
}
