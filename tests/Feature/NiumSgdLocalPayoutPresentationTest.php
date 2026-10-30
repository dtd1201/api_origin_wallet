<?php

namespace Tests\Feature;

use App\Http\Resources\User\BeneficiaryResource;
use App\Http\Resources\User\TransferResource;
use App\Models\Beneficiary;
use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Nium\NiumPayoutFxLockContract;
use App\Services\Nium\NiumPayoutFxLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NiumSgdLocalPayoutPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('services.nium.client_id', 'test-client');
        config()->set('services.nium.base_url', 'https://nium.test');
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    private function transfer(bool $withLock): Transfer
    {
        $provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $user = User::factory()->create(['kyc_status' => 'verified']);
        $user->providerAccounts()->create([
            'provider_id' => $provider->id, 'external_customer_id' => 'test-customer', 'external_account_id' => 'test-wallet',
            'status' => 'active', 'provider_status' => 'clear', 'reconciliation_status' => 'reconciled',
            'customer_id_verified_at' => now(), 'wallet_id_verified_at' => now(),
        ]);
        $beneficiary = Beneficiary::query()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id, 'full_name' => 'Test Individual',
            'beneficiary_type' => 'personal', 'country_code' => 'SG', 'currency' => 'SGD', 'payout_method' => 'LOCAL', 'status' => 'active',
            'raw_data' => ['payout_admission' => ['allowed' => true]],
        ]);
        $transfer = Transfer::query()->create([
            'user_id' => $user->id, 'provider_id' => $provider->id, 'beneficiary_id' => $beneficiary->id,
            'transfer_no' => 'TEST-SGD', 'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'SGD',
            'source_amount' => '10', 'target_amount' => '999', 'fx_rate' => $withLock ? '1.32' : null,
            'fee_amount' => 0, 'fee_currency' => 'USD', 'status' => 'draft',
        ]);
        if ($withLock) {
            $contract = NiumPayoutFxLockContract::parse([
                'auditId' => '112', 'exchangeRate' => '1.32', 'expiresAt' => now()->addMinutes(10)->toISOString(), 'status' => 'ACTIVE',
                'sourceAmount' => '10', 'destinationAmount' => '13.15', 'sourceCurrency' => 'USD', 'destinationCurrency' => 'SGD',
            ], 'USD', 'SGD', '10');
            // An isolated persisted fixture, not a call to createLock or a provider.
            $quote = FxQuote::query()->create([
                'user_id' => $user->id, 'provider_id' => $provider->id, 'source_currency' => 'USD', 'target_currency' => 'SGD',
                'source_amount' => '10', 'target_amount' => '999', 'net_rate' => '1.32', 'fee_amount' => 0,
                'quote_ref' => '112', 'expires_at' => $contract['expires_at'],
                'raw_data' => ['provider_fx_type' => 'payout_fx_lock', 'audit_id' => '112',
                    'payout_fx_lock_contract' => $contract, 'provider_context' => app(NiumPayoutFxLockService::class)->context($provider, $user)],
            ]);
            $transfer->update(['fx_quote_id' => $quote->id]);
        }

        return $transfer->fresh(['beneficiary', 'provider', 'user', 'fxQuote']);
    }

    public function test_backend_admission_remains_false_with_global_fx_flag_on_or_off(): void
    {
        $transfer = $this->transfer(false);
        foreach ([false, true] as $enabled) {
            config()->set('services.nium.payout_fx_enabled', $enabled);
            $payload = (new BeneficiaryResource($transfer->beneficiary))->resolve();
            $this->assertFalse($payload['payout_admission']['allowed']);
            $this->assertSame('financial_terms_unverified', $payload['payout_admission']['reason']);
            $this->assertSame($transfer->beneficiary_id, $payload['payout_admission']['beneficiary_id']);
            $this->assertSame('LOCAL', $payload['payout_admission']['payout_method']);
        }
    }

    public function test_zero_fee_and_calculated_target_placeholders_never_become_authoritative_amounts(): void
    {
        $transfer = $this->transfer(false);
        $payload = (new TransferResource($transfer))->resolve();
        $amounts = $payload['payout_amounts'];
        $this->assertNull($payload['fee_amount']);
        $this->assertNull($payload['fee_currency']);
        $this->assertNull($payload['target_amount']);
        $this->assertNull($payload['fx_rate']);
        $this->assertSame('10.00000000', $amounts['source_amount']);
        foreach (['total_wallet_debit', 'fx_rate', 'fx_lock_expires_at', 'conversion_amount', 'transfer_fee', 'beneficiary_credit'] as $field) {
            $this->assertNull($amounts[$field], $field);
        }
    }

    public function test_bound_provider_rate_and_conversion_are_separate_from_fees_and_final_credit(): void
    {
        $transfer = $this->transfer(true);
        $payload = (new TransferResource($transfer))->resolve();
        $this->assertSame('1.32', $payload['payout_amounts']['fx_rate']);
        $this->assertSame($transfer->fxQuote->expires_at->toISOString(), $payload['payout_amounts']['fx_lock_expires_at']);
        $this->assertSame('13.15', $payload['payout_amounts']['conversion_amount']);
        $this->assertNull($payload['payout_amounts']['total_wallet_debit']);
        $this->assertNull($payload['payout_amounts']['transfer_fee']);
        $this->assertNull($payload['payout_amounts']['beneficiary_credit']);
        $this->assertArrayNotHasKey('provider_context', $payload);
        $this->assertArrayNotHasKey('raw_data', $payload);
    }

    public function test_changed_wallet_binding_makes_fx_values_unavailable(): void
    {
        $transfer = $this->transfer(true);
        $transfer->user->providerAccounts()->update(['external_account_id' => 'different-wallet']);
        $amounts = (new TransferResource($transfer))->resolve()['payout_amounts'];
        $this->assertNull($amounts['fx_rate']);
        $this->assertNull($amounts['fx_lock_expires_at']);
        $this->assertNull($amounts['conversion_amount']);
    }

    public function test_expired_lock_never_supplies_review_amounts(): void
    {
        $transfer = $this->transfer(true);
        $transfer->fxQuote->update(['expires_at' => now()->subSecond()]);
        $amounts = (new TransferResource($transfer))->resolve()['payout_amounts'];
        $this->assertNull($amounts['fx_rate']);
        $this->assertNull($amounts['conversion_amount']);
    }

    public function test_hk_resources_do_not_change_shape(): void
    {
        $transfer = $this->transfer(false);
        $transfer->beneficiary->update(['country_code' => 'HK', 'currency' => 'USD', 'payout_method' => 'SWIFT']);
        $transfer->update(['target_currency' => 'USD']);
        $this->assertArrayNotHasKey('payout_admission', (new BeneficiaryResource($transfer->beneficiary))->resolve());
        $this->assertArrayNotHasKey('payout_amounts', (new TransferResource($transfer))->resolve());
    }
}
