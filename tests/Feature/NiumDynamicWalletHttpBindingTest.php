<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\NiumDynamicPayoutOutbox;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutWalletSnapshot;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Support\OwnerFirstPostgresDatabase;
use Tests\TestCase;

/** Real service/client with offline HTTP fakes; synthetic contract data only. */
final class NiumDynamicWalletHttpBindingTest extends TestCase
{
    use OwnerFirstPostgresDatabase;
    use CreatesDynamicConfirmationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->createDynamicFixtures('2000');
        $this->wallet->update(['currency' => 'USD']);
        config([
            'services.nium.base_url' => 'https://api.spend.nium.com',
            'services.nium.client_id' => 'fixture-client',
            'services.nium.auth.mode' => 'header',
            'services.nium.auth.header_name' => 'x-api-key',
            'services.nium.auth.header_value' => 'synthetic-wallet-test-key',
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'synthetic-webhook-test-key',
            'services.nium.health_endpoint' => '/api/v1/client/{clientHashId}/customer',
            'services.nium.customer_create_endpoint' => '/api/v1/client/{clientHashId}/customer',
            'services.nium.customer_list_endpoint' => '/api/v1/client/{clientHashId}/customer',
            'services.nium.customer_get_endpoint' => '/api/v1/client/{clientHashId}/customer/{customerHashId}',
        ]);
    }

    public static function authHeaderNames(): array
    {
        return [['x-api-key'], ['X-API-Key']];
    }

    #[DataProvider('authHeaderNames')]
    public function test_real_get_forwards_authenticated_callback_without_redirects_or_financial_side_effects(string $headerName): void
    {
        config(['services.nium.auth.header_name' => $headerName]);
        Http::fake(function (Request $request, array $options) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertSame('GET', $request->method());
            $this->assertSame('https://api.spend.nium.com/api/v1/client/fixture-client/customer/fixture-customer/wallet/fixture-wallet', $request->url());
            $this->assertSame(['synthetic-wallet-test-key'], $request->toPsrRequest()->getHeader('x-api-key'));
            $this->assertFalse($options['allow_redirects']);

            return Http::response('[{"curSymbol":"USD","isoCode":"USD","balance":123.12345678,"withHoldingBalance":10}]', 200);
        });
        $snapshot = app(DynamicPayoutWalletSnapshot::class)->refresh($this->owner, $this->wallet);
        $this->assertSame('123.12345678', $snapshot['available']);
        $this->assertSame('2000.00000000', $this->wallet->fresh()->ledger_balance);
        $this->assertSame('2000.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('0.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->count());
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
    }

    public static function unsuccessfulStatuses(): array
    {
        return [[302], [401], [500]];
    }

    #[DataProvider('unsuccessfulStatuses')]
    public function test_redirect_or_unsuccessful_response_is_not_retried_or_persisted(int $status): void
    {
        Http::fake(fn () => Http::response('[{"curSymbol":"USD","balance":1}]', $status,
            ['Location' => 'https://gateway.nium.com/other']));
        $raw = $this->wallet->raw_data;
        try {
            app(DynamicPayoutWalletSnapshot::class)->refresh($this->owner, $this->wallet);
            $this->fail('Unsuccessful response was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('dynamic_wallet_authenticated_snapshot_unavailable', $exception->getMessage());
        }
        $this->assertSame($raw, $this->wallet->fresh()->raw_data);
        Http::assertSentCount(1);
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->count());
    }
}
