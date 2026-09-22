<?php

namespace Tests\Feature;

use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Nium\NiumDataSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NiumBalanceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_balance_sync_preserves_origin_transfer_hold_and_internal_metadata(): void
    {
        config()->set(
            'services.nium.base_url',
            'https://' . 'gateway.sandbox.nium.test'
        );

        config()->set('services.nium.client_id', 'client-test');

        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => 'test-key',
        ]);

        config()->set(
            'services.nium.webhook.static_header_name',
            'x-partner-key'
        );

        config()->set(
            'services.nium.webhook.static_header_value',
            'test-partner-key'
        );

        config()->set(
            'services.nium.wallet_balance_endpoint',
            '/balances'
        );

        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);

        $user = User::factory()->create();

        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'customer-balance-test',
            'external_account_id' => 'wallet-balance-test',
            'status' => 'active',
            'provider_status' => 'clear',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
            'provider_ids_verified_at' => now(),
        ]);

        Balance::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_account_id' => 'wallet-balance-test',
            'currency' => 'USD',
            'available_balance' => '990.00000000',
            'ledger_balance' => '1000.00000000',
            'reserved_balance' => '10.00000000',
            'as_of' => now()->subHour(),
            'raw_data' => [
                'wallet_id' => 'wallet-balance-test',
                'currency' => 'USD',
                '_origin_ledger' => [
                    'last_hold_reference' => 'transfer:123:hold',
                    'last_hold_transfer_id' => 123,
                ],
            ],
        ]);

        Http::fake(fn () => Http::response([
            'balances' => [[
                'walletHashId' => 'wallet-balance-test',
                'currency' => 'USD',
                'availableBalance' => '990.00',
                'ledgerBalance' => '990.00',
                'reservedBalance' => '0.00',
                'status' => 'ACTIVE',
                'updatedAt' => now()->toISOString(),
            ]],
        ], 200));

        $result = app(NiumDataSyncService::class)
            ->syncBalances($provider, $user);

        $balance = Balance::query()->sole();

        $this->assertSame(1, $result['synced_balances']);

        $this->assertSame(
            '990.00000000',
            $balance->available_balance
        );

        $this->assertSame(
            '1000.00000000',
            $balance->ledger_balance
        );

        $this->assertSame(
            '10.00000000',
            $balance->reserved_balance
        );

        $this->assertSame(
            'transfer:123:hold',
            data_get(
                $balance->raw_data,
                '_origin_ledger.last_hold_reference'
            )
        );

        $this->assertSame(
            123,
            data_get(
                $balance->raw_data,
                '_origin_ledger.last_hold_transfer_id'
            )
        );

        $this->assertSame(
            '0.00',
            data_get(
                $balance->raw_data,
                'provider_reserved_balance'
            )
        );

        $this->assertSame(
            '990.00',
            data_get(
                $balance->raw_data,
                'provider_ledger_balance'
            )
        );
    }
}
