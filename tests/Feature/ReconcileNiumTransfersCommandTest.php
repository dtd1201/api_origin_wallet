<?php

namespace Tests\Feature;

use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Wallet\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReconcileNiumTransfersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'services.nium.base_url',
            'https://' . 'gateway.sandbox.nium.test'
        );

        config()->set(
            'services.nium.client_id',
            'client-reconcile-test'
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
            'services.nium.health_endpoint',
            '/api/v1/client/{clientHashId}'
        );

        config()->set(
            'services.nium.customer_create_endpoint',
            '/api/v5/client/{clientHashId}/customers'
        );

        config()->set(
            'services.nium.customer_list_endpoint',
            '/api/v5/client/{clientHashId}/customers'
        );

        config()->set(
            'services.nium.customer_get_endpoint',
            '/api/v5/client/{clientHashId}/customer/{customerHashId}'
        );

        config()->set(
            'services.nium.transfer_status_endpoint',
            '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance/{systemReferenceNumber}/audit'
        );

        config()->set(
            'services.nium.transfer_reconciliation_limit',
            50
        );

        config()->set(
            'services.nium.transfer_reconciliation_enabled',
            true
        );
    }

    public function test_disabled_reconciliation_exits_without_provider_http(): void
    {
        config()->set(
            'services.nium.transfer_reconciliation_enabled',
            false
        );

        Http::fake();

        $this->artisan('nium:reconcile-transfers')
            ->expectsOutput('Nium transfer reconciliation is disabled.')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_batch_reconciliation_completes_pending_transfer_and_settles_ledger(): void
    {
        [$provider, $user] = $this->makeProviderUserAndBalance();

        $transfer = $this->makeHeldTransfer(
            $provider,
            $user,
            'RT-AUTO-COMPLETED'
        );

        Http::fake(fn (Request $request) => Http::response([
            [
                'status' => 'COMPLETED',
                'lastUpdatedAt' => now()->toISOString(),
            ],
        ], 200));

        $this->artisan('nium:reconcile-transfers', [
            '--limit' => 10,
        ])->assertExitCode(0);

        $fresh = $transfer->fresh();
        $balance = Balance::query()->sole();

        $this->assertSame('completed', $fresh->status);
        $this->assertSame('COMPLETED', $fresh->provider_status);

        $this->assertSame(
            '990.00000000',
            $balance->available_balance
        );

        $this->assertSame(
            '0.00000000',
            $balance->reserved_balance
        );

        $this->assertSame(
            '990.00000000',
            $balance->ledger_balance
        );

        $this->assertSame(
            1,
            LedgerEntry::query()
                ->where('source_type', 'transfer')
                ->where('source_id', (string) $transfer->id)
                ->where('entry_type', 'debit')
                ->count()
        );

        $this->assertCount(
            0,
            collect(Http::recorded())->filter(
                fn ($pair) => $pair[0]->method() === 'POST'
            )
        );
    }

    public function test_one_provider_failure_does_not_stop_remaining_batch(): void
    {
        [$provider, $user] = $this->makeProviderUserAndBalance();

        $first = $this->makeHeldTransfer(
            $provider,
            $user,
            'RT-AUTO-FAIL'
        );

        $second = $this->makeHeldTransfer(
            $provider,
            $user,
            'RT-AUTO-SUCCESS'
        );

        Http::fake(function (Request $request) {
            if (str_contains(
                $request->url(),
                'RT-AUTO-FAIL'
            )) {
                return Http::response([
                    'message' => 'Temporary provider failure.',
                ], 422);
            }

            return Http::response([
                [
                    'status' => 'COMPLETED',
                    'lastUpdatedAt' => now()->toISOString(),
                ],
            ], 200);
        });

        $this->artisan('nium:reconcile-transfers', [
            '--limit' => 10,
        ])->assertExitCode(1);

        $this->assertSame(
            'pending',
            $first->fresh()->status
        );

        $this->assertSame(
            'completed',
            $second->fresh()->status
        );

        $this->assertSame(
            1,
            LedgerEntry::query()
                ->where('source_type', 'transfer')
                ->where('source_id', (string) $second->id)
                ->where('entry_type', 'debit')
                ->count()
        );

        $this->assertCount(
            0,
            collect(Http::recorded())->filter(
                fn ($pair) => $pair[0]->method() === 'POST'
            )
        );
    }

    private function makeProviderUserAndBalance(): array
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);

        $user = User::factory()->create();

        $user->providerAccounts()->create([
            'provider_id' => $provider->id,
            'external_customer_id' => 'customer-reconcile-test',
            'external_account_id' => 'wallet-reconcile-test',
            'status' => 'active',
            'provider_status' => 'clear',
            'reconciliation_status' => 'reconciled',
            'customer_id_verified_at' => now(),
            'wallet_id_verified_at' => now(),
            'provider_ids_verified_at' => now(),
        ]);

        Balance::query()->create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_account_id' => 'wallet-reconcile-test',
            'currency' => 'USD',
            'available_balance' => '1000.00000000',
            'ledger_balance' => '1000.00000000',
            'reserved_balance' => '0.00000000',
            'as_of' => now(),
        ]);

        return [$provider, $user];
    }

    private function makeHeldTransfer(
        IntegrationProvider $provider,
        User $user,
        string $reference,
    ): Transfer {
        $transfer = Transfer::query()->create([
            'transfer_no' => 'TRF-'.$reference,
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'external_transfer_id' => $reference,
            'transfer_type' => 'payout',
            'source_currency' => 'USD',
            'target_currency' => 'USD',
            'source_amount' => '10.00000000',
            'status' => 'pending',
        ]);

        app(LedgerService::class)
            ->reserveTransfer($transfer);

        return $transfer->fresh();
    }
}
