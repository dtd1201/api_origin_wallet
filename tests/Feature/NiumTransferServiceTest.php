<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumPayoutFxLockContract;
use App\Services\Nium\NiumPayoutFxLockService;
use App\Services\Nium\NiumPurposeCodeService;
use App\Services\Nium\NiumRemittanceDispatchHeldException;
use App\Services\Nium\NiumSgdAdmissionFence;
use App\Services\Nium\NiumTransferPolicy;
use App\Services\Nium\NiumTransferService;
use App\Services\Wallet\LedgerService;
use App\Services\Wallet\TransferApprovalService;
use Illuminate\Database\Schema\Blueprint;
use Tests\Support\OwnerFirstPostgresDatabase as RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('wallet.ledger.enabled', true);

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
            'external_account_id' => 'wallet_hash_123',
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

        app(LedgerService::class)->reserveTransfer($transfer);

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

    public static function provenHkSourceAmounts(): array
    {
        return ['USD 5' => ['5.00', '5.00000000', 5], 'USD 10' => ['10.00', '10.00000000', 10]];
    }

    #[DataProvider('provenHkSourceAmounts')]
    public function test_hk_swift_approval_preserves_exact_remittance_body_and_one_dispatch(
        string $amount, string $reserved, int $wireAmount,
    ): void {
        Http::preventStrayRequests();
        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'source_amount' => $amount, 'status' => 'approval_required', 'reference_text' => 'HK-CONTRACT',
        ]);
        config()->set('wallet.transfer_controls.require_admin_approval', true);
        config()->set('wallet.transfer_controls.approval_threshold_amount', 0);
        config()->set('wallet.ledger.enabled', true);
        config()->set('services.nium.payout_fx_enabled', true);
        config()->set('services.nium.transfer_endpoint', '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance');
        // Enabling cross-currency support must not add pricing or FX to HK/USD SWIFT.
        $this->mock(\App\Services\Nium\NiumSgdLocalPricing::class)->shouldNotReceive('forTransfer');
        // Resolve the real final FX service; Http::fake guards its I/O boundary.
        $approver = User::factory()->create(['status' => 'active']);
        $approver->roles()->create(['role_code' => 'admin']);
        $endpoint = 'https://gateway.sandbox.nium.test/api/v1/client/client_hash_test/customer/customer-test/wallet/wallet-test/remittance';
        Http::fake([...$this->purposeCodesRoute(), $endpoint => Http::response(['systemReferenceNumber' => 'HK-CONTRACT-RESULT'])]);

        // Fixed application contract fixture, not a claimed capture of Production transactions.
        $expectedBody = '{"beneficiary":{"id":"beneficiary-test"},"payout":{"sourceAmount":'.$wireAmount.',"sourceCurrency":"USD","destinationCurrency":"USD","payoutMethod":"SWIFT","tradeOrderID":"HK-CONTRACT","swiftFeeType":"SHA"},"purposeCode":"IR01811","sourceOfFunds":"Corporate Account","customerComments":"HK-CONTRACT"}';
        $approved = app(TransferApprovalService::class)->approve($transfer, $approver, null, app(ProviderTransferManager::class));
        $this->assertSame('pending', $approved->status);
        $requests = collect(Http::recorded())->filter(fn ($pair) => $this->isRemittancePost($pair[0]));
        $this->assertCount(1, $requests);
        $post = $requests->sole()[0];
        $this->assertSame($endpoint, $post->url());
        $this->assertTrue($post->hasHeader('x-api-key', 'test-key'));
        $this->assertSame($expectedBody, $post->body());
        $this->assertSame($reserved, Balance::query()->where('user_id', $transfer->user_id)->sole()->reserved_balance);
        $this->assertDatabaseCount('fx_quotes', 0);
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->assertSame(1, LedgerEntry::query()->where('reference', "transfer:{$transfer->id}:hold")->count());

        app(TransferApprovalService::class)->approve($approved, $approver, null, app(ProviderTransferManager::class));
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => $this->isRemittancePost($pair[0])));
        $this->assertDatabaseCount('transfer_approvals', 1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/lockExchangeRate')
            || str_contains($request->url(), '/quotes'));
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


    public function test_manager_sync_does_not_commit_terminal_status_when_ledger_application_fails(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'status' => 'pending',
            'external_transfer_id' => 'RT-MANUAL-ATOMIC',
        ]);

        Http::fake([
            '*' => Http::response([
                [
                    'status' => 'COMPLETED',
                    'paymentReferenceNumber' => 'PAY-MANUAL-ATOMIC',
                    'lastUpdatedAt' => now()->toISOString(),
                ],
            ], 200),
        ]);

        try {
            app(ProviderTransferManager::class)->syncTransferStatus(
                $provider,
                $transfer->fresh(['provider', 'user', 'beneficiary', 'sourceBankAccount'])
            );

            $this->fail('Ledger settlement failure must abort the terminal sync.');
        } catch (RuntimeException) {
            // The fixture has no reserved funds, so terminal settlement must fail.
        }

        $this->assertSame(
            'pending',
            $transfer->fresh()->status,
            'Transfer status must not commit when manual-sync ledger settlement fails.'
        );
    }


    public function test_manager_sync_rejects_conflicting_terminal_status_without_changing_ledger(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();

        $ledger = app(LedgerService::class);
        $ledger->reserveTransfer($transfer);

        $transfer->update([
            'external_transfer_id' => 'RT-TERMINAL-CONFLICT',
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $ledger->applyTransferTerminalStatus($transfer->fresh());

        Http::fake([
            '*' => Http::response([
                [
                    'status' => 'FAILED',
                    'remarks' => 'Late conflicting provider status.',
                    'lastUpdatedAt' => now()->addMinute()->toISOString(),
                ],
            ], 200),
        ]);

        try {
            app(ProviderTransferManager::class)->syncTransferStatus(
                $provider,
                $transfer->fresh(['provider', 'user', 'beneficiary', 'sourceBankAccount'])
            );

            $this->fail('A conflicting terminal status must require reconciliation.');
        } catch (RuntimeException) {
            // Expected: completed funds must not be reclassified as failed automatically.
        }

        $fresh = $transfer->fresh();
        $balance = Balance::query()->where('user_id', $transfer->user_id)->sole();

        $this->assertSame('completed', $fresh->status);
        $this->assertSame('990.00000000', $balance->available_balance);
        $this->assertSame('990.00000000', $balance->ledger_balance);
        $this->assertSame('0.00000000', $balance->reserved_balance);

        $this->assertSame(
            1,
            LedgerEntry::query()
                ->where('source_id', (string) $transfer->id)
                ->where('entry_type', 'debit')
                ->count()
        );

        $this->assertSame(
            0,
            LedgerEntry::query()
                ->where('source_id', (string) $transfer->id)
                ->where('entry_type', 'release')
                ->count()
        );
    }

    public function test_same_transfer_submitted_twice_sends_one_provider_post(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response(['systemReferenceNumber' => 'RT-ONCE', 'paymentId' => 'PAY-ONCE'])]);

        app(LedgerService::class)->reserveTransfer($transfer);

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
        Balance::query()->where('user_id', $transfer->user_id)->update(['external_account_id' => 'provider-account-wallet']);
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response(['systemReferenceNumber' => 'RT-PROVIDER-ACCOUNT'])]);

        app(LedgerService::class)->reserveTransfer($transfer);

        app(NiumTransferService::class)->submitTransfer($provider, $transfer);

        Http::assertSent(fn ($request): bool => str_contains(
            $request->url(),
            '/customer/provider-account-customer/wallet/provider-account-wallet/remittance',
        ));
    }

    public function test_same_currency_transfer_does_not_require_fx_lock(): void
    {
        config()->set('services.nium.payout_fx_enabled', true);

        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'target_currency' => 'USD',
            'fx_quote_id' => null,
            'fx_rate' => null,
        ]);
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response(['systemReferenceNumber' => 'RT-SAME-CURRENCY'])]);

        app(LedgerService::class)->reserveTransfer($transfer);

        $updated = app(NiumTransferService::class)->submitTransfer(
            $provider,
            $transfer->fresh(['provider', 'user', 'beneficiary'])
        );

        $this->assertSame('pending', $updated->status);
        Http::assertSent(fn ($request): bool => $this->isRemittancePost($request)
            && ! array_key_exists('auditId', $request->data()['payout'] ?? []));

        Http::assertNotSent(fn ($request): bool =>
            str_contains($request->url(), '/lockExchangeRate')
        );
    }

    public function test_same_currency_transfer_without_fx_lock_uses_live_transfer_money_contract(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'fx_quote_id' => null,
            'fx_rate' => null,
        ]);
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response(['systemReferenceNumber' => 'RT-LIVE-FX'])]);

        app(LedgerService::class)->reserveTransfer($transfer);

        $updated = app(NiumTransferService::class)->submitTransfer(
            $provider,
            $transfer->fresh(['provider', 'user', 'beneficiary'])
        );

        $this->assertSame('pending', $updated->status);
        Http::assertSent(fn ($request): bool => $this->isRemittancePost($request)
            && ! array_key_exists('auditId', $request->data()['payout'] ?? []));
    }

    public function test_legacy_fx_lock_binding_survives_database_round_trip(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        config()->set('services.nium.payout_fx_enabled', true);
        config()->set('services.nium.payout_fx_lock_endpoint',
            '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/lockExchangeRate');
        [$provider, $transfer] = $this->makeSubmittableTransfer(['target_currency' => 'EUR']);
        $transfer->beneficiary->update(['currency' => 'EUR']);
        $url = 'https://gateway.sandbox.nium.test/api/v1/client/client_hash_test/customer/customer-test/wallet/wallet-test/lockExchangeRate';
        $response = [
            'audit_id' => '778', 'fx_rate' => '0.915', 'status' => 'ACTIVE',
            'hold_expiry_at' => now()->addMinutes(10)->toISOString(),
            'sourceCurrency' => 'USD', 'destinationCurrency' => 'EUR', 'sourceAmount' => '10',
        ];
        Http::fake([$url.'*' => Http::response($response)]);

        $locks = app(NiumPayoutFxLockService::class);
        $context = $locks->context($provider, $transfer->user);
        $this->assertSame([
            'client' => 'client_hash_test', 'customer' => 'customer-test',
            'wallet' => 'wallet-test', 'base_url' => 'https://gateway.sandbox.nium.test',
        ], $context);
        $quote = $locks->createLock($provider, $transfer->user, 'USD', 'EUR', '10');
        $transfer->update(['fx_quote_id' => $quote->id, 'fx_rate' => $quote->net_rate]);
        NiumPayoutFxLockContract::assertMatchesTransfer($quote, $transfer, $context);

        $stored = $quote->fresh();
        $storedContext = $stored->raw_data['provider_context'];
        $this->assertCount(count($context), $storedContext);
        foreach ($context as $key => $value) {
            $this->assertArrayHasKey($key, $storedContext);
            $this->assertSame($value, $storedContext[$key]);
        }
        $expected = NiumPayoutFxLockContract::parse($response, 'USD', 'EUR', '10');
        $storedContract = $stored->raw_data['payout_fx_lock_contract'];
        $this->assertCount(count($expected), $storedContract);
        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $storedContract);
            $this->assertSame($value, $storedContract[$key]);
        }
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), $url.'?')
            && $request['sourceCurrency'] === 'USD' && $request['destinationCurrency'] === 'EUR');
        $this->assertNoRemittancePost();
        $this->assertSame('draft', $transfer->fresh()->status);
        $this->assertNull($transfer->fresh()->provider_operation_key);

        // Validate the real stored JSONB context without normalizing/mutating
        // the quote or replacing the production validator.
        NiumPayoutFxLockContract::assertMatchesTransfer($stored, $transfer->fresh(), $context);
    }

    public function test_legacy_cross_currency_transfer_acquires_fresh_fx_lock_but_remittance_remains_on_hold(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        config()->set('services.nium.payout_fx_enabled', true);
        config()->set(
            'services.nium.payout_fx_lock_endpoint',
            '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/lockExchangeRate',
        );

        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'target_currency' => 'EUR',
            'fx_quote_id' => null,
            'fx_rate' => null,
        ]);

        $transfer->beneficiary->update([
            'currency' => 'EUR',
        ]);

        $createdAt = $transfer->created_at->toDateTimeString();

        Http::fake([
            ...$this->purposeCodesRoute(),

            'https://gateway.sandbox.nium.test/api/v1/client/client_hash_test/customer/customer-test/wallet/wallet-test/lockExchangeRate*'
                => Http::response([
                    'audit_id' => 778,
                    'fx_hold_id' => 'hold-auto-778',
                    'fx_rate' => '0.915',
                    'hold_expiry_at' => now()->addMinutes(10)->toISOString(),
                    'status' => 'ACTIVE',
                ], 200),
        ]);

        // Legacy HK/EUR lock acquisition is supported; its remittance wire is held.
        app(LedgerService::class)->reserveTransfer($transfer);
        try {
            app(NiumTransferService::class)->submitTransfer($provider, $transfer->fresh());
            $this->fail('A legacy cross-currency payload must not bypass the dispatch HOLD.');
        } catch (NiumRemittanceDispatchHeldException $exception) {
            $this->assertSame(NiumSgdAdmissionFence::DISPATCH_HOLD, $exception->getMessage());
        }

        $persisted = Transfer::query()
            ->with('fxQuote')
            ->findOrFail($transfer->id);

        $this->assertRemittanceDispatchHeldState($persisted);
        $this->assertSame($createdAt, $persisted->created_at->toDateTimeString());
        $this->assertNotNull($persisted->fx_quote_id);
        $this->assertSame('0.9150000000', $persisted->fx_rate);
        $this->assertSame('778', $persisted->fxQuote->quote_ref);
        $this->assertSame('hold-auto-778', $persisted->fxQuote->raw_data['fx_hold_id']);
        $this->assertSame(
            'payout_fx_lock',
            $persisted->fxQuote->raw_data['provider_fx_type'],
        );

        Http::assertSent(fn ($request): bool =>
            $request->method() === 'GET'
            && str_contains($request->url(), '/lockExchangeRate')
        );

        $payload = app(NiumTransferPolicy::class)->providerPayload(
            $persisted, app(NiumPurposeCodeService::class)->supported($persisted->user),
        );
        $this->assertSame('USD', $payload['payout']['sourceCurrency']);
        $this->assertSame('EUR', $payload['payout']['destinationCurrency']);
        $this->assertSame(778, $payload['payout']['auditId']);
        $this->assertDatabaseCount('fx_quotes', 1);
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/lockExchangeRate')));
        $this->assertNoRemittancePost();
    }

    public function test_legacy_cross_currency_lock_serializes_audit_id_but_remittance_remains_on_hold(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        config()->set('services.nium.payout_fx_enabled', true);

        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'target_currency' => 'EUR',
            'fx_rate' => '0.9150000000',
        ]);

        $transfer->beneficiary->update([
            'currency' => 'EUR',
        ]);

        $quote = \App\Models\FxQuote::query()->create([
            'user_id' => $transfer->user_id,
            'provider_id' => $provider->id,
            'quote_ref' => '112',
            'source_currency' => 'USD',
            'target_currency' => 'EUR',
            'source_amount' => '10.00000000',
            'target_amount' => '9.15000000',
            'mid_rate' => '0.9200000000',
            'net_rate' => '0.9150000000',
            'fee_amount' => '0.00000000',
            'expires_at' => now()->addMinutes(10),
            'raw_data' => [
                'provider_fx_type' => 'payout_fx_lock',
                'audit_id' => '112',
            ],
        ]);

        $transfer->update([
            'fx_quote_id' => $quote->id,
        ]);

        $quoteEvidence = $quote->fresh()->getRawOriginal();

        Http::fake([
            ...$this->purposeCodesRoute(),
        ]);

        $payload = app(NiumTransferPolicy::class)->providerPayload(
            $transfer->fresh(), app(NiumPurposeCodeService::class)->supported($transfer->user),
        );
        $this->assertSame('USD', $payload['payout']['sourceCurrency']);
        $this->assertSame('EUR', $payload['payout']['destinationCurrency']);
        $this->assertSame(112, $payload['payout']['auditId']);

        app(LedgerService::class)->reserveTransfer($transfer);
        try {
            app(NiumTransferService::class)->submitTransfer($provider, $transfer->fresh());
            $this->fail('An authoritative audit ID does not authorize cross-currency dispatch.');
        } catch (NiumRemittanceDispatchHeldException $exception) {
            $this->assertSame(NiumSgdAdmissionFence::DISPATCH_HOLD, $exception->getMessage());
        }
        $this->assertRemittanceDispatchHeldState($transfer->fresh());
        $this->assertSame($quote->id, $transfer->fresh()->fx_quote_id);
        $this->assertSame($quoteEvidence, $quote->fresh()->getRawOriginal());
        $this->assertDatabaseCount('fx_quotes', 1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/lockExchangeRate'));
        $this->assertNoRemittancePost();
    }

    public function test_manager_releases_reservation_once_for_deterministic_dispatch_hold(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        config()->set('services.nium.payout_fx_enabled', true);
        config()->set('wallet.ledger.enabled', true);
        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'target_currency' => 'EUR', 'fx_rate' => '0.9150000000',
        ]);
        $transfer->beneficiary->update(['currency' => 'EUR']);
        $quote = FxQuote::query()->create([
            'user_id' => $transfer->user_id, 'provider_id' => $provider->id,
            'quote_ref' => '112', 'source_currency' => 'USD', 'target_currency' => 'EUR',
            'source_amount' => '10.00000000', 'target_amount' => '9.15000000',
            'net_rate' => '0.9150000000', 'fee_amount' => 0, 'expires_at' => now()->addMinutes(10),
            'raw_data' => ['provider_fx_type' => 'payout_fx_lock', 'audit_id' => '112', 'fx_hold_id' => 'hold-held-112'],
        ]);
        $transfer->update(['fx_quote_id' => $quote->id]);
        $quoteEvidence = $quote->fresh()->getRawOriginal();
        $createdAt = $transfer->created_at->toDateTimeString();

        config()->set('wallet.transfer_controls.require_admin_approval', true);
        config()->set('wallet.transfer_controls.approval_threshold_amount', 0);
        $approver = User::factory()->create(['status' => 'active']);
        $approver->roles()->create(['role_code' => 'admin']);
        $approvals = app(TransferApprovalService::class);
        // Approval of a draft records evidence without auto-submitting it.
        $approved = $approvals->approve($transfer->fresh(), $approver);
        $approval = $approved->approvals()->sole();
        $approvalEvidence = $approval->getRawOriginal();
        Http::fake($this->purposeCodesRoute());
        $manager = app(ProviderTransferManager::class);

        try {
            $manager->submitTransfer($provider, $approved);
            $this->fail('The dispatch HOLD must reach the manager for reservation cleanup.');
        } catch (NiumRemittanceDispatchHeldException $exception) {
            $this->assertSame(NiumSgdAdmissionFence::DISPATCH_HOLD, $exception->getMessage());
        }

        $failed = $transfer->fresh();
        $this->assertRemittanceDispatchHeldState($failed);
        $operationKey = $failed->provider_operation_key;
        $walletReceipt = $failed->raw_data['wallet'];
        $calls = count(Http::recorded());
        $this->assertSame($createdAt, $failed->created_at->toDateTimeString());
        $this->assertSame($quote->id, $failed->fx_quote_id);
        $this->assertSame('0.9150000000', $failed->fx_rate);
        $this->assertSame($quoteEvidence, $quote->fresh()->getRawOriginal());
        $this->assertSame($approvalEvidence, $approval->fresh()->getRawOriginal());
        $this->assertSame('1000.00000000', Balance::query()->sole()->available_balance);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame('1000.00000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame(1, LedgerEntry::query()->where('reference', "transfer:{$transfer->id}:hold")->count());
        $this->assertSame(1, LedgerEntry::query()->where('reference', "transfer:{$transfer->id}:release")->count());
        $this->assertDatabaseCount('ledger_entries', 2);

        $again = $approvals->approve($failed, $approver, null, $manager);
        $this->assertSame('failed', $again->status);
        try {
            $manager->submitTransfer($provider, $again);
            $this->fail('A deterministically failed transfer must not retry dispatch.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Only draft, approval required, or approved transfers can be submitted before provider submission.',
                $exception->getMessage());
        }

        $this->assertSame($calls, count(Http::recorded()));
        $this->assertSame($operationKey, $transfer->fresh()->provider_operation_key);
        $this->assertSame($walletReceipt, $transfer->fresh()->raw_data['wallet']);
        $this->assertSame($approvalEvidence, $approval->fresh()->getRawOriginal());
        $this->assertSame($quoteEvidence, $quote->fresh()->getRawOriginal());
        $this->assertDatabaseCount('transfer_approvals', 1);
        $this->assertDatabaseCount('fx_quotes', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertSame('1000.00000000', Balance::query()->sole()->available_balance);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/lockExchangeRate'));
        $this->assertNoRemittancePost();
    }

    public static function reservationCleanupOutcomes(): array
    {
        return [
            'cleanup fails' => [true, false],
            'cleanup and logging fail' => [true, true],
            'cleanup succeeds' => [false, false],
        ];
    }

    #[DataProvider('reservationCleanupOutcomes')]
    public function test_reservation_cleanup_observability_preserves_primary_dispatch_hold(
        bool $cleanupFails, bool $loggingFails,
    ): void {
        $this->freezeTime();
        Http::preventStrayRequests();
        config()->set('services.nium.payout_fx_enabled', true);
        config()->set('wallet.ledger.enabled', true);
        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'target_currency' => 'EUR', 'fx_rate' => '0.9150000000',
        ]);
        $transfer->beneficiary->update(['currency' => 'EUR']);
        $quote = FxQuote::query()->create([
            'user_id' => $transfer->user_id, 'provider_id' => $provider->id,
            'quote_ref' => '112', 'source_currency' => 'USD', 'target_currency' => 'EUR',
            'source_amount' => '10.00000000', 'target_amount' => '9.15000000',
            'net_rate' => '0.9150000000', 'fee_amount' => 0, 'expires_at' => now()->addMinutes(10),
            'raw_data' => ['provider_fx_type' => 'payout_fx_lock', 'audit_id' => '112'],
        ]);
        $transfer->update(['fx_quote_id' => $quote->id]);
        Http::fake($this->purposeCodesRoute());

        $releaseAttempts = 0;
        LedgerEntry::creating(static function (LedgerEntry $entry) use (&$releaseAttempts, $cleanupFails): void {
            if ($entry->entry_type === 'release') {
                $releaseAttempts++;
                if ($cleanupFails) {
                    // Failure after the balance update exercises the real release rollback.
                    throw new RuntimeException('SENSITIVE-CLEANUP-MESSAGE');
                }
            }
        });

        // Delegate to the real service, capturing the exception before manager cleanup.
        $service = app(NiumTransferService::class);
        $primary = null;
        $primaryTrace = null;
        $this->mock(NiumTransferService::class)->shouldReceive('submitTransfer')->once()
            ->andReturnUsing(function (IntegrationProvider $submissionProvider, Transfer $candidate) use ($service, &$primary, &$primaryTrace): Transfer {
                try {
                    return $service->submitTransfer($submissionProvider, $candidate);
                } catch (NiumRemittanceDispatchHeldException $exception) {
                    $primary = $exception;
                    $primaryTrace = $exception->getTraceAsString();
                    throw $exception;
                }
            });

        if ($cleanupFails) {
            $errorLog = Log::shouldReceive('error')->once()->with('Transfer reservation cleanup failed.', [
                'transfer_id' => $transfer->id,
                'phase' => 'reservation_cleanup',
                'cleanup_exception_class' => RuntimeException::class,
                'primary_exception_class' => NiumRemittanceDispatchHeldException::class,
                'failure_code' => 'remittance_dispatch_held',
            ]);
            if ($loggingFails) {
                $errorLog->andThrow(new RuntimeException('SENSITIVE-LOGGER-MESSAGE'));
            }
        } else {
            Log::shouldReceive('error')->never();
        }

        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $transfer->fresh());
            $this->fail('The primary dispatch HOLD must still be rethrown.');
        } catch (NiumRemittanceDispatchHeldException $exception) {
            $this->assertSame($primary, $exception);
            $this->assertSame($primaryTrace, $exception->getTraceAsString());
            $this->assertSame(NiumSgdAdmissionFence::DISPATCH_HOLD, $exception->getMessage());
        }

        $failed = $transfer->fresh();
        $this->assertRemittanceDispatchHeldState($failed);
        $this->assertSame($quote->id, $failed->fx_quote_id);
        $this->assertSame('112', $quote->fresh()->raw_data['audit_id']);
        $this->assertSame(1, $releaseAttempts);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'hold')->count());
        $this->assertSame($cleanupFails ? 0 : 1, LedgerEntry::query()->where('entry_type', 'release')->count());
        $this->assertDatabaseCount('ledger_entries', $cleanupFails ? 1 : 2);
        $balance = Balance::query()->sole();
        $this->assertSame($cleanupFails ? '990.00000000' : '1000.00000000', $balance->available_balance);
        $this->assertSame($cleanupFails ? '10.00000000' : '0.00000000', $balance->reserved_balance);
        $this->assertSame('1000.00000000', $balance->ledger_balance);
        if ($cleanupFails) {
            $this->assertArrayNotHasKey('release_reference', $failed->raw_data['wallet']);
        } else {
            $this->assertSame("transfer:{$transfer->id}:release", $failed->raw_data['wallet']['release_reference']);
        }
        $this->assertNoRemittancePost();
    }

    public static function referenceLessTransferStates(): array
    {
        return ['failed' => ['failed'], 'submitting' => ['submitting'],
            'submission_unknown' => ['submission_unknown'], 'pending' => ['pending']];
    }

    #[DataProvider('referenceLessTransferStates')]
    public function test_reference_less_transfer_cannot_sync_or_reconcile(string $status): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer(['status' => $status]);
        config()->set('services.nium.transfer_reconciliation_enabled', true);
        Http::preventStrayRequests();
        Http::fake();

        try {
            app(ProviderTransferManager::class)->syncTransferStatus($provider, $transfer);
            $this->fail('A provider reference is required before status synchronization.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Nium transfer is missing the system reference number.', $exception->getMessage());
        }
        $this->artisan('nium:reconcile-transfer', ['transfer' => $transfer->id])
            ->expectsOutput('No authoritative Nium reference is available; human review is required and the POST must not be retried.')
            ->assertExitCode(1);
        $this->artisan('nium:reconcile-transfers')->assertExitCode(0);

        $this->assertSame($status, $transfer->fresh()->status);
        $this->assertNull($transfer->fresh()->external_transfer_id);
        $this->assertNull($transfer->fresh()->external_payment_id);
        $this->assertNull($transfer->fresh()->submitted_at);
        $this->assertDatabaseCount('ledger_entries', 0);
        Http::assertNothingSent();
    }

    public function test_expired_payout_lock_prevents_remittance_without_replacing_the_quote(): void
    {
        config()->set('services.nium.payout_fx_enabled', true);
        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'target_currency' => 'EUR',
            'fx_rate' => '0.9150000000',
        ]);
        $transfer->beneficiary->update(['currency' => 'EUR']);
        $quote = \App\Models\FxQuote::query()->create([
            'user_id' => $transfer->user_id,
            'provider_id' => $provider->id,
            'quote_ref' => '112',
            'source_currency' => 'USD',
            'target_currency' => 'EUR',
            'source_amount' => '10.00000000',
            'target_amount' => '9.15000000',
            'net_rate' => '0.9150000000',
            'fee_amount' => 0,
            'expires_at' => now()->subSecond(),
            'raw_data' => ['provider_fx_type' => 'payout_fx_lock', 'audit_id' => '112'],
        ]);
        $transfer->update(['fx_quote_id' => $quote->id]);
        Http::preventStrayRequests();
        Http::fake($this->purposeCodesRoute());

        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $transfer->fresh());
            $this->fail('An expired lock must never reach remittance.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Nium exchange-rate lock has expired.', $exception->getMessage());
        }

        $this->assertNoRemittancePost();
        Http::assertSentCount(1); // Purpose codes only; no replacement FX lock.
        $this->assertSame($quote->id, $transfer->fresh()->fx_quote_id);
        $this->assertSame('draft', $transfer->fresh()->status);
        $this->assertSame('1000.00000000', Balance::query()->sole()->available_balance);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
    }

    public function test_insufficient_balance_prevents_provider_requests_and_wallet_holds(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer(['source_amount' => '1000.00000001']);
        Http::preventStrayRequests();
        Http::fake();

        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $transfer);
            $this->fail('An insufficient balance must prevent submission.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Insufficient available balance for this transfer.', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame('draft', $transfer->fresh()->status);
        $this->assertSame('1000.00000000', Balance::query()->sole()->available_balance);
    }

    public function test_missing_admin_approval_prevents_provider_requests_and_wallet_holds(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer(['status' => 'approval_required']);
        config()->set('wallet.transfer_controls.require_admin_approval', true);
        config()->set('wallet.transfer_controls.approval_threshold_amount', 0);
        Http::preventStrayRequests();
        Http::fake();

        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $transfer);
            $this->fail('Missing approval must prevent submission.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Admin approval is required before submitting this transfer.', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame('approval_required', $transfer->fresh()->status);
        $this->assertSame('1000.00000000', Balance::query()->sole()->available_balance);
    }

    public function test_cross_currency_transfer_with_fx_enabled_but_without_lock_is_rejected(): void
    {
        config()->set('services.nium.payout_fx_enabled', true);

        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'target_currency' => 'EUR',
            'fx_quote_id' => null,
            'fx_rate' => null,
        ]);

        $transfer->beneficiary->update([
            'currency' => 'EUR',
        ]);

        Http::fake([
            ...$this->purposeCodesRoute(),
            '*' => Http::response([]),
        ]);

        $this->expectException(RuntimeException::class);

        try {
            app(NiumTransferService::class)->submitTransfer(
                $provider,
                $transfer->fresh([
                    'provider',
                    'user',
                    'beneficiary',
                    'fxQuote',
                ]),
            );
        } finally {
            $this->assertNoRemittancePost();
        }
    }

    public function test_modern_executable_quote_cannot_be_used_as_payout_audit_id(): void
    {
        config()->set('services.nium.payout_fx_enabled', true);

        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'target_currency' => 'EUR',
            'fx_rate' => '0.9150000000',
        ]);

        $transfer->beneficiary->update([
            'currency' => 'EUR',
        ]);

        $quote = \App\Models\FxQuote::query()->create([
            'user_id' => $transfer->user_id,
            'provider_id' => $provider->id,
            'quote_ref' => 'quote_exec_123',
            'source_currency' => 'USD',
            'target_currency' => 'EUR',
            'source_amount' => '10.00000000',
            'target_amount' => '9.15000000',
            'mid_rate' => '0.9200000000',
            'net_rate' => '0.9150000000',
            'fee_amount' => '0.00000000',
            'expires_at' => now()->addMinutes(10),
            'raw_data' => [
                'provider_fx_type' => 'modern_quote',
                'provider_quote_id' => 'quote_exec_123',
            ],
        ]);

        $transfer->update([
            'fx_quote_id' => $quote->id,
        ]);

        Http::fake([
            ...$this->purposeCodesRoute(),
            '*' => Http::response([]),
        ]);

        $this->expectException(RuntimeException::class);

        try {
            app(NiumTransferService::class)->submitTransfer(
                $provider,
                $transfer->fresh([
                    'provider',
                    'user',
                    'beneficiary',
                    'fxQuote',
                ]),
            );
        } finally {
            $this->assertNoRemittancePost();
        }
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

        app(LedgerService::class)->reserveTransfer($transfer);

        app(NiumTransferService::class)->submitTransfer($provider, $transfer);

        Http::assertSent(fn ($request): bool => $this->isRemittancePost($request)
            && ($request->data()['payout']['payoutMethod'] ?? null) === 'SWIFT'
            && ($request->data()['payout']['swiftFeeType'] ?? null) === 'SHA'
            && ($request->data()['purposeCode'] ?? null) === 'IR01811'
            && ($request->data()['sourceOfFunds'] ?? null) === 'Corporate Account');
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

        app(LedgerService::class)->reserveTransfer($transfer);

        app(NiumTransferService::class)->submitTransfer($provider, $transfer);

        Http::assertSent(function ($request): bool {
            if (! $this->isRemittancePost($request)) {
                return false;
            }

            $payload = $request->data();

            return ($payload['beneficiary']['id'] ?? null) === 'beneficiary-test'
                && ($payload['payout']['sourceAmount'] ?? null) === 10.0
                && ($payload['payout']['sourceCurrency'] ?? null) === 'USD'
                && ($payload['payout']['destinationCurrency'] ?? null) === 'USD'
                && ($payload['payout']['payoutMethod'] ?? null) === 'SWIFT'
                && ($payload['payout']['swiftFeeType'] ?? null) === 'SHA'
                && ($payload['purposeCode'] ?? null) === 'IR01811'
                && ($payload['sourceOfFunds'] ?? null) === 'Corporate Account';
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

            Http::assertNotSent(fn ($request): bool =>
                str_contains($request->url(), '/lockExchangeRate')
            );
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

        app(LedgerService::class)->reserveTransfer($transfer);
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
            'paymentId' => 'PAY-UNKNOWN-WITHOUT-SYSTEM-REF',
        ], 200)]);

        app(LedgerService::class)->reserveTransfer($transfer);

        $updated = app(NiumTransferService::class)->submitTransfer($provider, $transfer);

        $this->assertSame('submission_unknown', $updated->status);
        $this->assertSame('provider_submission_unknown', $updated->failure_code);
        $this->assertNull($updated->external_transfer_id);
        $this->assertSame(
            'PAY-UNKNOWN-WITHOUT-SYSTEM-REF',
            $updated->external_payment_id
        );
    }

    public function test_uncertain_provider_response_preserves_authoritative_references_for_reconciliation(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();

        Http::fake([
            ...$this->purposeCodesRoute(),
            '*' => Http::response([
                'status' => 'PROCESSING',
                'systemReferenceNumber' => 'RT-UNKNOWN-503',
                'paymentId' => 'PAY-UNKNOWN-503',
            ], 503),
        ]);

        app(LedgerService::class)->reserveTransfer($transfer);

        $updated = app(NiumTransferService::class)->submitTransfer(
            $provider,
            $transfer
        );

        $this->assertSame('submission_unknown', $updated->status);
        $this->assertSame(
            'provider_submission_unknown',
            $updated->failure_code
        );
        $this->assertSame(
            'RT-UNKNOWN-503',
            $updated->external_transfer_id
        );
        $this->assertSame(
            'PAY-UNKNOWN-503',
            $updated->external_payment_id
        );
    }

    public function test_successful_transfer_reference_remains_compatible_with_lifecycle_webhook(): void
    {
        config()->set('wallet.ledger.enabled', true);
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response([
            'systemReferenceNumber' => 'RT-WEBHOOK-LIFECYCLE',
            'paymentId' => 'PAY-WEBHOOK-LIFECYCLE',
        ], 200)]);

        app(LedgerService::class)->reserveTransfer($transfer);

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
        app(LedgerService::class)->reserveTransfer($transfer);

        app(NiumTransferService::class)->submitTransfer($provider, $transfer);
        Http::assertSent(fn ($request): bool => $this->isRemittancePost($request)
            && ($request->data()['payout']['swiftFeeType'] ?? null) === 'SHA');
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

        app(LedgerService::class)->reserveTransfer($transfer);

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
        ApiRequestLog::creating(static function (ApiRequestLog $log): void {
            if ($log->operation === 'transfer_money') {
                throw new RuntimeException('Synthetic evidence persistence failure.');
            }
        });

        app(LedgerService::class)->reserveTransfer($transfer);

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

    public function test_manager_preserves_hold_for_provider_response_without_no_wire_proof(): void
    {
        [$provider, $rejectedTransfer] = $this->makeSubmittableTransfer();
        Http::fake([...$this->purposeCodesRoute(), '*' => Http::response(['message' => 'Rejected'], 422)]);
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $rejectedTransfer);
            $this->fail('Provider rejection must be surfaced.');
        } catch (RuntimeException) {
            // A response after a POST does not authorize no-wire request cleanup.
        }
        $rejectedBalance = Balance::query()->where('user_id', $rejectedTransfer->user_id)->sole();
        $this->assertSame('990.00000000', $rejectedBalance->available_balance);
        $this->assertSame('10.00000000', $rejectedBalance->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
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

    public function test_manager_releases_hold_when_payout_fx_lock_fails_before_remittance(): void
    {
        config()->set('services.nium.payout_fx_enabled', true);
        config()->set(
            'services.nium.payout_fx_lock_endpoint',
            '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/lockExchangeRate',
        );

        [$provider, $transfer] = $this->makeSubmittableTransfer([
            'target_currency' => 'EUR',
            'fx_quote_id' => null,
            'fx_rate' => null,
        ]);

        $transfer->beneficiary->update([
            'currency' => 'EUR',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/purposeCodes')) {
                return Http::response([
                    [
                        'description' => 'General Goods Trades - Offline trade',
                        'purposeCode' => 'IR01811',
                    ],
                ]);
            }

            if ($request->method() === 'GET'
                && str_contains($request->url(), '/lockExchangeRate')) {
                return Http::response([
                    'message' => 'FX lock unavailable.',
                ], 503);
            }

            return Http::response([
                'systemReferenceNumber' => 'MUST-NOT-BE-CALLED',
            ], 200);
        });

        try {
            app(ProviderTransferManager::class)->submitTransfer(
                $provider,
                $transfer->fresh([
                    'provider',
                    'user',
                    'beneficiary',
                    'sourceBankAccount',
                ]),
            );

            $this->fail('FX lock failure must abort before remittance submission.');
        } catch (RuntimeException) {
            // Manager must release the wallet hold.
        }

        $balance = Balance::query()
            ->where('user_id', $transfer->user_id)
            ->sole();

        $persisted = Transfer::query()->findOrFail($transfer->id);

        $this->assertSame('1000.00000000', $balance->available_balance);
        $this->assertSame('0.00000000', $balance->reserved_balance);

        $this->assertNull($persisted->fx_quote_id);
        $this->assertNull($persisted->fx_rate);

        $this->assertSame(
            1,
            LedgerEntry::query()
                ->where('source_id', (string) $transfer->id)
                ->where('entry_type', 'hold')
                ->count(),
        );

        $this->assertSame(
            1,
            LedgerEntry::query()
                ->where('source_id', (string) $transfer->id)
                ->where('entry_type', 'release')
                ->count(),
        );

        $this->assertSame(0, \App\Models\FxQuote::query()->count());

        Http::assertSent(fn ($request): bool =>
            $request->method() === 'GET'
            && str_contains($request->url(), '/lockExchangeRate')
        );

        $this->assertNoRemittancePost();
    }

    public function test_manager_preserves_hold_when_hk_swift_remittance_outcome_is_unknown(): void
    {
        Http::preventStrayRequests();
        config()->set('services.nium.payout_fx_enabled', true);
        // Exercise unknown outcomes through the authorized HK/USD SWIFT wire.
        [$provider, $transfer] = $this->makeSubmittableTransfer();

        $remittanceAttempts = 0;

        Http::fake(function ($request) use (&$remittanceAttempts) {
            if (str_contains($request->url(), '/purposeCodes')) {
                return Http::response([
                    [
                        'description' => 'General Goods Trades - Offline trade',
                        'purposeCode' => 'IR01811',
                    ],
                ]);
            }

            if ($request->method() === 'POST'
                && str_ends_with(
                    (string) parse_url($request->url(), PHP_URL_PATH),
                    '/remittance',
                )) {
                $remittanceAttempts++;
                $this->assertSame('USD', $request['payout']['destinationCurrency']);
                $this->assertSame('SWIFT', $request['payout']['payoutMethod']);
                $this->assertArrayNotHasKey('auditId', $request['payout']);

                throw new ConnectionException(
                    'Timed out after provider may have accepted remittance.'
                );
            }

            throw new RuntimeException('Unexpected provider request.');
        });

        $unknown = app(ProviderTransferManager::class)->submitTransfer(
            $provider,
            $transfer->fresh([
                'provider',
                'user',
                'beneficiary',
                'sourceBankAccount',
            ]),
        );

        $this->assertSame('submission_unknown', $unknown->status);
        $this->assertSame('provider_submission_unknown', $unknown->failure_code);
        $this->assertNotEmpty($unknown->provider_operation_key);

        $balance = Balance::query()
            ->where('user_id', $transfer->user_id)
            ->sole();

        $persisted = Transfer::query()
            ->with('fxQuote')
            ->findOrFail($transfer->id);

        $this->assertSame('990.00000000', $balance->available_balance);
        $this->assertSame('10.00000000', $balance->reserved_balance);

        $this->assertNull($persisted->fx_quote_id);
        $this->assertNull($persisted->fx_rate);
        $this->assertDatabaseCount('fx_quotes', 0);
        $this->assertSame(1, LedgerEntry::query()->where('reference', "transfer:{$transfer->id}:hold")->count());

        $this->assertSame(
            0,
            LedgerEntry::query()
                ->where('source_id', (string) $transfer->id)
                ->where('entry_type', 'release')
                ->count(),
        );

        $this->assertCount(
            0,
            collect(Http::recorded())->filter(
                fn ($pair) =>
                    $pair[0]->method() === 'GET'
                    && str_contains($pair[0]->url(), '/lockExchangeRate')
            ),
        );

        $this->assertSame(1, $remittanceAttempts);
    }

    public function test_hk_swift_submission_unknown_retry_does_not_repeat_dispatch_or_reservation(): void
    {
        Http::preventStrayRequests();
        config()->set('services.nium.payout_fx_enabled', true);
        [$provider, $transfer] = $this->makeSubmittableTransfer();

        $remittanceAttempts = 0;

        Http::fake(function ($request) use (&$remittanceAttempts) {
            if (str_contains($request->url(), '/purposeCodes')) {
                return Http::response([
                    [
                        'description' => 'General Goods Trades - Offline trade',
                        'purposeCode' => 'IR01811',
                    ],
                ]);
            }

            if ($request->method() === 'POST'
                && str_ends_with(
                    (string) parse_url($request->url(), PHP_URL_PATH),
                    '/remittance',
                )) {
                $remittanceAttempts++;
                $this->assertSame('USD', $request['payout']['destinationCurrency']);
                $this->assertSame('SWIFT', $request['payout']['payoutMethod']);
                $this->assertArrayNotHasKey('auditId', $request['payout']);

                throw new ConnectionException(
                    'Unknown remittance submission outcome.'
                );
            }

            throw new RuntimeException('Unexpected provider request.');
        });

        $manager = app(ProviderTransferManager::class);

        $unknown = $manager->submitTransfer(
            $provider,
            $transfer->fresh([
                'provider',
                'user',
                'beneficiary',
                'sourceBankAccount',
            ]),
        );

        $this->assertSame('submission_unknown', $unknown->status);
        $this->assertSame('provider_submission_unknown', $unknown->failure_code);
        $this->assertNotEmpty($unknown->provider_operation_key);
        $operationKey = $unknown->provider_operation_key;
        $walletReceipt = $unknown->raw_data['wallet'];
        $providerCalls = count(Http::recorded());
        $this->assertDatabaseCount('fx_quotes', 0);

        try {
            $manager->submitTransfer(
                $provider,
                $unknown->fresh([
                    'provider',
                    'user',
                    'beneficiary',
                    'sourceBankAccount',
                ]),
            );

            $this->fail(
                'submission_unknown transfer must never retry provider submission.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame('Only draft, approval required, or approved transfers can be submitted before provider submission.',
                $exception->getMessage());
        }

        $this->assertSame($providerCalls, count(Http::recorded()));
        $this->assertSame('submission_unknown', $transfer->fresh()->status);
        $this->assertSame($operationKey, $transfer->fresh()->provider_operation_key);
        $this->assertSame($walletReceipt, $transfer->fresh()->raw_data['wallet']);
        $this->assertSame(1, LedgerEntry::query()->where('reference', "transfer:{$transfer->id}:hold")->count());
        $this->assertDatabaseCount('fx_quotes', 0);

        $this->assertCount(
            0,
            collect(Http::recorded())->filter(
                fn ($pair) =>
                    $pair[0]->method() === 'GET'
                    && str_contains($pair[0]->url(), '/lockExchangeRate')
            ),
        );

        $this->assertSame(1, $remittanceAttempts);

        $balance = Balance::query()
            ->where('user_id', $transfer->user_id)
            ->sole();

        $this->assertSame('990.00000000', $balance->available_balance);
        $this->assertSame('10.00000000', $balance->reserved_balance);

        $this->assertSame(
            0,
            LedgerEntry::query()
                ->where('source_id', (string) $transfer->id)
                ->where('entry_type', 'release')
                ->count(),
        );
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

    public function test_mismatched_payout_lock_never_posts_remittance_or_acquires_replacement_lock(): void
    {
        config()->set('services.nium.payout_fx_enabled', true);
        [$provider, $transfer] = $this->makeSubmittableTransfer(['target_currency' => 'EUR', 'fx_rate' => '0.915']);
        $transfer->beneficiary->update(['currency' => 'EUR']);
        $quote = \App\Models\FxQuote::query()->create([
            'user_id' => $transfer->user_id, 'provider_id' => $provider->id, 'quote_ref' => '112',
            'source_currency' => 'USD', 'target_currency' => 'EUR', 'source_amount' => 10,
            'target_amount' => '9.15', 'net_rate' => '0.915', 'expires_at' => now()->addMinutes(10),
            'raw_data' => ['provider_fx_type' => 'payout_fx_lock', 'audit_id' => '112'],
        ]);
        $transfer->update(['fx_quote_id' => $quote->id]);
        $original = $quote->getAttributes();
        $otherUser = User::factory()->create();
        $otherProvider = IntegrationProvider::query()->create(['code' => 'other', 'name' => 'Other', 'status' => 'active']);
        foreach ([
            ['user_id' => $otherUser->id], ['provider_id' => $otherProvider->id],
            ['source_amount' => 11], ['source_currency' => 'EUR'], ['target_currency' => 'USD'],
            ['net_rate' => '0.916'], ['net_rate' => 0], ['expires_at' => now()], ['expires_at' => null],
            ['raw_data' => ['provider_fx_type' => 'payout_fx_lock', 'audit_id' => '113']],
            ['raw_data' => ['provider_fx_type' => 'modern_quote', 'audit_id' => '112']],
        ] as $overrides) {
            $quote->setRawAttributes($original);
            $quote->forceFill($overrides)->save();
            Http::fake($this->purposeCodesRoute());
            Http::preventStrayRequests();
            try {
                app(NiumTransferService::class)->submitTransfer($provider, $transfer->fresh());
                $this->fail('A mismatched payout lock must be rejected.');
            } catch (RuntimeException) {
                $this->assertNoRemittancePost();
                Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/lockExchangeRate'));
                $this->assertSame('draft', $transfer->fresh()->status);
            }
        }
    }

    public function test_invalid_purpose_is_rejected_before_requesting_a_payout_fx_lock(): void
    {
        config()->set('services.nium.payout_fx_enabled', true);
        [$provider, $transfer] = $this->makeSubmittableTransfer(['target_currency' => 'EUR', 'purpose_code' => 'UNSUPPORTED']);
        $transfer->beneficiary->update(['currency' => 'EUR']);
        Http::fake($this->purposeCodesRoute());
        Http::preventStrayRequests();
        $this->expectException(RuntimeException::class);
        try {
            app(NiumTransferService::class)->submitTransfer($provider, $transfer->fresh());
        } finally {
            $this->assertNoRemittancePost();
            Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/lockExchangeRate'));
        }
    }

    public function test_bound_legacy_lock_cannot_be_submitted_from_another_wallet(): void
    {
        config()->set('services.nium.payout_fx_enabled', true);
        [$provider, $transfer] = $this->makeSubmittableTransfer(['target_currency' => 'EUR']);
        $transfer->beneficiary->update(['currency' => 'EUR']);
        Http::preventStrayRequests();
        Http::fake([
            ...$this->purposeCodesRoute(),
            '*lockExchangeRate*' => Http::response([
                'audit_id' => '112', 'fx_rate' => '0.915', 'hold_expiry_at' => now()->addMinutes(10)->toISOString(),
            ]),
        ]);
        // Existing legacy transport fixture only; SGD acquisition remains held.
        $quote = app(NiumPayoutFxLockService::class)->createLock($provider, $transfer->user, 'USD', 'EUR', '10');
        $transfer->update(['fx_quote_id' => $quote->id, 'fx_rate' => $quote->net_rate]);
        $transfer->user->providerAccounts()->update(['external_account_id' => 'changed-wallet']);
        try {
            app(NiumTransferService::class)->submitTransfer($provider, $transfer->fresh());
            $this->fail('A lock from another wallet must never be submitted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('binding does not match', $exception->getMessage());
        }
        $this->assertNoRemittancePost();
        $this->assertSame('draft', $transfer->fresh()->status);
        $this->assertNull($transfer->fresh()->provider_operation_key);
        $this->assertSame(1, FxQuote::query()->count());
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/lockExchangeRate')));
    }

    public function test_preflight_failure_releases_created_hold_and_retry_is_rejected_without_provider_io(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        Http::preventStrayRequests();
        Http::fake(['*purposeCodes' => Http::response(['message' => 'Unavailable'], 503)]);
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $transfer);
            $this->fail('Preflight must fail before any remittance submission.');
        } catch (\App\Services\Nium\NiumSubmissionPreflightException $exception) {
            $this->assertSame('Nium purpose codes are temporarily unavailable.', $exception->getMessage());
        }
        $this->assertSame('draft', $transfer->fresh()->status);
        $this->assertNull($transfer->fresh()->provider_operation_key);
        $this->assertSame('1000.00000000', Balance::query()->sole()->available_balance);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $entries = LedgerEntry::query()->orderBy('id')->get()->toArray();
        $calls = count(Http::recorded());

        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $transfer->fresh());
            $this->fail('Released hold retry must not be revived.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Released or debited transfer holds cannot be reused as active reservations.', $exception->getMessage());
        }
        $this->assertSame($entries, LedgerEntry::query()->orderBy('id')->get()->toArray());
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertNoRemittancePost();
    }

    public function test_preflight_failure_reusing_an_active_hold_has_no_cleanup_authority(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer(['target_amount' => '10']);
        app(LedgerService::class)->reserveTransfer($transfer);
        Http::fake($this->purposeCodesRoute());

        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $transfer->fresh());
            $this->fail('Policy must reject the invalid instruction.');
        } catch (\App\Services\Nium\NiumSubmissionPreflightException) {
            // This request received a duplicate hold, not cleanup authority.
        }
        $this->assertSame('990.00000000', Balance::query()->sole()->available_balance);
        $this->assertSame('10.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertNoRemittancePost();
    }

    public function test_losing_submit_during_winner_post_cannot_release_winners_hold(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        $stale = $transfer->fresh(['provider', 'user', 'beneficiary', 'sourceBankAccount']);
        $posts = 0;
        Http::fake(function ($request) use ($provider, $stale, &$posts) {
            if (str_contains($request->url(), '/purposeCodes')) {
                return Http::response([['description' => 'General Goods', 'purposeCode' => 'IR01811']]);
            }
            $this->assertTrue($this->isRemittancePost($request));
            $posts++;
            try {
                app(ProviderTransferManager::class)->submitTransfer($provider, $stale);
                $this->fail('Stale concurrent request must not acquire another submission.');
            } catch (RuntimeException) {
                $this->assertSame('10.00000000', Balance::query()->sole()->reserved_balance);
                $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'release')->count());
            }

            return Http::response(['systemReferenceNumber' => 'RT-PATCH258-WINNER']);
        });
        $submitted = app(ProviderTransferManager::class)->submitTransfer($provider, $transfer);

        $this->assertSame('pending', $submitted->status);
        $this->assertSame(1, $posts);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame('10.00000000', Balance::query()->sole()->reserved_balance);
    }

    public function test_hold_creator_cleanup_is_fenced_when_reuser_has_committed_submission(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        $entered = false;
        $posts = 0;
        Http::fake(function ($request) use ($provider, $transfer, &$entered, &$posts) {
            if (str_contains($request->url(), '/purposeCodes')) {
                if (! $entered) {
                    $entered = true;
                    // Deterministic interleaving, not a PostgreSQL concurrency proof.
                    $winner = app(ProviderTransferManager::class)->submitTransfer($provider, $transfer->fresh());
                    $this->assertSame('pending', $winner->status);

                    return Http::response(['message' => 'Creator preflight failed'], 503);
                }

                return Http::response([['description' => 'General Goods', 'purposeCode' => 'IR01811']]);
            }
            $this->assertTrue($this->isRemittancePost($request));
            $posts++;

            return Http::response(['systemReferenceNumber' => 'RT-PATCH258-REUSER']);
        });
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $transfer);
            $this->fail('Creator preflight should fail after the competing submit.');
        } catch (\App\Services\Nium\NiumSubmissionPreflightException) {
            // The creator owns the original hold, but a committed wire fences release.
        }
        $this->assertSame('pending', $transfer->fresh()->status);
        $this->assertSame(1, $posts);
        $this->assertSame('10.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_nium_submission_without_own_active_hold_never_posts(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        Http::fake($this->purposeCodesRoute());
        try {
            app(NiumTransferService::class)->submitTransfer($provider, $transfer);
            $this->fail('Direct provider primitive must require an own active hold.');
        } catch (\App\Services\Nium\NiumSubmissionPreflightException $exception) {
            $this->assertSame('An own hold journal is required for this transfer.', $exception->getMessage());
        }
        $this->assertSame('draft', $transfer->fresh()->status);
        $this->assertNull($transfer->fresh()->provider_operation_key);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertNoRemittancePost();
    }

    public function test_unpersisted_submission_claim_never_posts_and_releases_only_created_hold(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        Http::preventStrayRequests();
        Http::fake($this->purposeCodesRoute());
        Transfer::updating(static function (Transfer $candidate): ?bool {
            return $candidate->isDirty('status') && $candidate->status === 'submitting' ? false : null;
        });

        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $transfer);
            $this->fail('A vetoed submission claim must never reach provider POST.');
        } catch (\App\Services\Nium\NiumSubmissionPreflightException $exception) {
            $this->assertSame('Nium submission claim was not persisted; no payout was sent.', $exception->getMessage());
        }

        $this->assertSame('draft', $transfer->fresh()->status);
        $this->assertNull($transfer->fresh()->provider_operation_key);
        $this->assertSame('1000.00000000', Balance::query()->sole()->available_balance);
        $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertSame('1000.00000000', Balance::query()->sole()->ledger_balance);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'hold')->count());
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'release')->count());
        $this->assertNoRemittancePost();
    }

    public function test_unclassified_failure_after_submission_claim_preserves_hold(): void
    {
        [$provider, $transfer] = $this->makeSubmittableTransfer();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/purposeCodes')) {
                return Http::response([['description' => 'General Goods', 'purposeCode' => 'IR01811']]);
            }
            throw new RuntimeException('Unclassified failure at remittance boundary.');
        });
        try {
            app(ProviderTransferManager::class)->submitTransfer($provider, $transfer);
            $this->fail('Unknown failure must be surfaced.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unclassified failure at remittance boundary.', $exception->getMessage());
        }
        $this->assertSame('submitting', $transfer->fresh()->status);
        $this->assertNotEmpty($transfer->fresh()->provider_operation_key);
        $this->assertSame('10.00000000', Balance::query()->sole()->reserved_balance);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    private function assertRemittanceDispatchHeldState(Transfer $transfer): void
    {
        $this->assertSame('failed', $transfer->status);
        $this->assertSame('remittance_dispatch_held', $transfer->failure_code);
        $this->assertSame(NiumSgdAdmissionFence::DISPATCH_HOLD, $transfer->failure_reason);
        $this->assertNotEmpty($transfer->provider_operation_key);
        $this->assertNull($transfer->external_transfer_id);
        $this->assertNull($transfer->external_payment_id);
        $this->assertNull($transfer->submitted_at);
        $this->assertNull($transfer->provider_status_at);
        $this->assertSame(now()->toDateTimeString(), $transfer->completed_at?->toDateTimeString());
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
            'user_id' => $user->id, 'provider_id' => $provider->id,
            'external_account_id' => 'wallet-test', 'currency' => 'USD',
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
