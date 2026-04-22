<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Nium\NiumBeneficiaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class NiumBeneficiaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_beneficiary_maps_model_to_nium_payload(): void
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
            'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.test',
            'phone' => '15551234567',
            'country_code' => 'IN',
            'currency' => 'INR',
            'bank_name' => 'HDFC',
            'bank_code' => 'HDFC0001234',
            'account_number' => '1234567890',
            'swift_bic' => 'HDFCINBB',
            'address_line1' => '1 Main St',
            'city' => 'Delhi',
            'state' => 'Delhi',
            'postal_code' => '110017',
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
            'https://gateway.sandbox.nium.com/api/v2/client/client_hash_123/customer/cust_hash_123/beneficiaries' => Http::response([
                'beneficiaryHashId' => 'bnf_hash_123',
                'status' => 'ACTIVE',
            ], 200),
        ]);

        $updated = app(NiumBeneficiaryService::class)->createBeneficiary($provider, $beneficiary->fresh('user'));

        $this->assertSame('bnf_hash_123', $updated->external_beneficiary_id);
        $this->assertSame('active', $updated->status);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://gateway.sandbox.nium.com/api/v2/client/client_hash_123/customer/cust_hash_123/beneficiaries'
                && $request->hasHeader('x-api-key', 'nium-api-key')
                && $data['beneficiary']['name'] === 'Jane Doe'
                && $data['destinationCurrency'] === 'INR'
                && $data['routingInfo'][0]['type'] === 'SWIFT';
        });
    }

    public function test_create_beneficiary_can_verify_account_before_create_when_requested(): void
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
            'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.test',
            'phone' => '15551234567',
            'country_code' => 'IN',
            'currency' => 'INR',
            'bank_name' => 'HDFC',
            'bank_code' => 'HDFC0001234',
            'account_number' => '1234567890',
            'swift_bic' => 'HDFCINBB',
            'address_line1' => '1 Main St',
            'city' => 'Delhi',
            'state' => 'Delhi',
            'postal_code' => '110017',
            'status' => 'pending',
            'raw_data' => [
                'nium' => [
                    'verify_before_create' => true,
                    'account_verification' => [
                        'routingInfo' => [
                            ['type' => 'IFSC', 'value' => 'HDFC0001234'],
                        ],
                    ],
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
            'https://gateway.sandbox.nium.com/api/v1/client/client_hash_123/customer/cust_hash_123/accountVerification' => Http::response([], 200),
            'https://gateway.sandbox.nium.com/api/v2/client/client_hash_123/customer/cust_hash_123/beneficiaries' => Http::response([
                'beneficiaryHashId' => 'bnf_hash_456',
                'status' => 'ACTIVE',
            ], 200),
        ]);

        $updated = app(NiumBeneficiaryService::class)->createBeneficiary($provider, $beneficiary->fresh('user'));

        $this->assertSame('bnf_hash_456', $updated->external_beneficiary_id);
        $this->assertArrayHasKey('verification_response', $updated->raw_data);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://gateway.sandbox.nium.com/api/v1/client/client_hash_123/customer/cust_hash_123/accountVerification'
                && $request->hasHeader('x-api-key', 'nium-api-key')
                && $data['payoutMethod'] === 'LOCAL'
                && $data['routingInfo'][0]['type'] === 'IFSC';
        });
    }

    public function test_update_beneficiary_requires_explicit_endpoint_configuration(): void
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
            'status' => 'active',
        ]);

        $beneficiary = Beneficiary::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe',
            'country_code' => 'IN',
            'currency' => 'INR',
            'account_number' => '1234567890',
            'external_beneficiary_id' => 'bnf_hash_123',
            'status' => 'active',
        ]);

        config()->set('services.nium.beneficiary_update_endpoint', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nium beneficiary update is not enabled.');

        app(NiumBeneficiaryService::class)->updateBeneficiary($provider, $beneficiary->fresh('user'));
    }

    public function test_delete_beneficiary_requires_explicit_endpoint_configuration(): void
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
            'status' => 'active',
        ]);

        $beneficiary = Beneficiary::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe',
            'country_code' => 'IN',
            'currency' => 'INR',
            'account_number' => '1234567890',
            'external_beneficiary_id' => 'bnf_hash_123',
            'status' => 'active',
        ]);

        config()->set('services.nium.beneficiary_delete_endpoint', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nium beneficiary delete is not enabled.');

        app(NiumBeneficiaryService::class)->deleteBeneficiary($provider, $beneficiary->fresh('user'));
    }
}
