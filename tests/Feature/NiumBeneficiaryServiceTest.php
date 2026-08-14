<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Nium\NiumBeneficiaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NiumBeneficiaryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-partner-key');
        config()->set('services.nium.account_verification_enabled', true);
        config()->set('services.nium.beneficiary_update_enabled', true);
        config()->set('services.nium.beneficiary_delete_enabled', true);
        config()->set('services.nium.supported_corridors', [[
            'destinationCountry' => 'IN',
            'destinationCurrency' => 'INR',
            'payoutMethod' => 'LOCAL',
            'beneficiaryAccountType' => 'INDIVIDUAL',
            'customerType' => 'INDIVIDUAL',
            'routingCodeType' => ['IFSC', 'SWIFT'],
        ]]);
    }

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
            'provider_status' => 'clear',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
            'provider_ids_verified_at' => now(),
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
            'raw_data' => ['nium' => [
                'payoutMethod' => 'LOCAL',
                'bankCodeType' => 'IFSC',
                'schema_sha256' => hash('sha256', 'factual-test-schema'),
                'schema_approval' => $this->schemaApproval(['routingCodeType1', 'routingCodeValue1']),
            ]],
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

        $updated = app(NiumBeneficiaryService::class)->createBeneficiary($provider, $this->bindPreparation($beneficiary));

        $this->assertSame('bnf_hash_123', $updated->external_beneficiary_id);
        $this->assertSame('active', $updated->status);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://gateway.sandbox.nium.com/api/v2/client/client_hash_123/customer/cust_hash_123/beneficiaries'
                && $request->hasHeader('x-api-key', 'nium-api-key')
                && $data['beneficiaryName'] === 'Jane Doe'
                && $data['destinationCurrency'] === 'INR'
                && $data['routingCodeType1'] === 'SWIFT'
                && $data['routingCodeValue1'] === 'HDFCINBB';
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
            'provider_status' => 'clear',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
            'provider_ids_verified_at' => now(),
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
                    'payoutMethod' => 'LOCAL',
                    'bankCodeType' => 'IFSC',
                    'schema_sha256' => hash('sha256', 'factual-test-schema'),
                    'schema_approval' => $this->schemaApproval([]),
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

        $updated = app(NiumBeneficiaryService::class)->createBeneficiary($provider, $this->bindPreparation($beneficiary));

        $this->assertSame('bnf_hash_456', $updated->external_beneficiary_id);
        $this->assertArrayNotHasKey('verification_response', $updated->raw_data ?? []);
        $this->assertArrayNotHasKey('verification_request', $updated->raw_data ?? []);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://gateway.sandbox.nium.com/api/v1/client/client_hash_123/customer/cust_hash_123/accountVerification'
                && $request->hasHeader('x-api-key', 'nium-api-key')
                && $data['payoutMethod'] === 'LOCAL'
                && $data['routingInfo'][0]['type'] === 'IFSC';
        });
    }

    public function test_supported_corridor_v3_is_queried_with_exact_dimensions_and_cached(): void
    {
        Cache::flush();
        config()->set('services.nium.supported_corridors', []);
        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.com');
        config()->set('services.nium.client_id', 'client_hash_123');
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => 'nium-api-key',
        ]);

        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $user->profile()->create(['user_type' => 'individual']);
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

        $beneficiaries = collect(['Jane One', 'Jane Two'])->map(fn (string $name): Beneficiary => Beneficiary::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'beneficiary_type' => 'personal',
            'full_name' => $name,
            'country_code' => 'IN',
            'currency' => 'INR',
            'account_number' => '1234567890',
            'bank_code' => 'HDFC0001234',
            'raw_data' => ['nium' => [
                'payoutMethod' => 'LOCAL',
                'bankCodeType' => 'IFSC',
                'schema_sha256' => hash('sha256', 'factual-test-schema'),
                'schema_approval' => $this->schemaApproval([]),
            ]],
            'status' => 'pending',
        ]));

        $corridorRequests = 0;
        Http::fake(function ($request) use (&$corridorRequests) {
            if (str_contains($request->url(), '/api/v3/client/client_hash_123/supportedCorridors')) {
                $corridorRequests++;

                return Http::response([
                    'content' => [[
                        'destinationCountry' => 'IN',
                        'destinationCurrency' => 'INR',
                        'payoutMethod' => 'LOCAL',
                        'beneficiaryAccountType' => 'INDIVIDUAL',
                        'customerType' => 'INDIVIDUAL',
                        'routingCodeType' => 'IFSC',
                        'accountVerification' => 'SUPPORTED',
                        'mandatoryDataRequirement' => ['Beneficiary Account Number', 'Routing Code Type 1 - IFSC'],
                    ]],
                    'totalElements' => 1,
                    'totalPages' => 1,
                ]);
            }

            return Http::response([
                'beneficiaryHashId' => 'bnf_'.substr(md5($request->data()['beneficiaryName']), 0, 8),
                'status' => 'ACTIVE',
            ]);
        });

        foreach ($beneficiaries as $beneficiary) {
            app(NiumBeneficiaryService::class)->createBeneficiary($provider, $this->bindPreparation($beneficiary));
        }

        $this->assertSame(1, $corridorRequests);
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/api/v3/client/client_hash_123/supportedCorridors')) {
                return false;
            }

            return $request['destinationCountry'] === 'IN'
                && $request['destinationCurrency'] === 'INR'
                && $request['payoutMethod'] === 'LOCAL'
                && $request['beneficiaryAccountType'] === 'INDIVIDUAL'
                && $request['customerType'] === 'INDIVIDUAL'
                && (int) $request['page'] === 0
                && (int) $request['size'] === 500
                && $request['order'] === 'ASC';
        });
    }

    public function test_update_beneficiary_uses_official_v2_endpoint(): void
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
            'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe',
            'country_code' => 'IN',
            'currency' => 'INR',
            'account_number' => '1234567890',
            'external_beneficiary_id' => 'bnf_hash_123',
            'status' => 'active',
            'raw_data' => ['nium' => [
                'payoutMethod' => 'LOCAL',
                'schema_sha256' => hash('sha256', 'factual-test-schema'),
                'schema_approval' => $this->schemaApproval(['beneficiaryAccountNumber']),
            ]],
        ]);

        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.com');
        config()->set('services.nium.client_id', 'client_hash_123');
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => 'nium-api-key',
        ]);

        Http::fake([
            'https://gateway.sandbox.nium.com/api/v2/client/client_hash_123/customer/cust_hash_123/beneficiaries/bnf_hash_123' => Http::response([
                'beneficiaryHashId' => 'bnf_hash_123',
                'status' => 'ACTIVE',
            ], 200),
        ]);

        $updated = app(NiumBeneficiaryService::class)->updateBeneficiary($provider, $this->bindPreparation($beneficiary));

        $this->assertSame('active', $updated->status);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->method() === 'PUT'
                && $request->url() === 'https://gateway.sandbox.nium.com/api/v2/client/client_hash_123/customer/cust_hash_123/beneficiaries/bnf_hash_123'
                && $data['beneficiaryName'] === 'Jane Doe'
                && $data['beneficiaryAccountNumber'] === '1234567890';
        });
    }

    public function test_delete_beneficiary_uses_official_v1_endpoint(): void
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
            'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe',
            'country_code' => 'IN',
            'currency' => 'INR',
            'account_number' => '1234567890',
            'external_beneficiary_id' => 'bnf_hash_123',
            'status' => 'active',
        ]);

        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.com');
        config()->set('services.nium.client_id', 'client_hash_123');
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => 'nium-api-key',
        ]);

        Http::fake([
            'https://gateway.sandbox.nium.com/api/v1/client/client_hash_123/customer/cust_hash_123/beneficiaries/bnf_hash_123' => Http::response('', 200),
        ]);

        app(NiumBeneficiaryService::class)->deleteBeneficiary($provider, $beneficiary->fresh('user'));

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.sandbox.nium.com/api/v1/client/client_hash_123/customer/cust_hash_123/beneficiaries/bnf_hash_123');
    }

    private function schemaApproval(array $approvedFields, array $requiredFields = []): array
    {
        return [
            'schema_sha256' => hash('sha256', 'factual-test-schema'),
            'beneficiary_preparation_sha256' => str_repeat('0', 64),
            'schema_length' => strlen('factual-test-schema'),
            'currency_code' => 'INR',
            'destination_country' => 'IN',
            'payout_method' => 'LOCAL',
            'approved_fields' => $approvedFields,
            'required_fields' => $requiredFields,
            'reviewed_at' => now()->toISOString(),
            'review_source' => 'human_reviewed_factual_nium_schema',
        ];
    }

    private function bindPreparation(Beneficiary $beneficiary): Beneficiary
    {
        $beneficiary = $beneficiary->fresh('user.profile');
        $raw = (array) $beneficiary->raw_data;
        $raw['nium']['schema_approval']['beneficiary_preparation_sha256'] = app(NiumBeneficiaryService::class)->preparationFingerprint($beneficiary);
        $beneficiary->update(['raw_data' => $raw]);

        return $beneficiary->fresh('user.profile');
    }
}
