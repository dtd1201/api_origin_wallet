<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use App\Models\NiumDynamicReservation;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutWalletSnapshot;
use App\Services\Nium\NiumProviderAccountStateService;
use App\Services\Nium\NiumService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Concerns\CreatesFinalizedUsdConfirmationFixtures;
use Tests\Support\DynamicConfirmationDatabaseTestCase;

final class NiumDynamicWalletSnapshotTest extends DynamicConfirmationDatabaseTestCase
{
    use CreatesDynamicConfirmationFixtures;
    use CreatesFinalizedUsdConfirmationFixtures;
    private NiumService $nium;
    private DynamicPayoutWalletSnapshot $snapshots;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDynamicFixtures('2000');
        $this->wallet->update(['currency' => 'USD']);
        config(['services.nium.base_url' => 'https://gatewaysandbox.nium.com',
            'services.nium.client_id' => 'fixture-client',
            'services.nium.auth.mode' => 'header', 'services.nium.auth.header_name' => 'x-api-key',
            'services.nium.auth.header_value' => 'synthetic-wallet-test-key']);
        $this->nium = Mockery::mock(NiumService::class)->makePartial();
        $this->nium->shouldReceive('clientId')->andReturnUsing(fn () => config('services.nium.client_id'));
        $this->nium->shouldNotReceive('post', 'postDynamicPayout', 'getDynamicPayoutTransactions');
        $this->snapshots = new DynamicPayoutWalletSnapshot($this->nium, app(NiumProviderAccountStateService::class));
    }

    private function response(string|array $body, ?\Closure $during = null): void
    {
        $bodies = is_array($body) ? $body : [$body];
        $call = 0;
        $this->nium->shouldReceive('get')->times(count($bodies))->andReturnUsing(function ($path, $query, $user, $operation,
            $reference, $beforeDispatch) use ($bodies, $during, &$call): Response {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('/api/v1/client/fixture-client/customer/fixture-customer/wallet/fixture-wallet', $path);
            $this->assertSame('dynamic_wallet_availability', $operation);
            $this->assertSame([], $query);
            $this->assertSame($this->owner->id, $user->id);
            $this->assertSame((string) $this->wallet->id, $reference);
            $beforeDispatch(new Request(new PsrRequest('GET', rtrim(config('services.nium.base_url'), '/').$path,
                ['x-api-key' => config('services.nium.auth.header_value')])));
            $during?->__invoke();
            return new Response(new PsrResponse(200, [], $bodies[$call++]));
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
        $this->assertSame(0, NiumDynamicIntent::query()->count());
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->count());
    }

    public function test_lower_then_higher_provider_observations_do_not_permanently_reduce_local_funds(): void
    {
        $this->response([
            '{"walletHashId":"fixture-wallet","currencies":[{"currencyCode":"USD","availableBalance":500}]}',
            '{"walletHashId":"fixture-wallet","currencies":[{"currencyCode":"USD","availableBalance":2000}]}',
        ]);
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

    public function test_spend_root_array_uses_request_scope_and_preserves_exact_observation_separately(): void
    {
        config(['services.nium.base_url' => 'https://api.spend.nium.com']);
        $body = '[{"curSymbol":"USD","isoCode":"USD","balance":999999999999.12345678,"withHoldingBalance":25,"default":true}]';
        $this->response($body);
        $snapshot = $this->snapshots->refresh($this->owner, $this->wallet);
        $this->assertSame('999999999999.12345678', $snapshot['available']);
        $this->assertSame('fixture-wallet', $snapshot['wallet']);
        $this->assertSame('fixture-customer', $snapshot['customer']);
        $this->assertSame('fixture-client', $snapshot['client']);
        $this->assertSame((string) $this->owner->id, $snapshot['owner']);
        $this->assertSame((string) $this->account->id, $snapshot['account_id']);
        $this->assertSame((string) $this->account->provider_id, $snapshot['provider_id']);
        $this->assertSame('USD', $snapshot['currency']);
        $this->assertSame('https://api.spend.nium.com', $snapshot['origin']);
        $this->assertSame('/api/v1/client/fixture-client/customer/fixture-customer/wallet/fixture-wallet', $snapshot['request_path']);
        $this->assertSame(now()->toISOString(), $snapshot['requested_at']);
        $this->assertSame(now()->toISOString(), $snapshot['observed_at']);
        $this->assertSame(now()->addSeconds(30)->toISOString(), $snapshot['expires_at']);
        $this->assertSame(hash('sha256', $body), $snapshot['response_sha256']);
        $raw = $this->wallet->fresh()->raw_data;
        $this->assertSame($snapshot['available'], $raw['provider_available_balance']);
        $this->assertEquals($snapshot, $raw['dynamic_authenticated_availability']);
        $this->assertSame('2000.00000000', $this->wallet->fresh()->ledger_balance);
        $this->assertSame('2000.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('0.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->count());
    }

    public static function changedBindings(): array
    {
        return array_map(static fn ($binding) => [$binding], [
            'wallet', 'customer', 'account_wallet', 'wallet_owner', 'account_owner', 'provider',
            'provider_code', 'provider_status', 'currency', 'origin', 'unknown_origin', 'client', 'credential', 'eligibility',
        ]);
    }

    #[DataProvider('changedBindings')]
    public function test_changes_after_authenticated_http_cannot_persist_an_observation(string $binding): void
    {
        $raw = $this->wallet->raw_data;
        $this->response('[{"curSymbol":"USD","balance":1}]', function () use ($binding): void {
            switch ($binding) {
                case 'wallet': $this->wallet->update(['external_account_id' => 'changed']); break;
                case 'customer': $this->account->update(['external_customer_id' => 'changed']); break;
                case 'account_wallet': $this->account->update(['external_account_id' => 'changed']); break;
                case 'wallet_owner': $this->wallet->update(['user_id' => User::factory()->create()->id]); break;
                case 'account_owner': $this->account->update(['user_id' => User::factory()->create()->id]); break;
                case 'provider':
                    $provider = IntegrationProvider::query()->create(['code' => 'other', 'name' => 'Other fixture', 'status' => 'active']);
                    $this->account->update(['provider_id' => $provider->id]);
                    $this->wallet->update(['provider_id' => $provider->id]);
                    break;
                case 'currency': $this->wallet->update(['currency' => 'EUR']); break;
                case 'provider_code': IntegrationProvider::query()->findOrFail($this->account->provider_id)->update(['code' => 'changed']); break;
                case 'provider_status': IntegrationProvider::query()->findOrFail($this->account->provider_id)->update(['status' => 'inactive']); break;
                case 'origin': config(['services.nium.base_url' => 'https://gateway.nium.com']); break;
                case 'unknown_origin': config(['services.nium.base_url' => 'https://unknown.nium.com']); break;
                case 'client': config(['services.nium.client_id' => 'changed-client']); break;
                case 'credential': config(['services.nium.auth.header_value' => 'changed-synthetic-key']); break;
                case 'eligibility': $this->account->update(['provider_status' => 'blocked']); break;
            }
        });
        try {
            $this->snapshots->refresh($this->owner, $this->wallet);
            $this->fail('Changed scope was accepted.');
        } catch (RuntimeException) {
            $this->assertSame($raw, $this->wallet->fresh()->raw_data);
            $this->assertSame(0, LedgerEntry::query()->count());
        }
    }

    public function test_expiry_while_acquiring_persistence_locks_rejects_snapshot(): void
    {
        $this->response('[{"curSymbol":"USD","balance":1}]', function (): void {
            Event::listen('eloquent.retrieved: '.Balance::class, fn () => $this->travelTo(now()->addSeconds(30)));
        });
        $this->expectExceptionMessage('dynamic_wallet_snapshot_binding_changed');
        $this->snapshots->refresh($this->owner, $this->wallet);
    }

    public function test_zero_balance_observation_does_not_release_an_existing_local_reservation(): void
    {
        $this->source->review = $this->usdReview();
        $this->confirmDynamic();
        $reservation = NiumDynamicReservation::query()->sole()->getAttributes();
        $entries = LedgerEntry::query()->count();
        $this->response('[{"curSymbol":"USD","balance":0,"withHoldingBalance":2}]');
        $snapshot = $this->snapshots->refresh($this->owner, $this->wallet);
        $this->assertSame('0.00000000', $snapshot['available']);
        $this->assertSame('2000.00000000', $this->wallet->fresh()->ledger_balance);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->available_balance);
        $this->assertSame('1000.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertSame($reservation, NiumDynamicReservation::query()->sole()->getAttributes());
        $this->assertSame($entries, LedgerEntry::query()->count());
        $this->assertSame('held', NiumDynamicPayoutOutbox::query()->sole()->state);
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->sole()->attempts);
    }

    public function test_unresolved_payout_still_blocks_wallet_observation_persistence(): void
    {
        $this->source->review = $this->usdReview();
        $this->confirmDynamic();
        NiumDynamicPayoutOutbox::query()->sole()->forceFill(['state' => 'claimed'])->save();
        $this->response('[{"curSymbol":"USD","balance":2000}]');
        $this->expectExceptionMessage('dynamic_wallet_has_unresolved_payout');
        $this->snapshots->refresh($this->owner, $this->wallet);
    }

    public function test_a_response_without_the_authenticated_dispatch_callback_has_no_authority(): void
    {
        $this->nium->shouldReceive('get')->once()->andReturn(new Response(new PsrResponse(200, [],
            '[{"curSymbol":"USD","balance":1}]')));
        $this->expectExceptionMessage('dynamic_wallet_authenticated_snapshot_unavailable');
        $this->snapshots->refresh($this->owner, $this->wallet);
    }

    public function test_unknown_origin_is_rejected_before_wallet_http(): void
    {
        config(['services.nium.base_url' => 'https://unknown.nium.com']);
        $this->nium->shouldNotReceive('get');
        $this->expectExceptionMessage('dynamic_provider_environment_unverified');
        $this->snapshots->refresh($this->owner, $this->wallet);
    }

    public static function invalidOutgoingRequests(): array
    {
        $path = '/api/v1/client/fixture-client/customer/fixture-customer/wallet/fixture-wallet';
        return [
            ['POST', 'https://gatewaysandbox.nium.com'.$path, 'synthetic-wallet-test-key'],
            ['GET', 'https://gateway.nium.com'.$path, 'synthetic-wallet-test-key'],
            ['GET', 'https://gatewaysandbox.nium.com'.$path.'/transactions', 'synthetic-wallet-test-key'],
            ['GET', 'https://gatewaysandbox.nium.com'.$path.'?customer=other', 'synthetic-wallet-test-key'],
            ['GET', 'https://gatewaysandbox.nium.com'.$path, 'wrong-synthetic-key'],
            ['GET', 'https://gatewaysandbox.nium.com'.$path, null],
        ];
    }

    #[DataProvider('invalidOutgoingRequests')]
    public function test_method_endpoint_origin_and_credentials_are_checked_at_dispatch(string $method, string $url, ?string $key): void
    {
        $this->nium->shouldReceive('get')->once()->andReturnUsing(function ($path, $query, $user, $operation,
            $reference, $beforeDispatch) use ($method, $url, $key): Response {
            $beforeDispatch(new Request(new PsrRequest($method, $url, $key === null ? [] : ['x-api-key' => $key])));
            $this->fail('Untrusted outgoing Wallet request was accepted.');
        });
        $this->expectExceptionMessage('dynamic_wallet_snapshot_binding_changed');
        $this->snapshots->refresh($this->owner, $this->wallet);
    }

    public function test_missing_authenticated_credentials_are_rejected_before_http(): void
    {
        config(['services.nium.auth.header_value' => null]);
        $this->nium->shouldNotReceive('get');
        $this->expectExceptionMessage('dynamic_wallet_authenticated_credentials_unavailable');
        $this->snapshots->refresh($this->owner, $this->wallet);
    }
}
