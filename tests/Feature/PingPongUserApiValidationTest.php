<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PingPongUserApiValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_pingpong_beneficiary_with_nested_pingpong_raw_data(): void
    {
        Cache::flush();

        $user = User::factory()->create();
        $user->profile()->create([
            'user_type' => 'business',
        ]);

        $provider = IntegrationProvider::query()->create([
            'code' => 'pingpong',
            'name' => 'PingPong',
            'status' => 'active',
        ]);

        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'status' => 'active',
            'metadata' => [
                'client_id' => 'managed-client-123',
            ],
        ]);

        config()->set('services.pingpong.base_url', 'https://test-gateway.pingpongx.com');
        config()->set('services.pingpong.auth', [
            'mode' => 'pingpong_access_token',
            'token_endpoint' => '/v2/token/get',
            'app_id' => 'pingpong-app-id',
            'app_secret' => 'pingpong-app-secret',
            'cache_key' => 'tests:pingpong:user-api:beneficiary:token',
            'cache_buffer_seconds' => 300,
        ]);

        Http::fake([
            'https://test-gateway.pingpongx.com/v2/token/get*' => Http::response([
                'access_token' => 'pingpong-access-token',
                'expires_in' => 7200,
            ], 200),
            'https://test-gateway.pingpongx.com/api/recipient/v2/create' => Http::response([
                'code' => 'SUCCESS',
                'data' => [
                    'biz_id' => 'R202501080950209789',
                    'status' => 'PENDING',
                ],
            ], 200),
        ]);

        $response = $this->withToken($this->issueTokenFor($user))
            ->postJson("/api/user/users/{$user->id}/beneficiaries", [
                'provider_id' => $provider->id,
                'beneficiary_type' => 'personal',
                'full_name' => 'Jane Doe',
                'email' => 'jane@example.test',
                'phone' => '15551234567',
                'country_code' => 'US',
                'currency' => 'USD',
                'bank_name' => 'Chase',
                'bank_code' => '021000021',
                'branch_code' => '001',
                'account_number' => '123456789',
                'swift_bic' => 'CHASUS33',
                'address_line1' => '1 Main St',
                'city' => 'New York',
                'state' => 'NY',
                'postal_code' => '10001',
                'raw_data' => [
                    'pingpong' => [
                        'document' => 'doc-file-id',
                        'bank_detail' => [
                            'account_type' => 'CHECKING',
                            'routing_no' => '110000000',
                        ],
                        'recipient_detail' => [
                            'recipient_type' => '10',
                            'phone_prefix' => '+1',
                        ],
                    ],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('external_beneficiary_id', 'R202501080950209789')
            ->assertJsonPath('raw_data.pingpong.document', 'doc-file-id')
            ->assertJsonPath('raw_data.pingpong.bank_detail.routing_no', '110000000');
    }

    public function test_user_can_store_transfer_with_pingpong_override_fields(): void
    {
        $user = User::factory()->create();
        $user->profile()->create([
            'user_type' => 'business',
        ]);

        $provider = IntegrationProvider::query()->create([
            'code' => 'pingpong',
            'name' => 'PingPong',
            'status' => 'active',
        ]);

        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'status' => 'active',
        ]);

        $beneficiary = $user->beneficiaries()->create([
            'provider_id' => $provider->id,
            'external_beneficiary_id' => 'R202501080950209789',
            'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe',
            'country_code' => 'US',
            'currency' => 'USD',
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

        $response = $this->withToken($this->issueTokenFor($user))
            ->postJson("/api/user/users/{$user->id}/transfers", [
                'provider_id' => $provider->id,
                'beneficiary_id' => $beneficiary->id,
                'transfer_type' => 'bank',
                'source_currency' => 'USD',
                'target_currency' => 'INR',
                'source_amount' => 200,
                'target_amount' => 16600,
                'purpose_code' => '1001',
                'reference_text' => 'Invoice 42',
                'raw_data' => [
                    'rate_id' => 'RATE-123',
                    'pingpong' => [
                        'payment_method' => 'CROSS-BORDER',
                        'clearing_network' => 'SWIFT',
                        'fee_bear' => 'OUR',
                        'document' => 'doc-file-id',
                    ],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('raw_data.rate_id', 'RATE-123')
            ->assertJsonPath('raw_data.pingpong.payment_method', 'CROSS-BORDER')
            ->assertJsonPath('raw_data.pingpong.clearing_network', 'SWIFT')
            ->assertJsonPath('raw_data.pingpong.fee_bear', 'OUR');
    }

    private function issueTokenFor(User $user): string
    {
        $plainToken = Str::random(80);

        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test-token',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return $plainToken;
    }
}
