<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\NiumVirtualAccount;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumClientDetailsService;
use App\Services\Nium\NiumFundingSettlementVerifier;
use App\Services\Nium\NiumHkFundingReadinessRunner;
use App\Services\Nium\NiumVirtualAccountDetailsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class NiumFundingReadinessF1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.test');
        config()->set('services.nium.client_id', 'client-test');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'secret-key']);
        config()->set('services.nium.webhook', ['static_header_name' => 'x-partner-key', 'static_header_value' => 'partner-key']);
        config()->set('services.nium.client_details_endpoint', '/api/v1/client/{clientHashId}');
        config()->set('services.nium.virtual_account_details_endpoint', '/api/v2/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentIds');
    }

    public function test_client_details_uses_dedicated_path_and_returns_only_safe_projection(): void
    {
        Http::fake(['*' => Http::response([
            'regulatoryRegion' => 'HK', 'allowThirdPartyFunding' => false,
            'fundingInstrumentType' => 'BANK_TRANSFER', 'ekycRedirectUrl' => 'https://secret.example/session',
            'currencies' => [['currencyCode' => 'HKD', 'remittanceAllowed' => true, 'fxSellAllowed' => false, 'authorizationOrder' => 1, 'decimalUnit' => 2, 'secret' => 'no']],
            'paymentIds' => [['currencyCode' => 'HKD', 'bankName' => 'BANK', 'bankNameFull' => 'Bank Full', 'accountType' => 'LOCAL', 'uniquePaymentId' => 'VA-SECRET', 'uniquePayerId' => 'PAYER-SECRET', 'bankAddress' => 'no']],
            'apiKey' => 'must-not-survive',
        ])]);

        $result = app(NiumClientDetailsService::class)->get(separateHumanApproval: true);

        $this->assertSame('HK', $result['regulatoryRegion']);
        $this->assertTrue($result['ekycRedirectUrlConfigured']);
        $this->assertSame(['currencyCode', 'remittanceAllowed', 'fxSellAllowed', 'authorizationOrder', 'decimalUnit'], array_keys($result['currencies'][0]));
        $this->assertTrue($result['paymentIds'][0]['uniquePaymentIdPresent']);
        $this->assertTrue($result['paymentIds'][0]['uniquePayerIdPresent']);
        $encoded = json_encode([$result, ApiRequestLog::query()->get()->toArray()], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret.example', $encoded);
        $this->assertStringNotContainsString('VA-SECRET', $encoded);
        $this->assertStringNotContainsString('must-not-survive', $encoded);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.sandbox.nium.test/api/v1/client/client-test');
    }

    public function test_client_details_blank_redirect_is_false_and_failures_hold(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['regulatoryRegion' => 'HK', 'ekycRedirectUrl' => '   '])
            ->push([], 503)
            ->push('not-json', 200)]);

        $this->assertFalse(app(NiumClientDetailsService::class)->get(separateHumanApproval: true)['ekycRedirectUrlConfigured']);
        foreach ([1, 2] as $_) {
            try {
                app(NiumClientDetailsService::class)->get(separateHumanApproval: true);
                $this->fail('Expected client details HOLD.');
            } catch (RuntimeException $exception) {
                $this->assertSame('HOLD_NIUM_CLIENT_DETAILS_UNAVAILABLE', $exception->getMessage());
            }
        }
    }

    public function test_client_details_redirect_flag_rejects_non_string_values(): void
    {
        $values = [null, '', '   ', [], ['url' => 'https://example.test'], 123, true, false];
        $sequence = Http::sequence();
        foreach ($values as $value) {
            $sequence->push(['regulatoryRegion' => 'HK', 'ekycRedirectUrl' => $value]);
        }
        Http::fake(['*' => $sequence]);

        foreach ($values as $_) {
            $result = app(NiumClientDetailsService::class)->get(separateHumanApproval: true);
            $this->assertFalse($result['ekycRedirectUrlConfigured']);
        }
    }

    public function test_virtual_account_details_exact_active_self_funding_match_is_runtime_only(): void
    {
        [, , $account, $virtualAccount] = $this->eligibleVirtualAccount();
        Http::fake(['*' => Http::response(['content' => [$this->van()]])]);

        $result = app(NiumVirtualAccountDetailsService::class)->get($virtualAccount, separateHumanApproval: true);

        $this->assertSame('SELF_FUNDING_ACCOUNT', $result['accountCategory']);
        $this->assertSame('BANK', $result['bankName']);
        $this->assertSame(1, NiumVirtualAccount::query()->count());
        $this->assertNull($virtualAccount->fresh()->account_type);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.sandbox.nium.test/api/v2/client/client-test/customer/customer-7/wallet/wallet-7/paymentIds?uniquePaymentId=VA-7&status=Active&page=0&size=20');
        $this->assertSame('customer-7', $account->fresh()->external_customer_id);
    }

    public function test_virtual_account_details_hold_on_all_correlation_and_review_failures(): void
    {
        [, , , $virtualAccount] = $this->eligibleVirtualAccount();
        $cases = [
            [[], 'HOLD_NIUM_VAN_MATCH_NOT_UNAMBIGUOUS'],
            [[$this->van(), $this->van()], 'HOLD_NIUM_VAN_MATCH_NOT_UNAMBIGUOUS'],
            [[$this->van(['status' => 'Inactive'])], 'HOLD_NIUM_VAN_NOT_ACTIVE'],
            [[$this->van(['currencyCode' => 'USD'])], 'HOLD_NIUM_VAN_CURRENCY_MISMATCH'],
            [[$this->van(['accountCategory' => 'COLLECTION_ACCOUNT'])], 'HOLD_NIUM_VAN_ACCOUNT_CATEGORY_MISMATCH'],
            [[$this->van(['routingCodeValue1' => null])], 'HOLD_NIUM_VAN_BANK_ROUTING_REVIEW_REQUIRED'],
        ];
        $sequence = Http::sequence();
        foreach ($cases as [$content]) {
            $sequence->push(['content' => $content]);
        }
        Http::fake(['*' => $sequence]);

        foreach ($cases as [$content, $message]) {
            try {
                app(NiumVirtualAccountDetailsService::class)->get($virtualAccount, separateHumanApproval: true);
                $this->fail('Expected VAN details HOLD.');
            } catch (RuntimeException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    public function test_virtual_account_cannot_resolve_to_another_authoritative_account(): void
    {
        [$provider, $user, $account, $virtualAccount] = $this->eligibleVirtualAccount();
        $account->forceFill(['provider_id' => IntegrationProvider::query()->create(['code' => 'other', 'name' => 'Other', 'status' => 'active'])->id])->save();
        $user->providerAccounts()->create([
            'provider_id' => $provider->id, 'external_customer_id' => 'customer-other', 'external_account_id' => 'wallet-other',
            'status' => 'active', 'provider_status' => 'clear', 'customer_id_verified_at' => now(), 'wallet_id_verified_at' => now(),
        ]);
        Http::fake();

        $this->expectExceptionMessage('HOLD_NIUM_VAN_ACCOUNT_BINDING_MISMATCH');
        try {
            app(NiumVirtualAccountDetailsService::class)->get($virtualAccount, separateHumanApproval: true);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_virtual_account_requires_nium_provider_and_present_provider_ids_before_http(): void
    {
        [, , $account, $virtualAccount] = $this->eligibleVirtualAccount();
        $account->provider->forceFill(['code' => 'other'])->save();
        Http::fake();

        try {
            app(NiumVirtualAccountDetailsService::class)->get($virtualAccount, separateHumanApproval: true);
            $this->fail('Expected non-Nium provider binding to HOLD.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HOLD_NIUM_VAN_ACCOUNT_BINDING_MISMATCH', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_funding_verifier_requires_approved_and_settled_and_preserves_rfi_review(): void
    {
        $verifier = app(NiumFundingSettlementVerifier::class);
        $this->assertTrue($verifier->verify(['status' => 'Approved', 'complianceStatus' => 'Settled'])['accepted']);
        $this->assertFalse($verifier->verify(['status' => 'Pending', 'complianceStatus' => 'Settled'])['accepted']);
        $this->assertFalse($verifier->verify(['status' => 'Approved', 'complianceStatus' => 'Pending'])['accepted']);
        $this->assertFalse($verifier->verify(['status' => 'Rejected', 'complianceStatus' => 'Settled'])['accepted']);
        $this->assertTrue($verifier->verify(['status' => 'Approved', 'complianceStatus' => 'RFI_REQUESTED'])['reviewRequired']);
    }

    public function test_provider_gets_require_separate_human_approval_before_http(): void
    {
        [, , , $virtualAccount] = $this->eligibleVirtualAccount();
        Http::fake();

        foreach ([
            fn () => app(NiumClientDetailsService::class)->get(),
            fn () => app(NiumVirtualAccountDetailsService::class)->get($virtualAccount),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected explicit provider GET approval HOLD.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('HUMAN_APPROVAL_REQUIRED', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_account_7_audit_is_local_only_holds_for_kyc_and_preserves_account_4(): void
    {
        $provider = IntegrationProvider::query()->forceCreate(['id' => 1, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        UserProviderAccount::query()->forceCreate(['id' => 4, 'user_id' => User::factory()->create()->id, 'provider_id' => $provider->id, 'status' => 'active']);
        $user = User::factory()->create(['id' => 9, 'kyc_status' => 'pending']);
        UserProviderAccount::query()->forceCreate(['id' => 7, 'user_id' => 9, 'provider_id' => $provider->id, 'external_customer_id' => 'customer-7', 'external_account_id' => 'wallet-7', 'status' => 'under_review', 'provider_status' => 'pending']);
        $before = UserProviderAccount::query()->findOrFail(4)->getRawOriginal();
        Http::fake();

        $result = app(NiumHkFundingReadinessRunner::class)->audit();

        $this->assertSame('HOLD_KYC_BLOCKED', $result['terminal']);
        $this->assertFalse($result['kyc_bypass']);
        $this->assertSame(0, $result['provider_get_count']);
        $this->assertSame(0, $result['db_write_count']);
        $this->assertSame($before, UserProviderAccount::query()->findOrFail(4)->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_account_7_audit_requires_clear_nium_status_and_empty_sub_status(): void
    {
        $account = $this->accountSevenFixture('verified', 'clear', 'awaiting_kyc');
        Http::fake();

        $this->assertSame('HOLD_KYC_BLOCKED', app(NiumHkFundingReadinessRunner::class)->audit()['terminal']);

        $account->forceFill(['provider_status' => 'pending', 'provider_sub_status' => 'awaiting_kyc'])->save();
        $this->assertSame('HOLD_KYC_BLOCKED', app(NiumHkFundingReadinessRunner::class)->audit()['terminal']);

        $account->forceFill(['provider_status' => 'clear', 'provider_sub_status' => null])->save();
        $this->assertSame('HOLD_PROVIDER_FACTS_NOT_REVIEWED', app(NiumHkFundingReadinessRunner::class)->audit()['terminal']);
        Http::assertNothingSent();
    }

    public function test_account_7_audit_rejects_missing_endpoint_placeholders_without_http_or_writes(): void
    {
        $account = $this->accountSevenFixture('verified', 'clear', null);
        $before = $account->fresh()->getRawOriginal();
        Http::fake();

        foreach ([
            ['services.nium.client_details_endpoint', '/api/v1/client/static-client'],
            ['services.nium.virtual_account_details_endpoint', '/api/v2/client/{clientHashId}/paymentIds'],
        ] as [$key, $value]) {
            config()->set($key, $value);
            try {
                app(NiumHkFundingReadinessRunner::class)->audit();
                $this->fail('Expected endpoint placeholder contract to HOLD.');
            } catch (RuntimeException $exception) {
                $this->assertSame('HOLD_NIUM_FUNDING_ENDPOINT_CONFIG_INVALID', $exception->getMessage());
            }
            config()->set('services.nium.client_details_endpoint', '/api/v1/client/{clientHashId}');
            config()->set('services.nium.virtual_account_details_endpoint', '/api/v2/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentIds');
        }

        $this->assertSame($before, $account->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    private function eligibleVirtualAccount(): array
    {
        $provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $user = User::factory()->create(['kyc_status' => 'verified']);
        $account = $user->providerAccounts()->create([
            'provider_id' => $provider->id, 'external_customer_id' => 'customer-7', 'external_account_id' => 'wallet-7',
            'status' => 'active', 'provider_status' => 'clear', 'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now(),
        ]);
        $virtualAccount = NiumVirtualAccount::query()->create([
            'user_provider_account_id' => $account->id, 'provider_payment_id' => 'VA-7',
            'virtual_account_reference' => 'VA-7', 'currency' => 'HKD',
            'account_category' => 'SELF_FUNDING_ACCOUNT', 'status' => 'assigned', 'assigned_at' => now(),
        ]);

        return [$provider, $user, $account, $virtualAccount];
    }

    private function accountSevenFixture(string $userKycStatus, string $providerStatus, ?string $providerSubStatus): UserProviderAccount
    {
        $provider = IntegrationProvider::query()->forceCreate(['id' => 1, 'code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        UserProviderAccount::query()->forceCreate([
            'id' => 4, 'user_id' => User::factory()->create()->id, 'provider_id' => $provider->id, 'status' => 'active',
        ]);
        User::factory()->create(['id' => 9, 'kyc_status' => $userKycStatus]);

        return UserProviderAccount::query()->forceCreate([
            'id' => 7, 'user_id' => 9, 'provider_id' => $provider->id,
            'external_customer_id' => 'customer-7', 'external_account_id' => 'wallet-7',
            'status' => 'active', 'provider_status' => $providerStatus, 'provider_sub_status' => $providerSubStatus,
        ]);
    }

    private function van(array $overrides = []): array
    {
        return [...[
            'uniquePaymentId' => 'VA-7', 'status' => 'Active', 'accountCategory' => 'SELF_FUNDING_ACCOUNT',
            'currencyCode' => 'HKD', 'accountName' => 'HONG KONG MACHINING GROUP CO., LIMITED',
            'accountType' => 'LOCAL', 'bankName' => 'BANK', 'fullBankName' => 'Bank Full',
            'bankAddress' => 'Hong Kong', 'routingCodeType1' => 'BANK_CODE', 'routingCodeValue1' => '123',
            'routingCodeType2' => null, 'routingCodeValue2' => null, 'uniquePayerId' => null, 'uniquePayerType' => null,
        ], ...$overrides];
    }
}
