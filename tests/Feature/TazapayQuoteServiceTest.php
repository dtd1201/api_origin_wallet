<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Tazapay\TazapayQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TazapayQuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_quote_maps_tazapay_payout_quote_response_to_fx_quote(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'tazapay',
            'name' => 'Tazapay',
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_account_id' => 'acc_connected_001',
            'status' => 'active',
            'metadata' => [
                'tz_account_id' => 'acc_connected_001',
            ],
        ]);

        config()->set('services.tazapay.base_url', 'https://service-sandbox.tazapay.com');
        config()->set('services.tazapay.auth', [
            'mode' => 'basic_auth',
            'username' => 'tzp_key',
            'password' => 'tzp_secret',
        ]);

        Http::fake([
            'https://service-sandbox.tazapay.com/v3/payout/quote' => Http::response([
                'status' => 'success',
                'message' => '',
                'data' => [
                    'id' => 'poq_test_123',
                    'payout_type' => 'local',
                    'holding_info' => [
                        'currency' => 'USD',
                        'amount' => 640000,
                    ],
                    'payout_info' => [
                        'currency' => 'HKD',
                        'amount' => 5000000,
                    ],
                    'fee_info' => [
                        'fixed' => [
                            'in_holding_currency' => 200,
                        ],
                        'variable' => [
                            'in_holding_currency' => 1280,
                        ],
                    ],
                    'exchange_rates' => [
                        'holding_to_payout' => 7.81,
                    ],
                    'valid_until' => '2024-12-03T14:30:00+08:00',
                ],
            ], 200),
        ]);

        $quote = app(TazapayQuoteService::class)->createQuote($provider, $user, [
            'source_currency' => 'USD',
            'target_currency' => 'HKD',
            'source_amount' => 640000,
            'target_amount' => 5000000,
            'raw_data' => [
                'tazapay' => [
                    'payout_type' => 'local',
                    'local' => [
                        'fund_transfer_network' => 'chats',
                    ],
                ],
            ],
        ]);

        $this->assertSame('poq_test_123', $quote->quote_ref);
        $this->assertSame('USD', $quote->source_currency);
        $this->assertSame('HKD', $quote->target_currency);
        $this->assertSame('640000.00000000', (string) $quote->source_amount);
        $this->assertSame('5000000.00000000', (string) $quote->target_amount);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://service-sandbox.tazapay.com/v3/payout/quote'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('tzp_key:tzp_secret'))
                && $request->hasHeader('tz-account-id', 'acc_connected_001')
                && $data['payout_type'] === 'local'
                && $data['payout_info']['currency'] === 'HKD'
                && $data['local']['fund_transfer_network'] === 'chats';
        });
    }
}
