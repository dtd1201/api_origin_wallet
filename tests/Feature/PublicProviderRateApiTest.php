<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicProviderRateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_provider_rates_return_all_providers_and_live_quote_without_auth(): void
    {
        Cache::flush();

        IntegrationProvider::query()->create([
            'code' => 'airwallex',
            'name' => 'Airwallex',
            'status' => 'active',
        ]);

        IntegrationProvider::query()->create([
            'code' => 'pingpong',
            'name' => 'PingPong',
            'status' => 'active',
        ]);

        config()->set('services.airwallex.base_url', 'https://api.airwallex.test');
        config()->set('services.airwallex.auth', [
            'mode' => 'none',
        ]);
        config()->set('services.airwallex.quote_endpoint', '/api/v1/quotes/create');
        config()->set('services.airwallex.quote_validity', 'MIN_15');

        Http::fake([
            'https://api.airwallex.test/api/v1/quotes/create' => Http::response([
                'quote_id' => 'awx_quote_123',
                'buy_amount' => 2545000,
                'client_rate' => 25450,
                'mid_rate' => 25500,
                'fee_amount' => 5,
                'quote_expiry_time' => '2026-05-18T10:30:00Z',
            ], 200),
        ]);

        $response = $this->getJson('/api/provider-rates?source_currency=usd&target_currency=vnd&source_amount=100');

        $response
            ->assertOk()
            ->assertJsonPath('meta.source_currency', 'USD')
            ->assertJsonPath('meta.target_currency', 'VND')
            ->assertJsonPath('data.0.provider.code', 'airwallex')
            ->assertJsonPath('data.0.quote_status', 'ready')
            ->assertJsonPath('data.0.quote.net_rate', 25450)
            ->assertJsonPath('data.0.quote.target_amount', 2545000)
            ->assertJsonPath('data.1.provider.code', 'pingpong')
            ->assertJsonPath('data.1.quote_status', 'unavailable');

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://api.airwallex.test/api/v1/quotes/create'
                && $data['sell_currency'] === 'USD'
                && $data['buy_currency'] === 'VND'
                && $data['sell_amount'] === '100';
        });
    }
}
