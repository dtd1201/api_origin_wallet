<?php

namespace Tests\Feature;

use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumPayoutFxLockService;
use App\Services\Nium\NiumSgdLocalPayoutGate;
use App\Services\Nium\NiumTransferPolicy;
use App\Services\Nium\NiumTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumSgdLocalPayoutGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('services.nium.base_url', 'https://nium.test');
        config()->set('services.nium.client_id', 'client-test');
        config()->set('services.nium.auth', ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-only']);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-only');
        config()->set('services.nium.supported_corridors', []);
        config()->set('wallet.transfer_controls.require_admin_approval', false);
    }

    private function corridor(array $overrides = []): array
    {
        return array_replace([
            'destinationCountry' => 'SG', 'destinationCurrency' => 'SGD', 'payoutMethod' => 'LOCAL',
            'beneficiaryAccountType' => 'INDIVIDUAL', 'customerType' => 'CORPORATE', 'routingCodeType' => 'SWIFT',
        ], $overrides);
    }

    private function candidate(): array
    {
        $provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $user = User::factory()->create(['kyc_status' => 'verified']);
        // No general profile: approved KYC, not a user-editable profile, identifies Corporate.
        $user->kycProfile()->create([
            'status' => 'approved', 'applicant_type' => 'business', 'registered_country_code' => 'HK',
            'country_code' => 'HK', 'metadata' => ['nium_region' => 'HK'],
            'legal_name' => 'Test HK Corporate', 'address_line1' => '1 Test Road',
            'city' => 'Hong Kong', 'postal_code' => '999077',
        ]);
        $user->providerAccounts()->create([
            'provider_id' => $provider->id, 'external_customer_id' => 'customer-test', 'external_account_id' => 'wallet-test',
            'status' => 'active', 'provider_status' => 'clear', 'reconciliation_status' => 'reconciled',
            'customer_id_verified_at' => now(), 'wallet_id_verified_at' => now(), 'provider_ids_verified_at' => now(),
        ]);
        $beneficiary = Beneficiary::query()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id, 'external_beneficiary_id' => 'dynamic-provider-beneficiary',
            'beneficiary_type' => 'personal', 'full_name' => 'Test Individual', 'country_code' => 'SG', 'currency' => 'SGD',
            'payout_method' => 'LOCAL', 'swift_bic' => 'DBSSSGSGXXX', 'status' => 'active',
        ]);

        return [$user, $provider, $beneficiary];
    }

    public function test_verified_hk_corporate_to_sg_individual_matches_exact_provider_corridor(): void
    {
        [$user, $provider, $beneficiary] = $this->candidate();
        Http::fake(['*supportedCorridors*' => Http::response(['content' => [$this->corridor()]])]);
        $match = app(NiumSgdLocalPayoutGate::class)->assertCandidate($user, $provider, $beneficiary, 'SGD');
        $this->assertSame($this->corridor(), $match);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request['customerType'] === 'CORPORATE'
            && $request['beneficiaryAccountType'] === 'INDIVIDUAL'
            && $request['payoutMethod'] === 'LOCAL'
            && $request['destinationCountry'] === 'SG'
            && $request['destinationCurrency'] === 'SGD');
        $this->assertNoMoneyMovement();
    }

    public static function invalidCandidates(): array
    {
        return [
            'inactive' => ['beneficiary', ['status' => 'inactive']],
            'missing provider ID' => ['beneficiary', ['external_beneficiary_id' => null]],
            'blank provider ID' => ['beneficiary', ['external_beneficiary_id' => ' ']],
            'cross user' => ['beneficiary', ['user_id' => 999]],
            'cross provider' => ['beneficiary', ['provider_id' => 999]],
            'corporate beneficiary' => ['beneficiary', ['beneficiary_type' => 'business']],
            'unknown beneficiary type' => ['beneficiary', ['beneficiary_type' => 'unknown']],
            'wrong country' => ['beneficiary', ['country_code' => 'US']],
            'wrong currency' => ['beneficiary', ['currency' => 'USD']],
            'wrong method' => ['beneficiary', ['payout_method' => 'SWIFT']],
            'unverified method' => ['beneficiary', ['payout_method' => null, 'raw_data' => ['nium' => ['payoutMethod' => 'LOCAL']]]],
            'missing routing' => ['beneficiary', ['swift_bic' => null]],
            'malformed routing' => ['beneficiary', ['swift_bic' => '12345']],
            'individual sender' => ['kyc', ['applicant_type' => 'individual']],
            'unapproved sender' => ['kyc', ['status' => 'pending']],
            'non HK sender' => ['kyc', ['registered_country_code' => 'SG', 'country_code' => 'SG', 'metadata' => []]],
            'blocked account' => ['account', ['provider_status' => 'blocked']],
            'unreconciled account' => ['account', ['reconciliation_status' => 'pending']],
            'missing customer' => ['account', ['external_customer_id' => null]],
            'missing wallet' => ['account', ['external_account_id' => null]],
            'unverified wallet' => ['account', ['wallet_id_verified_at' => null]],
        ];
    }

    #[DataProvider('invalidCandidates')]
    public function test_invalid_candidate_fails_before_corridor_or_money_movement(string $model, array $attributes): void
    {
        [$user, $provider, $beneficiary] = $this->candidate();
        match ($model) {
            'beneficiary' => $beneficiary->forceFill($attributes),
            'kyc' => $user->kycProfile->forceFill($attributes),
            'account' => $user->providerAccounts()->firstOrFail()->update($attributes),
        };
        Http::fake();
        try {
            app(NiumSgdLocalPayoutGate::class)->assertCandidate($user, $provider, $beneficiary, 'SGD');
            $this->fail('Invalid candidate must be rejected.');
        } catch (RuntimeException) {
            Http::assertNothingSent();
            $this->assertNoMoneyMovement();
        }
    }

    public static function unsupportedCorridors(): array
    {
        return [
            'lookup failure' => [503, null],
            'no match' => [200, null],
            'different country' => [200, ['destinationCountry' => 'HK']],
            'different currency' => [200, ['destinationCurrency' => 'USD']],
            'different method' => [200, ['payoutMethod' => 'SWIFT']],
            'different customer type' => [200, ['customerType' => 'INDIVIDUAL']],
            'different beneficiary type' => [200, ['beneficiaryAccountType' => 'CORPORATE']],
            'different routing' => [200, ['routingCodeType' => 'BANK CODE']],
            'missing routing evidence' => [200, ['routingCodeType' => []]],
        ];
    }

    #[DataProvider('unsupportedCorridors')]
    public function test_corridor_failures_fail_closed(int $status, ?array $overrides): void
    {
        [$user, $provider, $beneficiary] = $this->candidate();
        Http::fake(['*supportedCorridors*' => Http::response(['content' => $overrides === null ? [] : [$this->corridor($overrides)]], $status)]);
        $this->expectException(RuntimeException::class);
        try {
            app(NiumSgdLocalPayoutGate::class)->assertCandidate($user, $provider, $beneficiary, 'SGD');
        } finally {
            $this->assertNoMoneyMovement();
        }
    }

    public function test_transfer_currency_must_match_beneficiary_before_lookup(): void
    {
        [$user, $provider, $beneficiary] = $this->candidate();
        Http::fake();
        $this->expectException(RuntimeException::class);
        try {
            app(NiumSgdLocalPayoutGate::class)->assertCandidate($user, $provider, $beneficiary, 'USD');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_global_fx_flag_cannot_bypass_route_hold_for_creation_payload_submission_or_direct_lock(): void
    {
        [$user, $provider, $beneficiary] = $this->candidate();
        Balance::query()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id, 'currency' => 'USD',
            'available_balance' => 1000, 'ledger_balance' => 1000, 'as_of' => now(),
        ]);
        $transfer = Transfer::query()->create([
            'transfer_no' => 'SG-TEST', 'user_id' => $user->id, 'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id, 'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'SGD',
            'source_amount' => 1, 'fee_amount' => 0, 'fee_currency' => 'USD', 'status' => 'draft',
            'purpose_code' => 'TEST_PURPOSE', 'reference_text' => 'Test reference',
        ]);
        $purposes = [['code' => 'TEST_PURPOSE', 'label' => 'Mock only, not an approved CUG purpose']];
        foreach ([false, true] as $fxEnabled) {
            config()->set('services.nium.payout_fx_enabled', $fxEnabled);
            Http::fake([
                '*supportedCorridors*' => Http::response(['content' => [$this->corridor()]]),
                '*purposeCodes' => Http::response([['purposeCode' => 'TEST_PURPOSE', 'description' => 'Mock only']]),
            ]);
            $policy = app(NiumTransferPolicy::class);
            foreach ([
                fn () => $policy->normalizeCreate($transfer->toArray(), $user, $provider, $beneficiary, $purposes),
                fn () => $policy->providerPayload($transfer->fresh(['user', 'provider', 'beneficiary']), $purposes),
                fn () => app(NiumTransferService::class)->submitTransfer($provider, $transfer),
                fn () => app(ProviderTransferManager::class)->submitTransfer($provider, $transfer->fresh()),
                fn () => app(NiumPayoutFxLockService::class)->createLock($provider, $user, 'USD', 'SGD', 1),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('SGD route must stay closed even with the global FX flag enabled.');
                } catch (RuntimeException $exception) {
                    $this->assertTrue(str_contains($exception->getMessage(), 'not enabled')
                        || str_contains($exception->getMessage(), 'pending verification'), $exception->getMessage());
                }
            }
            $this->assertSame('draft', $transfer->fresh()->status);
            $this->assertSame('1000.00000000', Balance::query()->sole()->available_balance);
            $this->assertSame('0.00000000', Balance::query()->sole()->reserved_balance);
            $this->assertNoMoneyMovement();
        }
    }

    private function assertNoMoneyMovement(): void
    {
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' || str_contains($request->url(), '/lockExchangeRate'));
        $this->assertSame(0, FxQuote::query()->count());
    }
}
