<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NiumQuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-partner-key');
    }

    public function test_create_quote_maps_nium_response_to_fx_quote(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        UserProviderAccount::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_customer_id' => 'cust_hash_123',
            'external_account_id' => 'wallet_hash_123',
            'status' => 'active',
            'provider_status' => 'clear',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
            'provider_ids_verified_at' => now(),
        ]);

        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.com');
        config()->set('services.nium.client_id', 'client_hash_123');
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => 'nium-api-key',
        ]);

        Http::fake([
            'https://gateway.sandbox.nium.com/api/v1/client/client_hash_123/customer/cust_hash_123/wallet/wallet_hash_123/lockExchangeRate*' => Http::response([
                'audit_id' => 112,
                'fx_hold_id' => 'hold-123',
                'source_currency' => 'USD',
                'destination_currency' => 'INR',
                'fx_rate' => '78',
                'hold_expiry_at' => now()->addMinutes(15)->toISOString(),
                'status' => 'ACTIVE',
            ], 200),
        ]);

        $quote = app(NiumQuoteService::class)->createQuote($provider, $user, [
            'source_currency' => 'USD',
            'target_currency' => 'INR',
            'source_amount' => 100,
            'target_amount' => 7800,
        ]);

        $this->assertSame('112', $quote->quote_ref);
        $this->assertSame('USD', $quote->source_currency);
        $this->assertSame('INR', $quote->target_currency);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return str_starts_with($request->url(), 'https://gateway.sandbox.nium.com/api/v1/client/client_hash_123/customer/cust_hash_123/wallet/wallet_hash_123/lockExchangeRate?')
                && $request->hasHeader('x-api-key', 'nium-api-key')
                && $data['sourceCurrency'] === 'USD'
                && $data['destinationCurrency'] === 'INR';
        });
    }
}
