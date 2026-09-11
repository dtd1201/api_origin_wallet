<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class NiumPurposeCodeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('services.nium', array_replace_recursive((array) config('services.nium'), [
            'base_url' => 'https://gateway.nium.test',
            'client_id' => 'purpose-client',
            'auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'purpose-secret'],
            'webhook' => ['static_header_name' => 'x-partner-key', 'static_header_value' => 'partner-secret'],
            'health_endpoint' => '/api/v1/client/{clientHashId}',
            'customer_create_endpoint' => '/api/v1/client/{clientHashId}/customer',
            'customer_list_endpoint' => '/api/v1/client/{clientHashId}/customers',
            'customer_get_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}',
            'purpose_codes_endpoint' => '/api/v1/remittance/purposeCodes',
            'purpose_codes_cache_seconds' => 3600,
        ]));
    }

    public function test_returns_provider_confirmed_supported_purpose_and_caches_it(): void
    {
        [$user, $token] = $this->customer();
        Http::fake(['*' => Http::response([
            ['description' => 'Medical Treatment', 'purposeCode' => 'IR004'],
            ['description' => 'Business payment', 'purposeCode' => 'IR01811'],
        ])]);

        $expected = ['data' => [
            ['code' => 'IR004', 'label' => 'Medical Treatment'],
            ['code' => 'IR01811', 'label' => 'Business payment'],
        ]];
        $this->withToken($token)->getJson("/api/user/users/{$user->id}/transfers/nium-purpose-codes")
            ->assertOk()
            ->assertExactJson($expected);
        $this->withToken($token)->getJson("/api/user/users/{$user->id}/transfers/nium-purpose-codes")
            ->assertOk()
            ->assertExactJson($expected);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.nium.test/api/v1/remittance/purposeCodes'
            && $request->hasHeader('x-api-key', 'purpose-secret'));
    }

    public function test_provider_failure_is_sanitized_and_does_not_expose_credentials(): void
    {
        [$user, $token] = $this->customer();
        Http::fake(['*' => Http::response(['message' => 'purpose-secret provider internals'], 503)]);

        $response = $this->withToken($token)
            ->getJson("/api/user/users/{$user->id}/transfers/nium-purpose-codes")
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Payment purposes are temporarily unavailable.']);

        $this->assertStringNotContainsString('purpose-secret', $response->getContent());
    }

    private function customer(): array
    {
        $provider = IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']);
        $user = User::factory()->create();
        $user->profile()->create(['user_type' => 'business', 'country_code' => 'HK']);
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'purpose-customer',
            'external_account_id' => 'purpose-wallet',
            'status' => 'active',
            'provider_status' => 'clear',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
        ]);
        $plainToken = Str::random(80);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'purpose-test',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return [$user, $plainToken];
    }
}
