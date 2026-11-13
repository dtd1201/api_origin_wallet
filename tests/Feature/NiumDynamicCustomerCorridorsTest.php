<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FxQuote;
use App\Models\LedgerEntry;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use App\Models\NiumDynamicReservation;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutEligibility;
use App\Services\Nium\NiumService;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Support\DynamicConfirmationDatabaseTestCase;

/** Synthetic provider boundary only. No HTTP, dispatch, admission or financial writes. */
final class NiumDynamicCustomerCorridorsTest extends DynamicConfirmationDatabaseTestCase
{
    use CreatesDynamicConfirmationFixtures;

    private NiumService $nium;
    private array $binding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDynamicFixtures('2000');
        $this->wallet->update(['currency' => 'USD']);
        config(['services.nium.base_url' => 'https://api.spend.nium.com',
            'services.nium.client_id' => 'fixture-client',
            'nium_dynamic_confirmation.enabled' => false,
            'nium_dynamic_confirmation.automatic_dispatch_enabled' => false,
            'nium_dynamic_quotes.quote_contract_enabled' => false,
            'nium_dynamic_quotes.confirmation_contract_enabled' => false]);
        $this->binding = ['base_url' => 'https://api.spend.nium.com', 'client' => 'fixture-client',
            'customer' => 'fixture-customer', 'wallet' => 'fixture-wallet', 'owner' => (string) $this->owner->id,
            'provider_account_id' => $this->account->id, 'balance_id' => $this->wallet->id,
            'beneficiary_id' => $this->beneficiary->id];
        $this->nium = Mockery::mock(NiumService::class)->makePartial();
        $this->nium->shouldReceive('clientId')->andReturnUsing(static fn (): string => (string) config('services.nium.client_id'));
        $this->nium->shouldNotReceive('post');
        $this->nium->shouldNotReceive('get')->withArgs(static fn ($path, $query, $user, $operation): bool =>
            $operation === 'dynamic_payout_schema');
        $this->app->instance(NiumService::class, $this->nium);
    }

    private function row(): array
    {
        return ['destination_currency' => 'GBP', 'payout_method' => 'LOCAL',
            'destination_country' => ['GB'], 'routing_code_type' => 'synthetic-route-a'];
    }

    private function body(array $rows, array $echoes = []): string
    {
        return json_encode(['requestId' => 'synthetic-request', 'correlationId' => 'synthetic-correlation',
            'timestamp' => 'synthetic-timestamp', 'data' => $rows, ...$echoes], JSON_THROW_ON_ERROR);
    }

    private function expectCorridors(string $body, ?\Closure $outgoing = null, ?\Closure $during = null): void
    {
        $url = 'https://api.spend.nium.com/api/v1/client/fixture-client/customer/fixture-customer/supportedCorridors';
        $this->nium->shouldReceive('get')->once()->withArgs(function ($path, $query, $user, $operation): bool {
            $this->assertSame('/api/v1/client/fixture-client/customer/fixture-customer/supportedCorridors', $path);
            $this->assertSame([], $query);
            $this->assertSame($this->owner->id, $user->id);
            return $operation === 'dynamic_customer_corridors';
        })->andReturnUsing(function ($path, $query, $user, $operation, $reference, $beforeDispatch) use ($body, $url, $outgoing, $during): Response {
            $request = $outgoing === null ? new Request(new PsrRequest('GET', $url)) : $outgoing($url);
            if ($request !== null) {
                $beforeDispatch($request);
            }
            if ($during !== null) {
                $during();
            }
            return new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], $body));
        });
    }

    private function assertRefused(string $reason): void
    {
        try {
            app(DynamicPayoutEligibility::class)->options($this->owner, $this->beneficiary, $this->binding, 'HK');
            $this->fail('Listing cannot grant payout eligibility.');
        } catch (RuntimeException $exception) {
            $this->assertSame($reason, $exception->getMessage());
        }
        $this->assertSame(0, FxQuote::query()->count());
        $this->assertSame(0, NiumDynamicIntent::query()->count());
        $this->assertSame(0, NiumDynamicReservation::query()->count());
        $this->assertSame(0, NiumDynamicPayoutOutbox::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame('0.00000000', $this->wallet->fresh()->reserved_balance);
        $this->assertFalse(config('nium_dynamic_confirmation.automatic_dispatch_enabled'));
        $this->assertFalse(config('nium_dynamic_quotes.quote_contract_enabled'));
    }

    public function test_two_spend_records_are_read_but_authorization_is_refused_before_schema(): void
    {
        $this->expectCorridors($this->body([$this->row(), [...$this->row(), 'routing_code_type' => 'synthetic-route-b']]));
        $this->assertRefused('dynamic_customer_corridor_legal_entity_unverified');
    }

    public function test_missing_lookup_policy_is_not_false_even_with_a_matching_legal_entity(): void
    {
        $this->expectCorridors($this->body([[...$this->row(), 'client_legal_entity' => ['LEGAL_ENTITY_HK']]]));
        $this->assertRefused('dynamic_customer_corridor_lookup_policy_unverified');
    }

    public function test_synthetic_extra_fields_do_not_open_the_full_spend_contract(): void
    {
        $this->expectCorridors($this->body([[...$this->row(), 'client_legal_entity' => ['LEGAL_ENTITY_HK'], 'ignoreLookup' => false]]));
        $this->assertRefused('dynamic_production_customer_corridors_contract_unverified');
    }

    public static function wrongBindings(): array
    {
        return [
            ['base_url', 'https://gateway.nium.com', 'dynamic_provider_scope_unverified'],
            ['base_url', 'https://gatewaysandbox.nium.com', 'dynamic_provider_scope_unverified'],
            ['client', 'foreign-client', 'dynamic_eligibility_scope_changed'],
            ['customer', 'foreign-customer', 'dynamic_eligibility_scope_changed'],
            ['wallet', 'foreign-wallet', 'dynamic_eligibility_scope_changed'],
            ['owner', '999999', 'dynamic_eligibility_scope_changed'],
        ];
    }

    #[DataProvider('wrongBindings')]
    public function test_wrong_binding_is_rejected_before_any_provider_read(string $key, string $value, string $reason): void
    {
        $this->binding[$key] = $value;
        $this->nium->shouldNotReceive('get');
        $this->assertRefused($reason);
    }

    public static function foreignResponseEchoes(): array
    {
        return [[['customerHashId' => 'foreign-customer']], [['clientHashId' => 'foreign-client']],
            [['customerHashId' => null]], [['clientHashId' => null]]];
    }

    #[DataProvider('foreignResponseEchoes')]
    public function test_foreign_or_null_scope_echo_is_not_accepted(array $echoes): void
    {
        $this->expectCorridors($this->body([$this->row()], $echoes));
        $this->assertRefused('dynamic_eligibility_scope_changed');
    }

    public static function wrongRequests(): array
    {
        return [['origin'], ['customer'], ['method'], ['query'], ['missing_callback']];
    }

    #[DataProvider('wrongRequests')]
    public function test_actual_request_must_match_the_bound_customer_get(string $mutation): void
    {
        $this->expectCorridors($this->body([$this->row()]), outgoing: static function ($url) use ($mutation): ?Request {
            if ($mutation === 'missing_callback') {
                return null;
            }
            if ($mutation === 'origin') {
                $url = str_replace('api.spend.nium.com', 'gateway.nium.com', $url);
            }
            if ($mutation === 'customer') {
                $url = str_replace('fixture-customer', 'foreign-customer', $url);
            }
            if ($mutation === 'query') {
                $url .= '?customerHashId=foreign-customer';
            }
            return new Request(new PsrRequest($mutation === 'method' ? 'POST' : 'GET', $url));
        });
        $this->assertRefused('dynamic_eligibility_scope_changed');
    }

    public function test_customer_rebinding_during_the_read_invalidates_the_response(): void
    {
        $this->expectCorridors($this->body([$this->row()]), during: fn () =>
            $this->account->update(['external_customer_id' => 'foreign-customer']));
        $this->assertRefused('dynamic_eligibility_scope_changed');
    }

    public function test_origin_change_before_dispatch_is_not_a_same_production_identity_fallback(): void
    {
        $this->expectCorridors($this->body([$this->row()]), outgoing: static function ($url): Request {
            config(['services.nium.base_url' => 'https://gateway.nium.com']);
            return new Request(new PsrRequest('GET', $url));
        });
        $this->assertRefused('dynamic_provider_scope_unverified');
    }

    public function test_unknown_customer_corridor_remains_denied(): void
    {
        $this->expectCorridors($this->body([[...$this->row(), 'destination_country' => ['US']]]));
        $this->assertRefused('dynamic_customer_corridor_not_permitted');
    }

    public function test_multiple_authorized_candidates_remain_ambiguous(): void
    {
        $row = [...$this->row(), 'client_legal_entity' => ['LEGAL_ENTITY_HK'], 'ignoreLookup' => false];
        $this->expectCorridors($this->body([$row, [...$row, 'routing_code_type' => 'synthetic-route-b']]));
        $this->assertRefused('dynamic_customer_corridor_ambiguous');
    }
}
