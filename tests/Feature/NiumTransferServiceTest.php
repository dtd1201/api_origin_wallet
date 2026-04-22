<?php

namespace Tests\Feature;

use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Nium\NiumTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NiumTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_transfer_creates_nium_remittance_and_updates_transfer(): void
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
                ],
            ],
        ]);

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
                && $data['payout']['source_currency'] === 'USD'
                && $data['purposeCode'] === 'IR001';
        });
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
                'audit' => [
                    [
                        'status' => 'PENDING',
                    ],
                    [
                        'status' => 'COMPLETED',
                        'paymentReferenceNumber' => 'pay_123',
                        'dateTime' => now()->toISOString(),
                    ],
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
}
