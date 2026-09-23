<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class NiumQuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.test');
        config()->set('services.nium.client_id', 'client_hash_123');
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => 'nium-api-key',
        ]);
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'test-partner-key');
        config()->set(
            'services.nium.quote_endpoint',
            '/api/v1/client/{clientHashId}/quotes',
        );
    }

    public function test_create_quote_uses_modern_executable_nium_quote_contract(): void
    {
        [$provider, $user] = $this->providerAndUser();

        config()->set('services.nium.payout_fx_enabled', true);

        Http::fake([
            'https://gateway.sandbox.nium.test/api/v1/client/client_hash_123/quotes' => Http::response([
                'id' => 'quote_exec_123',
                'sourceCurrencyCode' => 'USD',
                'destinationCurrencyCode' => 'EUR',
                'sourceAmount' => 100,
                'destinationAmount' => 91.5,
                'exchangeRate' => 0.92,
                'netExchangeRate' => 0.915,
                'expiryTime' => now()->addMinutes(5)->toISOString(),
                'quoteType' => 'payout',
                'quoteIntent' => 'EXECUTABLE',
                'conversionSchedule' => 'immediate',
                'executionType' => 'manual',
                'lockPeriod' => '5_mins',
            ], 200),
        ]);

        $quote = app(NiumQuoteService::class)->createQuote($provider, $user, [
            'source_currency' => 'USD',
            'target_currency' => 'EUR',
            'source_amount' => 100,
        ]);

        $this->assertSame('quote_exec_123', $quote->quote_ref);
        $this->assertSame('USD', $quote->source_currency);
        $this->assertSame('EUR', $quote->target_currency);
        $this->assertSame('100.00000000', $quote->source_amount);
        $this->assertSame('91.50000000', $quote->target_amount);
        $this->assertSame('0.9200000000', $quote->mid_rate);
        $this->assertSame('0.9150000000', $quote->net_rate);
        $this->assertSame('modern_quote', $quote->raw_data['provider_fx_type']);
        $this->assertSame('EXECUTABLE', $quote->raw_data['quote_intent']);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://gateway.sandbox.nium.test/api/v1/client/client_hash_123/quotes'
                && $request->hasHeader('x-api-key', 'nium-api-key')
                && $request->hasHeader('x-request-id')
                && ($data['sourceCurrencyCode'] ?? null) === 'USD'
                && ($data['destinationCurrencyCode'] ?? null) === 'EUR'
                && (float) ($data['sourceAmount'] ?? 0) === 100.0
                && ($data['customerHashId'] ?? null) === 'cust_hash_123'
                && ($data['quoteType'] ?? null) === 'payout'
                && ($data['quoteIntent'] ?? null) === 'EXECUTABLE'
                && ($data['conversionSchedule'] ?? null) === 'immediate'
                && ($data['executionType'] ?? null) === 'manual'
                && ($data['lockPeriod'] ?? null) === '5_mins';
        });
    }

    public function test_create_quote_fails_closed_when_payout_fx_is_disabled_without_http(): void
    {
        [$provider, $user] = $this->providerAndUser();

        config()->set('services.nium.payout_fx_enabled', false);
        Http::fake();

        try {
            app(NiumQuoteService::class)->createQuote($provider, $user, [
                'source_currency' => 'USD',
                'target_currency' => 'EUR',
                'source_amount' => 100,
            ]);

            $this->fail('Expected disabled Nium payout FX to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Nium payout FX is not enabled.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    private function providerAndUser(): array
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
            'reconciliation_status' => 'reconciled',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
            'provider_ids_verified_at' => now(),
        ]);

        return [$provider, $user];
    }
}
