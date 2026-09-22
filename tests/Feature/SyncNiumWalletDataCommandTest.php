<?php

namespace Tests\Feature;

use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncNiumWalletDataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'services.nium.base_url',
            'https://gateway.sandbox.nium.test'
        );

        config()->set(
            'services.nium.client_id',
            'client-wallet-sync-test'
        );

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
            '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}'
        );

        config()->set(
            'services.nium.wallet_transactions_endpoint',
            '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/transactions'
        );

        config()->set(
            'services.nium.transaction_sync_page_size',
            100
        );

        config()->set(
            'services.nium.transaction_sync_max_pages',
            1
        );

        config()->set(
            'services.nium.wallet_data_sync_enabled',
            true
        );

        config()->set(
            'services.nium.wallet_data_sync_limit',
            50
        );
    }

    public function test_disabled_wallet_sync_sends_no_provider_request(): void
    {
        config()->set(
            'services.nium.wallet_data_sync_enabled',
            false
        );

        Http::fake();

        $this->artisan('nium:sync-wallet-data')
            ->expectsOutput('Nium wallet data sync is disabled.')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_only_operational_accounts_are_synced_and_requests_are_get_only(): void
    {
        $provider = $this->provider();

        $eligibleUser = User::factory()->create();

        $this->account(
            $eligibleUser,
            $provider,
            1,
            'wallet-eligible'
        );

        $ineligibleUser = User::factory()->create();

        $this->account(
            $ineligibleUser,
            $provider,
            2,
            'wallet-ineligible',
            [
                'status' => 'under_review',
                'provider_status' => 'pending',
            ]
        );

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/transactions')) {
                return Http::response([
                    'content' => [[
                        'transactionId' => 'txn-auto-wallet-sync',
                        'currency' => 'USD',
                        'amount' => 25,
                        'status' => 'COMPLETED',
                        'dateTime' => now()->toISOString(),
                    ]],
                    'totalPages' => 1,
                ], 200);
            }

            return Http::response([
                'balances' => [[
                    'walletHashId' => 'wallet-eligible',
                    'currency' => 'USD',
                    'availableBalance' => '100.00',
                    'ledgerBalance' => '100.00',
                    'reservedBalance' => '0.00',
                ]],
            ], 200);
        });

        $this->artisan('nium:sync-wallet-data')
            ->assertExitCode(0);

        $this->assertSame(
            1,
            Transaction::query()->count()
        );

        $this->assertSame(
            $eligibleUser->id,
            Transaction::query()->sole()->user_id
        );

        $this->assertSame(
            1,
            Balance::query()
                ->where('user_id', $eligibleUser->id)
                ->count()
        );

        $this->assertSame(
            0,
            Balance::query()
                ->where('user_id', $ineligibleUser->id)
                ->count()
        );

        $this->assertNotNull(
            $eligibleUser->providerAccounts()
                ->where('provider_id', $provider->id)
                ->sole()
                ->transactions_last_synced_at
        );

        $this->assertNull(
            $ineligibleUser->providerAccounts()
                ->where('provider_id', $provider->id)
                ->sole()
                ->transactions_last_synced_at
        );

        $this->assertCount(
            0,
            collect(Http::recorded())->filter(
                fn ($pair) => $pair[0]->method() !== 'GET'
            )
        );
    }

    public function test_transaction_failure_does_not_block_balance_or_next_account(): void
    {
        $provider = $this->provider();

        $firstUser = User::factory()->create();
        $this->account(
            $firstUser,
            $provider,
            1,
            'wallet-first'
        );

        $secondUser = User::factory()->create();
        $this->account(
            $secondUser,
            $provider,
            2,
            'wallet-second'
        );

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (
                str_contains($url, 'wallet-first')
                && str_contains($url, '/transactions')
            ) {
                return Http::response([
                    'message' => 'Temporary transaction API failure.',
                ], 500);
            }

            if (str_contains($url, '/transactions')) {
                return Http::response([
                    'content' => [[
                        'transactionId' => 'txn-second-user',
                        'currency' => 'USD',
                        'amount' => 50,
                        'status' => 'COMPLETED',
                        'dateTime' => now()->toISOString(),
                    ]],
                    'totalPages' => 1,
                ], 200);
            }

            $wallet = str_contains($url, 'wallet-first')
                ? 'wallet-first'
                : 'wallet-second';

            return Http::response([
                'balances' => [[
                    'walletHashId' => $wallet,
                    'currency' => 'USD',
                    'availableBalance' => '200.00',
                    'ledgerBalance' => '200.00',
                    'reservedBalance' => '0.00',
                ]],
            ], 200);
        });

        $this->artisan('nium:sync-wallet-data')
            ->assertExitCode(1);

        $this->assertSame(
            1,
            Balance::query()
                ->where('user_id', $firstUser->id)
                ->count()
        );

        $this->assertSame(
            1,
            Balance::query()
                ->where('user_id', $secondUser->id)
                ->count()
        );

        $this->assertSame(
            1,
            Transaction::query()
                ->where('user_id', $secondUser->id)
                ->count()
        );

        $this->assertNull(
            $firstUser->providerAccounts()
                ->where('provider_id', $provider->id)
                ->sole()
                ->transactions_last_synced_at
        );

        $this->assertNotNull(
            $secondUser->providerAccounts()
                ->where('provider_id', $provider->id)
                ->sole()
                ->transactions_last_synced_at
        );

        $this->assertCount(
            0,
            collect(Http::recorded())->filter(
                fn ($pair) => $pair[0]->method() !== 'GET'
            )
        );
    }

    public function test_balance_failure_does_not_rollback_transaction_or_block_next_account(): void
    {
        $provider = $this->provider();

        $firstUser = User::factory()->create();
        $this->account(
            $firstUser,
            $provider,
            1,
            'wallet-first'
        );

        $secondUser = User::factory()->create();
        $this->account(
            $secondUser,
            $provider,
            2,
            'wallet-second'
        );

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/transactions')) {
                $transactionId = str_contains($url, 'wallet-first')
                    ? 'txn-first-balance-failure'
                    : 'txn-second-balance-failure';

                return Http::response([
                    'content' => [[
                        'transactionId' => $transactionId,
                        'currency' => 'USD',
                        'amount' => 75,
                        'status' => 'COMPLETED',
                        'dateTime' => now()->toISOString(),
                    ]],
                    'totalPages' => 1,
                ], 200);
            }

            if (str_contains($url, 'wallet-first')) {
                return Http::response([
                    'message' => 'Temporary balance API failure.',
                ], 500);
            }

            return Http::response([
                'balances' => [[
                    'walletHashId' => 'wallet-second',
                    'currency' => 'USD',
                    'availableBalance' => '300.00',
                    'ledgerBalance' => '300.00',
                    'reservedBalance' => '0.00',
                ]],
            ], 200);
        });

        $this->artisan('nium:sync-wallet-data')
            ->assertExitCode(1);

        $this->assertSame(
            1,
            Transaction::query()
                ->where('user_id', $firstUser->id)
                ->count()
        );

        $this->assertSame(
            1,
            Transaction::query()
                ->where('user_id', $secondUser->id)
                ->count()
        );

        $this->assertSame(
            0,
            Balance::query()
                ->where('user_id', $firstUser->id)
                ->count()
        );

        $this->assertSame(
            1,
            Balance::query()
                ->where('user_id', $secondUser->id)
                ->count()
        );

        $this->assertNotNull(
            $firstUser->providerAccounts()
                ->where('provider_id', $provider->id)
                ->sole()
                ->transactions_last_synced_at
        );

        $this->assertNotNull(
            $secondUser->providerAccounts()
                ->where('provider_id', $provider->id)
                ->sole()
                ->transactions_last_synced_at
        );

        $this->assertCount(
            0,
            collect(Http::recorded())->filter(
                fn ($pair) => $pair[0]->method() !== 'GET'
            )
        );
    }

    private function provider(): IntegrationProvider
    {
        return IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
    }

    private function account(
        User $user,
        IntegrationProvider $provider,
        int $suffix,
        string $wallet,
        array $overrides = [],
    ): void {
        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => "customer-{$suffix}",
            'external_account_id' => $wallet,
            'status' => 'active',
            'provider_status' => 'clear',
            'reconciliation_status' => 'reconciled',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
            'provider_ids_verified_at' => now(),
            'security_conflict_at' => null,
            ...$overrides,
        ]);
    }
}
