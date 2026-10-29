<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Nium\NiumTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NiumTransferKycProfileStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('wallet.transfer_controls.require_admin_approval', false);
        config()->set('services.nium.payout_fx_enabled', false);
        config()->set('services.nium.base_url', 'https://gateway.nium.test');
        config()->set('services.nium.client_id', 'kyc-status-client');
        config()->set('services.nium.auth', [
            'mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-key',
        ]);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-partner-key');

        Http::preventStrayRequests();
        Http::fake([
            '*api/v1/remittance/purposeCodes' => Http::response([
                ['description' => 'General Goods Trades', 'purposeCode' => 'IR01811'],
            ]),
            'https://gateway.nium.test/api/v1/client/kyc-status-client/customer/kyc-status-customer/wallet/kyc-status-wallet/remittance' => Http::response(['systemReferenceNumber' => 'RT-KYC-STATUS']),
        ]);
    }

    public static function completedProfileStatuses(): array
    {
        return [
            'approved' => ['approved'],
            'verified' => ['verified'],
        ];
    }

    #[DataProvider('completedProfileStatuses')]
    public function test_completed_hk_business_profile_can_create_and_submit_usd_swift_payout(string $status): void
    {
        [$user, $provider, $payload] = $this->customer(['status' => $status]);
        $url = "/api/user/users/{$user->id}/transfers";

        $response = $this->postJson($url, $payload)->assertCreated();
        $this->assertDatabaseCount('transfers', 1);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');

        // Reusing the same client reference still returns the original draft.
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('id', $response->json('id'));
        $this->assertDatabaseCount('transfers', 1);

        $transfer = Transfer::query()->sole();
        $updated = app(NiumTransferService::class)->submitTransfer($provider, $transfer);

        $this->assertSame('pending', $updated->status);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/remittance')
            && $request['payout']['sourceAmount'] === 5.0
            && $request['payout']['sourceCurrency'] === 'USD'
            && $request['payout']['destinationCurrency'] === 'USD'
            && $request['payout']['payoutMethod'] === 'SWIFT');
    }

    public static function ineligibleProfiles(): array
    {
        return [
            'pending' => [['status' => 'pending']],
            'rejected' => [['status' => 'rejected']],
            'approved individual' => [['status' => 'approved', 'applicant_type' => 'individual']],
            'verified individual' => [['status' => 'verified', 'applicant_type' => 'individual']],
            'approved non-HK' => [[
                'status' => 'approved', 'registered_country_code' => 'SG',
                'country_code' => 'SG', 'metadata' => ['nium_region' => 'SG'],
            ]],
            'verified non-HK' => [[
                'status' => 'verified', 'registered_country_code' => 'SG',
                'country_code' => 'SG', 'metadata' => ['nium_region' => 'SG'],
            ]],
        ];
    }

    public static function ineligibleProviderAccounts(): array
    {
        return [
            'blocked account' => [['status' => 'blocked']],
            'unclear provider status' => [['provider_status' => 'pending']],
            'security conflict' => [['security_conflict_at' => '2026-10-29 00:00:00']],
            'unverified customer ID' => [['customer_id_verified_at' => null]],
            'unverified wallet ID' => [['wallet_id_verified_at' => null]],
            'missing customer ID' => [['external_customer_id' => null]],
            'missing wallet ID' => [['external_account_id' => null]],
        ];
    }

    #[DataProvider('ineligibleProviderAccounts')]
    public function test_verified_profile_still_requires_an_eligible_nium_account(array $account): void
    {
        [$user, , $payload] = $this->customer(['status' => 'verified']);
        $user->providerAccounts()->firstOrFail()->update($account);

        $this->postJson("/api/user/users/{$user->id}/transfers", $payload)->assertUnprocessable();

        $this->assertDatabaseCount('transfers', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('ineligibleProfiles')]
    public function test_ineligible_profile_cannot_create_a_transfer_or_send_money(array $profile): void
    {
        [$user, , $payload] = $this->customer($profile);

        $this->postJson("/api/user/users/{$user->id}/transfers", $payload)->assertUnprocessable();

        $this->assertDatabaseCount('transfers', 0);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    #[DataProvider('ineligibleProfiles')]
    public function test_ineligible_profile_cannot_submit_an_existing_draft(array $profile): void
    {
        [$user, $provider, $payload] = $this->customer($profile);
        $transfer = $user->transfers()->create([
            ...$payload,
            'transfer_no' => 'TRF-KYC-STATUS',
            'status' => 'draft',
            'fee_amount' => 0,
            'fee_currency' => 'USD',
        ]);

        try {
            app(NiumTransferService::class)->submitTransfer($provider, $transfer);
            $this->fail('An ineligible KYC profile must prevent submission.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Nium transfers currently require an HK corporate customer.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('transfers', 1);
        $this->assertSame('draft', $transfer->fresh()->status);
        $this->assertNull($transfer->fresh()->provider_operation_key);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    private function customer(array $profile): array
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium', 'name' => 'Nium', 'status' => 'active',
        ]);
        $user = User::factory()->create(['kyc_status' => 'verified']);
        $user->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        $user->kycProfile()->create([
            'status' => 'approved',
            'applicant_type' => 'business',
            'legal_name' => 'KYC Status Test Company',
            'business_name' => 'KYC Status Test Company',
            'registered_country_code' => 'HK',
            'country_code' => 'HK',
            'address_line1' => '1 Corporate Road',
            'city' => 'Hong Kong',
            'postal_code' => '999077',
            'metadata' => ['nium_region' => 'HK'],
            ...$profile,
        ]);
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'kyc-status-customer',
            'external_account_id' => 'kyc-status-wallet',
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
        $beneficiary = $user->beneficiaries()->create([
            'provider_id' => $provider->id,
            'external_beneficiary_id' => 'kyc-status-beneficiary',
            'beneficiary_type' => 'business',
            'full_name' => 'HK Test Payee',
            'country_code' => 'HK',
            'currency' => 'USD',
            'payout_method' => 'SWIFT',
            'status' => 'active',
            'raw_data' => ['nium' => ['payoutMethod' => 'SWIFT']],
        ]);
        $token = 'kyc-profile-status-test-token';
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'KYC profile status test',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);
        $this->withToken($token);

        return [$user, $provider, [
            'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id,
            'transfer_type' => 'payout',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'source_amount' => '5.00',
            'purpose_code' => 'IR01811',
            'reference_text' => 'INV-KYC-STATUS',
            'client_reference' => 'OW-KYC-STATUS',
        ]];
    }
}
