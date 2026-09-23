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

    public function test_public_provider_rates_return_primary_nium_modern_quote_without_auth(): void
    {
        Cache::flush();

        IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);

        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.test');
        config()->set('services.nium.client_id', 'client_hash_123');
        config()->set('services.nium.payout_fx_enabled', true);
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => 'nium-api-key',
        ]);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-partner-key');

        config()->set(
            'services.nium.health_endpoint',
            '/api/v1/client/{clientHashId}',
        );
        config()->set(
            'services.nium.customer_create_endpoint',
            '/api/v5/client/{clientHashId}/customer',
        );
        config()->set(
            'services.nium.customer_list_endpoint',
            '/api/v5/client/{clientHashId}/customers',
        );
        config()->set(
            'services.nium.customer_get_endpoint',
            '/api/v5/client/{clientHashId}/customer/{customerHashId}',
        );
        config()->set(
            'services.nium.quote_endpoint',
            '/api/v1/client/{clientHashId}/quotes',
        );

        Http::fake([
            'https://gateway.sandbox.nium.test/api/v1/client/client_hash_123/quotes' => Http::response([
                'id' => 'quote_preview_123',
                'sourceCurrencyCode' => 'USD',
                'destinationCurrencyCode' => 'VND',
                'sourceAmount' => 100,
                'destinationAmount' => 2545000,
                'exchangeRate' => 25500,
                'netExchangeRate' => 25450,
                'expiryTime' => '2026-09-23T10:30:00Z',
                'quoteType' => 'payout',
                'quoteIntent' => 'INDICATIVE',
            ], 200),
        ]);

        $response = $this->getJson(
            '/api/provider-rates?source_currency=usd&target_currency=vnd&source_amount=100'
        );

        $response
            ->assertOk()
            ->assertJsonPath('meta.source_currency', 'USD')
            ->assertJsonPath('meta.target_currency', 'VND')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider.code', 'nium')
            ->assertJsonPath('data.0.provider.supports_quotes', true)
            ->assertJsonPath('data.0.quote_status', 'ready')
            ->assertJsonPath('data.0.quote.source_amount', 100)
            ->assertJsonPath('data.0.quote.target_amount', 2545000)
            ->assertJsonPath('data.0.quote.mid_rate', 25500)
            ->assertJsonPath('data.0.quote.net_rate', 25450)
            ->assertJsonPath('data.0.quote.expires_at', '2026-09-23T10:30:00Z');

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://gateway.sandbox.nium.test/api/v1/client/client_hash_123/quotes'
                && $request->hasHeader('x-api-key', 'nium-api-key')
                && $request->hasHeader('x-request-id')
                && ($data['sourceCurrencyCode'] ?? null) === 'USD'
                && ($data['destinationCurrencyCode'] ?? null) === 'VND'
                && (float) ($data['sourceAmount'] ?? 0) === 100.0
                && ($data['quoteType'] ?? null) === 'payout'
                && ($data['conversionSchedule'] ?? null) === 'immediate'
                && ($data['lockPeriod'] ?? null) === '5_mins'
                && ($data['executionType'] ?? null) === 'at_conversion_time'
                && ($data['quoteIntent'] ?? null) === 'INDICATIVE';
        });
    }
}
