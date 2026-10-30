<?php

namespace Tests\Concerns;

use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;

trait CreatesNiumRemittanceSafetyFixtures
{
    protected function makeSubmittableTransfer(array $overrides = []): array
    {
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-partner-key');
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

    protected function createApprovedHkCorporateKycProfile(User $user): void
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
