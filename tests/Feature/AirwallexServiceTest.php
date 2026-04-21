<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Airwallex\AirwallexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AirwallexServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_request_uses_on_behalf_of_and_sca_token_from_completion_payload(): void
    {
        Cache::flush();

        $provider = IntegrationProvider::query()->create([
            'code' => 'airwallex',
            'name' => 'Airwallex',
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_account_id' => 'acct_external_fallback',
            'status' => 'active',
            'metadata' => [
                'completion_payload' => [
                    'open_id' => 'acct_completion_payload',
                    'sca_token' => 'sca-token-from-payload',
                ],
            ],
        ]);

        config()->set('services.airwallex.base_url', 'https://api-demo.airwallex.com');
        config()->set('services.airwallex.api_version', '2024-09-27');
        config()->set('services.airwallex.timeout', 30);
        config()->set('services.airwallex.x_api_key', 'airwallex-api-key');
        config()->set('services.airwallex.auth', [
            'mode' => 'airwallex_access_token',
            'token_endpoint' => '/api/v1/authentication/login',
            'client_id' => 'airwallex-client-id',
            'cache_key' => 'tests:airwallex:token',
            'cache_buffer_seconds' => 30,
        ]);

        Http::fake([
            'https://api-demo.airwallex.com/api/v1/authentication/login' => Http::response([
                'token' => 'airwallex-access-token',
                'expires_at' => now()->addMinutes(20)->toISOString(),
            ], 200),
            'https://api-demo.airwallex.com/api/v1/global_accounts' => Http::response([
                'items' => [],
            ], 200),
        ]);

        $response = app(AirwallexService::class)->get('/api/v1/global_accounts', user: $user->fresh('providerAccounts'));

        $this->assertTrue($response->successful());

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api-demo.airwallex.com/api/v1/global_accounts'
                && $request->hasHeader('Authorization', 'Bearer airwallex-access-token')
                && $request->hasHeader('x-api-version', '2024-09-27')
                && $request->hasHeader('x-on-behalf-of', 'acct_completion_payload')
                && $request->hasHeader('x-sca-token', 'sca-token-from-payload');
        });
    }
}
