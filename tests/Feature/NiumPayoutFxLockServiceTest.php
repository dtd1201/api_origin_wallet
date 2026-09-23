<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumPayoutFxLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NiumPayoutFxLockServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_lock_preserves_legacy_payout_fx_audit_contract(): void
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
            'services.nium.payout_fx_lock_endpoint',
            '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/lockExchangeRate',
        );

        Http::fake([
            'https://gateway.sandbox.nium.test/api/v1/client/client_hash_123/customer/cust_hash_123/wallet/wallet_hash_123/lockExchangeRate*' => Http::response([
                'audit_id' => 112,
                'fx_hold_id' => 'hold-123',
                'fx_rate' => '0.915',
                'hold_expiry_at' => now()->addMinutes(15)->toISOString(),
                'status' => 'ACTIVE',
            ], 200),
        ]);

        $quote = app(NiumPayoutFxLockService::class)->createLock(
            $provider,
            $user,
            'USD',
            'EUR',
            100,
        );

        $this->assertSame('112', $quote->quote_ref);
        $this->assertSame('USD', $quote->source_currency);
        $this->assertSame('EUR', $quote->target_currency);
        $this->assertSame('91.50000000', $quote->target_amount);
        $this->assertSame('payout_fx_lock', $quote->raw_data['provider_fx_type']);

        Http::assertSent(fn ($request): bool =>
            $request->method() === 'GET'
            && str_starts_with(
                $request->url(),
                'https://gateway.sandbox.nium.test/api/v1/client/client_hash_123/customer/cust_hash_123/wallet/wallet_hash_123/lockExchangeRate?'
            )
        );
    }
}
