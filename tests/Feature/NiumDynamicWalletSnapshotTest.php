<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutWalletSnapshot;
use App\Services\Nium\NiumProviderAccountStateService;
use App\Services\Nium\NiumService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Support\DynamicConfirmationDatabaseTestCase;

final class NiumDynamicWalletSnapshotTest extends DynamicConfirmationDatabaseTestCase
{
    use CreatesDynamicConfirmationFixtures;
    private NiumService $nium;
    private DynamicPayoutWalletSnapshot $snapshots;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDynamicFixtures('2000');
        $this->wallet->update(['currency' => 'USD']);
        config(['services.nium.base_url' => 'https://gatewaysandbox.nium.com']);
        $this->nium = Mockery::mock(NiumService::class)->makePartial();
        $this->nium->shouldReceive('clientId')->andReturn('fixture-client');
        $this->snapshots = new DynamicPayoutWalletSnapshot($this->nium, app(NiumProviderAccountStateService::class));
    }

    private function response(string $body, ?\Closure $during = null): void
    {
        $this->nium->shouldReceive('get')->once()->andReturnUsing(function ($path, $query, $user, $operation) use ($body, $during): Response {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('/api/v1/client/fixture-client/customer/fixture-customer/wallet/fixture-wallet', $path);
            $this->assertSame('dynamic_wallet_availability', $operation);
            $during?->__invoke();
            return new Response(new PsrResponse(200, [], $body));
        });
    }

    public function test_authenticated_refresh_records_admission_ceiling_without_rewriting_books(): void
    {
        $this->wallet->update(['available_balance' => '1500', 'reserved_balance' => '500']);
        $this->response('{"walletHashId":"fixture-wallet","currencies":[{"currencyCode":"USD","availableBalance":1000.01}]}');
        $snapshot = $this->snapshots->refresh($this->owner, $this->wallet);
        $this->assertSame('1000.01000000', $snapshot['available']);
        $this->assertSame('1500.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('500.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame('2000.00000000', $this->wallet->fresh()->ledger_balance);
        $this->assertArrayNotHasKey('provider_as_of', $snapshot);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_lower_then_higher_provider_observations_do_not_permanently_reduce_local_funds(): void
    {
        $this->nium->shouldReceive('get')->twice()->andReturn(
            new Response(new PsrResponse(200, [], '{"walletHashId":"fixture-wallet","currencies":[{"currencyCode":"USD","availableBalance":500}]}')),
            new Response(new PsrResponse(200, [], '{"walletHashId":"fixture-wallet","currencies":[{"currencyCode":"USD","availableBalance":2000}]}')));
        $this->snapshots->refresh($this->owner, $this->wallet);
        $this->assertSame('2000.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('500.00000000', $this->wallet->fresh()->raw_data['provider_available_balance']);
        $this->snapshots->refresh($this->owner, $this->wallet);
        $this->assertSame('2000.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('2000.00000000', $this->wallet->fresh()->raw_data['provider_available_balance']);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public static function invalidResponses(): array
    {
        return [
            ['{"walletHashId":"other","currencies":[]}', 'dynamic_wallet_response_scope_unverified'],
            ['{"walletHashId":"fixture-wallet","currencies":[{"currencyCode":"EUR","availableBalance":1000}]}', 'dynamic_wallet_usd_availability_unavailable'],
            ['{"walletHashId":"fixture-wallet","currencies":[{"currencyCode":"USD","availableBalance":1000},{"currencyCode":"USD","availableBalance":1000}]}', 'dynamic_wallet_usd_availability_unavailable'],
            ['{"walletHashId":"fixture-wallet","customerHashId":"other","currencies":[]}', 'dynamic_wallet_response_scope_unverified'],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_unbound_or_ambiguous_wallet_response_cannot_update_local_balances(string $body, string $reason): void
    {
        $this->response($body);
        try {
            $this->snapshots->refresh($this->owner, $this->wallet);
            $this->fail('Invalid provider wallet snapshot was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame($reason, $exception->getMessage());
        }
        $this->assertSame('2000.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('0.00000000', $this->wallet->fresh()->reserved_balance);
    }

    public function test_customer_rebinding_during_http_invalidates_the_snapshot(): void
    {
        $this->response('{"walletHashId":"fixture-wallet","currencies":[{"currencyCode":"USD","availableBalance":1000}]}',
            fn () => $this->account->update(['external_customer_id' => 'changed-customer']));
        $this->expectExceptionMessage('dynamic_wallet_snapshot_binding_changed');
        $this->snapshots->refresh($this->owner, $this->wallet);
    }

    public function test_wallet_provider_io_is_forbidden_inside_reservation_transactions(): void
    {
        $this->nium->shouldNotReceive('get');
        $this->expectException(\App\Services\Wallet\Concurrency\OwnerLockContractViolation::class);
        DB::transaction(fn () => $this->snapshots->refresh($this->owner, $this->wallet));
    }

    public function test_slow_authenticated_observation_does_not_update_spendable_funds(): void
    {
        $this->response('{"walletHashId":"fixture-wallet","currencies":[{"currencyCode":"USD","availableBalance":1000}]}',
            fn () => $this->travelTo(now()->addSeconds(31)));
        try {
            $this->snapshots->refresh($this->owner, $this->wallet);
            $this->fail('Expired observation was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('dynamic_wallet_authenticated_snapshot_unavailable', $exception->getMessage());
        }
        $this->assertSame('2000.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame(0, LedgerEntry::query()->count());
    }
}
