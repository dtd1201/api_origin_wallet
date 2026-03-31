<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderHttpClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_request_supports_query_params_and_static_headers(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'TEST_PROVIDER',
            'name' => 'Test Provider',
            'status' => 'active',
        ]);

        config()->set('services.test_provider.base_url', 'https://provider.example.test');
        config()->set('services.test_provider.timeout', 30);

        Http::fake([
            'https://provider.example.test/accounts*' => Http::response(['data' => []], 200),
        ]);

        $client = new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'test_provider',
            headers: [
                'X-API-KEY' => 'demo-key',
            ],
        );

        $response = $client->get('/accounts', ['page' => 2, 'per_page' => 50]);

        $this->assertTrue($response->successful());

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && $request->hasHeader('X-API-KEY', 'demo-key')
                && $request->url() === 'https://provider.example.test/accounts?page=2&per_page=50';
        });
    }

    public function test_client_credentials_auth_fetches_and_caches_access_token(): void
    {
        Cache::flush();

        $provider = IntegrationProvider::query()->create([
            'code' => 'OAUTH_PROVIDER',
            'name' => 'OAuth Provider',
            'status' => 'active',
        ]);

        config()->set('services.oauth_provider.base_url', 'https://oauth.example.test');
        config()->set('services.oauth_provider.timeout', 30);
        config()->set('services.oauth_provider.auth', [
            'mode' => 'client_credentials',
            'token_endpoint' => '/oauth2/token',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'scope' => 'accounts:read',
            'credentials_in' => 'body',
            'cache_key' => 'tests:oauth_provider:token',
            'cache_buffer_seconds' => 30,
        ]);

        $tokenRequests = 0;
        $apiRequests = 0;

        Http::fake(function ($request) use (&$tokenRequests, &$apiRequests) {
            if ($request->url() === 'https://oauth.example.test/oauth2/token') {
                $tokenRequests++;

                return Http::response([
                    'access_token' => 'cached-access-token',
                    'expires_in' => 300,
                    'token_type' => 'Bearer',
                ], 200);
            }

            if (str_starts_with($request->url(), 'https://oauth.example.test/accounts')) {
                $apiRequests++;

                return Http::response(['data' => []], 200);
            }

            return Http::response([], 404);
        });

        $client = new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'oauth_provider',
        );

        $client->get('/accounts');
        $client->get('/accounts', ['page' => 2]);

        $this->assertSame(1, $tokenRequests);
        $this->assertSame(2, $apiRequests);

        Http::assertSent(function ($request): bool {
            if (! str_starts_with($request->url(), 'https://oauth.example.test/accounts')) {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer cached-access-token');
        });
    }

    public function test_pingpong_auth_fetches_and_caches_access_token_without_bearer_prefix(): void
    {
        Cache::flush();

        $provider = IntegrationProvider::query()->create([
            'code' => 'PINGPONG',
            'name' => 'PingPong',
            'status' => 'active',
        ]);

        config()->set('services.pingpong_provider.base_url', 'https://test-gateway.pingpongx.com');
        config()->set('services.pingpong_provider.timeout', 30);
        config()->set('services.pingpong_provider.auth', [
            'mode' => 'pingpong_access_token',
            'token_endpoint' => '/v2/token/get',
            'app_id' => 'pingpong-app-id',
            'app_secret' => 'pingpong-app-secret',
            'cache_key' => 'tests:pingpong_provider:token',
            'cache_buffer_seconds' => 300,
        ]);

        $tokenRequests = 0;
        $apiRequests = 0;

        Http::fake(function ($request) use (&$tokenRequests, &$apiRequests) {
            if (str_starts_with($request->url(), 'https://test-gateway.pingpongx.com/v2/token/get')) {
                $tokenRequests++;

                return Http::response([
                    'access_token' => 'pingpong-raw-access-token',
                    'expires_in' => 7200,
                ], 200);
            }

            if ($request->url() === 'https://test-gateway.pingpongx.com/api/recipient/v2/create') {
                $apiRequests++;

                return Http::response([
                    'code' => 'SUCCESS',
                    'data' => ['biz_id' => 'R202501080950209789'],
                ], 200);
            }

            return Http::response([], 404);
        });

        $client = new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'pingpong_provider',
            headers: [
                'on-behalf-of' => 'managed-account-123',
            ],
        );

        $client->post('/api/recipient/v2/create', ['holder_type' => 'PERSONAL']);
        $client->post('/api/recipient/v2/create', ['holder_type' => 'COMPANY']);

        $this->assertSame(1, $tokenRequests);
        $this->assertSame(2, $apiRequests);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://test-gateway.pingpongx.com/api/recipient/v2/create') {
                return false;
            }

            return $request->hasHeader('Authorization', 'pingpong-raw-access-token')
                && ! $request->hasHeader('Authorization', 'Bearer pingpong-raw-access-token')
                && $request->hasHeader('on-behalf-of', 'managed-account-123');
        });
    }
}
