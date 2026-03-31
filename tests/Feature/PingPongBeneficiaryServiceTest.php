<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\PingPong\PingPongBeneficiaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PingPongBeneficiaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_beneficiary_maps_model_to_pingpong_recipient_payload(): void
    {
        Cache::flush();

        $provider = IntegrationProvider::query()->create([
            'code' => 'pingpong',
            'name' => 'PingPong',
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'status' => 'active',
            'metadata' => [
                'client_id' => 'managed-client-123',
            ],
        ]);

        $beneficiary = Beneficiary::query()->create([
            'user_id' => $user->id,
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
            'status' => 'pending',
        ]);

        config()->set('services.pingpong.base_url', 'https://test-gateway.pingpongx.com');
        config()->set('services.pingpong.auth', [
            'mode' => 'pingpong_access_token',
            'token_endpoint' => '/v2/token/get',
            'app_id' => 'pingpong-app-id',
            'app_secret' => 'pingpong-app-secret',
            'cache_key' => 'tests:pingpong:beneficiary:token',
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
                    'status' => 'AVAILABLE',
                ],
            ], 200),
        ]);

        $updated = app(PingPongBeneficiaryService::class)->createBeneficiary($provider, $beneficiary->fresh('user'));

        $this->assertSame('R202501080950209789', $updated->external_beneficiary_id);
        $this->assertSame('active', $updated->status);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://test-gateway.pingpongx.com/api/recipient/v2/create'
                && $request->hasHeader('Authorization', 'pingpong-access-token')
                && $request->hasHeader('on-behalf-of', 'managed-client-123')
                && $data['holder_type'] === 'PERSONAL'
                && $data['bank_detail']['account_no'] === '123456789'
                && $data['recipient_detail']['name'] === 'Jane Doe';
        });
    }
}
