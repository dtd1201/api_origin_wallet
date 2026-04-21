<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Tazapay\TazapayBeneficiaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TazapayBeneficiaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_beneficiary_maps_model_to_tazapay_payload(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'tazapay',
            'name' => 'Tazapay',
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_account_id' => 'acc_connected_001',
            'status' => 'active',
            'metadata' => [
                'tz_account_id' => 'acc_connected_001',
            ],
        ]);

        $beneficiary = Beneficiary::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.test',
            'phone' => '9362987920',
            'country_code' => 'IN',
            'currency' => 'INR',
            'bank_name' => 'HDFC',
            'account_number' => '1234567890',
            'swift_bic' => 'HDFCINBB',
            'address_line1' => 'Test',
            'address_line2' => 'Block A',
            'city' => 'Delhi',
            'state' => 'Delhi',
            'postal_code' => '110017',
            'status' => 'pending',
            'raw_data' => [
                'tazapay' => [
                    'phone' => ['calling_code' => '91'],
                    'bank' => [
                        'firc_required' => true,
                        'purpose_code' => 'PYR003',
                        'transfer_type' => 'local',
                    ],
                    'bank_codes' => [
                        'ifsc_code' => 'HDFC0001234',
                    ],
                ],
            ],
        ]);

        config()->set('services.tazapay.base_url', 'https://service-sandbox.tazapay.com');
        config()->set('services.tazapay.auth', [
            'mode' => 'basic_auth',
            'username' => 'tzp_key',
            'password' => 'tzp_secret',
        ]);

        Http::fake([
            'https://service-sandbox.tazapay.com/v3/beneficiary' => Http::response([
                'status' => 'success',
                'message' => '',
                'data' => [
                    'id' => 'bnf_test_123',
                    'status' => 'active',
                    'destination_details' => [
                        'type' => 'bank',
                        'bank' => [
                            'account_number' => '1234567890',
                            'bank_name' => 'HDFC',
                            'country' => 'IN',
                            'currency' => 'INR',
                            'bank_codes' => [
                                'ifsc_code' => 'HDFC0001234',
                                'swift_code' => 'HDFCINBB',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $updated = app(TazapayBeneficiaryService::class)->createBeneficiary($provider, $beneficiary->fresh('user'));

        $this->assertSame('bnf_test_123', $updated->external_beneficiary_id);
        $this->assertSame('active', $updated->status);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://service-sandbox.tazapay.com/v3/beneficiary'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('tzp_key:tzp_secret'))
                && $request->hasHeader('tz-account-id', 'acc_connected_001')
                && $data['name'] === 'Jane Doe'
                && $data['destination_details']['type'] === 'bank'
                && $data['destination_details']['bank']['account_number'] === '1234567890'
                && $data['destination_details']['bank']['bank_codes']['ifsc_code'] === 'HDFC0001234';
        });
    }
}
