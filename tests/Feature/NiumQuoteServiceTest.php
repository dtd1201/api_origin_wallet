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
        ]);

        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.com');
        config()->set('services.nium.client_id', 'client_hash_123');
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => 'nium-api-key',
        ]);

        Http::fake([
            'https://gateway.sandbox.nium.com/api/v1/client/client_hash_123/quotes' => Http::response([
                'quotes' => [[
                    'quoteId' => 'qte_123',
                    'sourceAmount' => 100,
                    'destinationAmount' => 7800,
                    'fxRate' => 78,
                    'feeAmount' => 1.5,
                    'expiresAt' => now()->addMinutes(15)->toISOString(),
                ]],
            ], 200),
        ]);

        $quote = app(NiumQuoteService::class)->createQuote($provider, $user, [
            'source_currency' => 'USD',
            'target_currency' => 'INR',
            'source_amount' => 100,
            'target_amount' => 7800,
        ]);

        $this->assertSame('qte_123', $quote->quote_ref);
        $this->assertSame('USD', $quote->source_currency);
        $this->assertSame('INR', $quote->target_currency);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://gateway.sandbox.nium.com/api/v1/client/client_hash_123/quotes'
                && $request->hasHeader('x-api-key', 'nium-api-key')
                && $data['sourceCurrencyCode'] === 'USD'
                && $data['destinationCurrencyCode'] === 'INR'
                && $data['sourceAmount'] === 100.0
                && $data['customerHashId'] === 'cust_hash_123'
                && $data['quoteType'] === 'payout';
        });
    }
}
