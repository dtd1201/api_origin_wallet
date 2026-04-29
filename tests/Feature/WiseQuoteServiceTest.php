<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Wise\WiseQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WiseQuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_quote_uses_user_token_and_profile_scoped_quote_endpoint(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'wise',
            'name' => 'Wise',
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => '123456',
            'status' => 'active',
            'metadata' => [
                'profile_id' => 123456,
                'access_token' => 'wise-user-token',
            ],
        ]);

        config()->set('services.wise.base_url', 'https://api.wise-sandbox.com');
        config()->set('services.wise.auth', [
            'mode' => 'client_credentials',
            'token_endpoint' => '/oauth/token',
            'client_id' => 'wise-client-id',
            'client_secret' => 'wise-client-secret',
            'credentials_in' => 'basic',
        ]);

        Http::fake([
            'https://api.wise-sandbox.com/v3/profiles/123456/quotes' => Http::response([
                'id' => '11144c35-9fe8-4c32-b7fd-d05c2a7734bf',
                'sourceCurrency' => 'USD',
                'targetCurrency' => 'EUR',
                'sourceAmount' => 100,
                'targetAmount' => 92.34,
                'rate' => 0.9234,
                'payOut' => 'BANK_TRANSFER',
                'paymentOptions' => [
                    [
                        'payOut' => 'BANK_TRANSFER',
                        'fee' => [
                            'total' => 1.25,
                        ],
                    ],
                ],
                'expirationTime' => '2026-04-23T10:30:00Z',
            ], 200),
        ]);

        $quote = app(WiseQuoteService::class)->createQuote($provider, $user, [
            'source_currency' => 'USD',
            'target_currency' => 'EUR',
            'source_amount' => 100,
            'raw_data' => [
                'wise' => [
                    'preferredPayIn' => 'BALANCE',
                ],
            ],
        ]);

        $this->assertSame('11144c35-9fe8-4c32-b7fd-d05c2a7734bf', $quote->quote_ref);
        $this->assertSame('92.34000000', (string) $quote->target_amount);
        $this->assertSame('1.25000000', (string) $quote->fee_amount);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://api.wise-sandbox.com/v3/profiles/123456/quotes'
                && $request->hasHeader('Authorization', 'Bearer wise-user-token')
                && $data['profile'] === 123456
                && $data['sourceCurrency'] === 'USD'
                && $data['preferredPayIn'] === 'BALANCE';
        });
    }
}
