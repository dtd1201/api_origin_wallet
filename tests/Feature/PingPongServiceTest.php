<?php

namespace Tests\Feature;

use App\Services\PingPong\PingPongService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PingPongServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_recipient_uses_configured_endpoint_and_on_behalf_of_header(): void
    {
        Cache::flush();

        config()->set('services.pingpong.base_url', 'https://test-gateway.pingpongx.com');
        config()->set('services.pingpong.auth', [
            'mode' => 'pingpong_access_token',
            'token_endpoint' => '/v2/token/get',
            'app_id' => 'pingpong-app-id',
            'app_secret' => 'pingpong-app-secret',
            'cache_key' => 'tests:pingpong:token',
            'cache_buffer_seconds' => 300,
        ]);
        config()->set('services.pingpong.recipient_create_endpoint', '/api/recipient/v2/create');

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

        $response = app(PingPongService::class)->createRecipient(
            payload: [
                'holder_type' => 'PERSONAL',
                'account_type' => 'RECIPIENT_BANK',
            ],
            onBehalfOf: 'managed-account-123',
        );

        $this->assertTrue($response->successful());
        $this->assertSame('SUCCESS', $response->json('code'));

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://test-gateway.pingpongx.com/api/recipient/v2/create'
                && $request->hasHeader('Authorization', 'pingpong-access-token')
                && $request->hasHeader('on-behalf-of', 'managed-account-123');
        });

        $this->assertDatabaseHas('integration_providers', [
            'code' => 'pingpong',
            'name' => 'PingPong',
        ]);
    }
}
