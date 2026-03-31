<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PingPongTransferStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_sync_pingpong_transfer_status_via_api(): void
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

        $beneficiary = Beneficiary::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_beneficiary_id' => 'R202501080950209789',
            'beneficiary_type' => 'personal',
            'full_name' => 'Jane Doe',
            'country_code' => 'US',
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-ABC123456789',
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'beneficiary_id' => $beneficiary->id,
            'external_payment_id' => '202501092053115499258',
            'transfer_type' => 'bank',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'source_amount' => 125.50,
            'target_amount' => 125.50,
            'status' => 'pending',
        ]);

        config()->set('services.pingpong.base_url', 'https://test-gateway.pingpongx.com');
        config()->set('services.pingpong.auth', [
            'mode' => 'pingpong_access_token',
            'token_endpoint' => '/v2/token/get',
            'app_id' => 'pingpong-app-id',
            'app_secret' => 'pingpong-app-secret',
            'cache_key' => 'tests:pingpong:transfer-status-api:token',
            'cache_buffer_seconds' => 300,
        ]);

        Http::fake([
            'https://test-gateway.pingpongx.com/v2/token/get*' => Http::response([
                'access_token' => 'pingpong-access-token',
                'expires_in' => 7200,
            ], 200),
            'https://test-gateway.pingpongx.com/api/payout/v2/query*' => Http::response([
                'code' => 'SUCCESS',
                'data' => [
                    'order_id' => '202501092053115499258',
                    'partner_order_id' => 'TRF-ABC123456789',
                    'status' => 'SUCCESS',
                    'finished' => 1736427191000,
                ],
            ], 200),
        ]);

        $response = $this->withToken($this->issueTokenFor($user))
            ->postJson("/api/user/users/{$user->id}/transfers/{$transfer->id}/sync-status");

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Transfer status synced successfully.')
            ->assertJsonPath('transfer.status', 'completed');

        $this->assertDatabaseHas('transfers', [
            'id' => $transfer->id,
            'status' => 'completed',
            'external_payment_id' => '202501092053115499258',
        ]);
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
