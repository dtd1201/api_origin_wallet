<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NiumDynamicIntent;
use App\Services\Nium\DynamicConfirmation\NiumDynamicPayoutTransport;
use App\Services\Nium\NiumService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Concerns\CreatesFinalizedUsdConfirmationFixtures;
use Tests\Support\DynamicConfirmationDatabaseTestCase;

/** Provider service is mocked; no authenticated requests or actual provider evidence. */
final class NiumDynamicPayoutTransportTest extends DynamicConfirmationDatabaseTestCase
{
    use CreatesDynamicConfirmationFixtures;
    use CreatesFinalizedUsdConfirmationFixtures;

    private NiumService $nium;
    private NiumDynamicIntent $intent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDynamicFixtures('2000');
        $this->wallet->update(['currency' => 'USD']);
        $this->source->review = $this->usdReview();
        $this->intent = $this->confirmDynamic();
        config(['services.nium.base_url' => 'https://gatewaysandbox.nium.com']);
        $this->nium = Mockery::mock(NiumService::class)->makePartial();
        $this->nium->shouldReceive('clientId')->andReturn('fixture-client');
    }

    private function response(string $body): Response
    {
        return new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], $body));
    }

    public function test_all_pages_are_read_without_remittance_filter_and_original_amount_tokens_are_preserved(): void
    {
        foreach ([0, 1] as $page) {
            $this->nium->shouldReceive('getDynamicPayoutTransactions')->once()
                ->withArgs(function ($path, $query, $user, $reference) use ($page): bool {
                    return $path === '/api/v1/client/fixture-client/customer/fixture-customer/wallet/fixture-wallet/transactions'
                        && $query['page'] === $page && $query['size'] === 100
                        && ! isset($query['systemReferenceNumber']) && $query['startDate'] === '2029-12-31'
                        && $query['endDate'] === '2030-01-01' && $user->id === $this->owner->id
                        && $reference === $this->intent->id;
                })->andReturn($this->response('{"content":[{"authCode":"row-'.$page.'",'
                    .'"settlementAuthAmount":9007199254740993.01}],"totalPages":2,"totalElements":2}'));
        }
        $batch = (new NiumDynamicPayoutTransport($this->nium))->transactions($this->intent);
        $this->assertCount(2, $batch['records']);
        $this->assertCount(2, $batch['response_sha256']);
        $this->assertSame('9007199254740993.01', $batch['records'][0]['settlementAuthAmount']);
    }

    public function test_incomplete_page_is_not_a_complete_fee_receipt(): void
    {
        $this->nium->shouldReceive('getDynamicPayoutTransactions')->once()->andReturn($this->response(
            '{"content":[{"authCode":"row"}],"totalPages":1,"totalElements":2}'));
        $this->expectExceptionMessage('dynamic_transaction_snapshot_incomplete');
        (new NiumDynamicPayoutTransport($this->nium))->transactions($this->intent);
    }

    public function test_repeated_record_across_pages_rejects_the_snapshot(): void
    {
        $this->nium->shouldReceive('getDynamicPayoutTransactions')->twice()->andReturn($this->response(
            '{"content":[{"authCode":"same-row"}],"totalPages":2,"totalElements":2}'));
        $this->expectExceptionMessage('dynamic_transaction_identity_unverified');
        (new NiumDynamicPayoutTransport($this->nium))->transactions($this->intent);
    }

    public function test_missing_pagination_metadata_cannot_be_treated_as_an_exhaustive_fee_inventory(): void
    {
        $this->nium->shouldReceive('getDynamicPayoutTransactions')->once()->andReturn($this->response(
            '{"content":[{"authCode":"row"}]}'));
        $this->expectExceptionMessage('dynamic_transaction_pagination_unverified');
        (new NiumDynamicPayoutTransport($this->nium))->transactions($this->intent);
    }

    public function test_changing_transaction_count_between_pages_rejects_the_snapshot(): void
    {
        $this->nium->shouldReceive('getDynamicPayoutTransactions')->twice()->andReturn(
            $this->response('{"content":[{"authCode":"row-1"}],"totalPages":2,"totalElements":2}'),
            $this->response('{"content":[{"authCode":"row-2"}],"totalPages":2,"totalElements":3}'));
        $this->expectExceptionMessage('dynamic_transaction_snapshot_incomplete');
        (new NiumDynamicPayoutTransport($this->nium))->transactions($this->intent);
    }

    public function test_sandbox_intent_cannot_be_reconciled_against_production(): void
    {
        config(['services.nium.base_url' => 'https://gateway.nium.com']);
        $this->nium->shouldNotReceive('getDynamicPayoutTransactions');
        $this->expectExceptionMessage('dynamic_provider_scope_unverified');
        (new NiumDynamicPayoutTransport($this->nium))->transactions($this->intent);
    }

    public static function unverifiedOrigins(): array
    {
        return [['https://unknown.nium.com'], ['http://gatewaysandbox.nium.com'], ['https://gatewaysandbox.nium.com/api']];
    }

    #[DataProvider('unverifiedOrigins')]
    public function test_unknown_origin_never_reaches_reconciliation_http(string $origin): void
    {
        config(['services.nium.base_url' => $origin]);
        $this->nium->shouldNotReceive('getDynamicPayoutTransactions');
        $this->expectExceptionMessage('dynamic_provider_environment_unverified');
        (new NiumDynamicPayoutTransport($this->nium))->transactions($this->intent);
    }

    public function test_verified_spend_transactions_read_preserves_null_ids_and_original_numeric_tokens(): void
    {
        config(['services.nium.base_url' => 'https://api.spend.nium.com']);
        $snapshot = $this->intent->confirmed_terms;
        $snapshot['context']['corridor']['environment'] = 'production';
        $snapshot['context']['provider_origin'] = 'https://api.spend.nium.com';
        $this->intent->forceFill(['confirmed_terms' => $snapshot]);
        $this->nium->shouldReceive('getDynamicPayoutTransactions')->once()->withArgs(function ($path, $query): bool {
            return $path === '/api/v1/client/fixture-client/customer/fixture-customer/wallet/fixture-wallet/transactions'
                && $query['page'] === 0 && $query['size'] === 100 && ! isset($query['externalId']);
        })->andReturn($this->response('{"content":[{"authCode":"RT-fixture-dynamic",'
            .'"walletHashId":"fixture-wallet","customerHashId":"fixture-customer","clientHashId":"fixture-client",'
            .'"externalId":null,"originalAuthorizationCode":null,"debit":true,"transactionType":"Remittance_Debit",'
            .'"settlementAuthAmount":999999999999.12345678,"settlementTransactionAmount":123.00000001,'
            .'"settlementStatus":"Settled","labels":{"beneficiaryId":"fixture-registered-payee"}}],"totalPages":1,"totalElements":1}'));
        $batch = (new NiumDynamicPayoutTransport($this->nium))->transactions($this->intent);
        $row = $batch['records'][0];
        $this->assertNull($row['externalId']);
        $this->assertNull($row['originalAuthorizationCode']);
        $this->assertTrue($row['debit']);
        $this->assertSame('999999999999.12345678', $row['settlementAuthAmount']);
        $this->assertSame('123.00000001', $row['settlementTransactionAmount']);
        $this->assertSame('fixture-registered-payee', $row['labels']['beneficiaryId']);
    }

    public function test_historical_production_gateway_snapshot_cannot_replay_on_spend_host(): void
    {
        config(['services.nium.base_url' => 'https://api.spend.nium.com']);
        $snapshot = $this->intent->confirmed_terms;
        $snapshot['context']['corridor']['environment'] = 'production';
        $this->intent->forceFill(['confirmed_terms' => $snapshot]);
        $this->nium->shouldNotReceive('getDynamicPayoutTransactions');
        $this->expectExceptionMessage('dynamic_provider_scope_unverified');
        (new NiumDynamicPayoutTransport($this->nium))->transactions($this->intent);
    }

    public function test_origin_change_during_pagination_cannot_be_accepted_as_a_complete_snapshot(): void
    {
        $this->nium->shouldReceive('getDynamicPayoutTransactions')->once()->andReturnUsing(function (): Response {
            config(['services.nium.base_url' => 'https://api.spend.nium.com']);
            return $this->response('{"content":[{"authCode":"row-0"}],"totalPages":2,"totalElements":2}');
        });
        $this->expectExceptionMessage('dynamic_provider_scope_unverified');
        (new NiumDynamicPayoutTransport($this->nium))->transactions($this->intent);
    }

    public function test_the_same_transaction_in_separate_get_snapshots_is_not_a_duplicate(): void
    {
        $this->nium->shouldReceive('getDynamicPayoutTransactions')->twice()->andReturn($this->response(
            '{"content":[{"authCode":"historical-row","externalId":null}],"totalPages":1,"totalElements":1}'));
        $transport = new NiumDynamicPayoutTransport($this->nium);
        $first = $transport->transactions($this->intent);
        $second = $transport->transactions($this->intent);
        $this->assertSame($first['records'], $second['records']);
        $this->assertCount(1, $second['records']);
    }

    public function test_empty_complete_snapshot_remains_distinct_from_a_missing_page(): void
    {
        $this->nium->shouldReceive('getDynamicPayoutTransactions')->once()->andReturn($this->response(
            '{"content":[],"totalPages":0,"totalElements":0}'));
        $this->assertSame([], (new NiumDynamicPayoutTransport($this->nium))->transactions($this->intent)['records']);
    }

    public static function incompleteSnapshots(): array
    {
        return [
            'empty page advertised as populated' => ['{"content":[],"totalPages":1,"totalElements":1}'],
            'more pages than records' => ['{"content":[{"authCode":"one"}],"totalPages":2,"totalElements":1}'],
            'total exceeds page capacity' => ['{"content":[{"authCode":"one"}],"totalPages":1,"totalElements":101}'],
            'zero pages with records' => ['{"content":[{"authCode":"one"}],"totalPages":0,"totalElements":0}'],
            'extra records' => ['{"content":[{"authCode":"one"},{"authCode":"two"}],"totalPages":1,"totalElements":1}'],
            'nonlist content' => ['{"content":{"row":{"authCode":"one"}},"totalPages":1,"totalElements":1}'],
        ];
    }

    #[DataProvider('incompleteSnapshots')]
    public function test_inconsistent_pagination_never_produces_a_complete_snapshot(string $body): void
    {
        $this->nium->shouldReceive('getDynamicPayoutTransactions')->once()->andReturn($this->response($body));
        $this->expectExceptionMessage('dynamic_transaction_snapshot_incomplete');
        (new NiumDynamicPayoutTransport($this->nium))->transactions($this->intent);
    }

    public function test_duplicate_within_one_page_is_rejected(): void
    {
        $this->nium->shouldReceive('getDynamicPayoutTransactions')->once()->andReturn($this->response(
            '{"content":[{"authCode":"same"},{"authCode":"same"}],"totalPages":1,"totalElements":2}'));
        $this->expectExceptionMessage('dynamic_transaction_identity_unverified');
        (new NiumDynamicPayoutTransport($this->nium))->transactions($this->intent);
    }

    public function test_a_missing_later_page_does_not_return_the_first_page_as_complete(): void
    {
        $this->nium->shouldReceive('getDynamicPayoutTransactions')->twice()->andReturn(
            $this->response('{"content":[{"authCode":"one"}],"totalPages":2,"totalElements":2}'),
            $this->response('{"content":[],"totalPages":2,"totalElements":2}'));
        $this->expectExceptionMessage('dynamic_transaction_snapshot_incomplete');
        (new NiumDynamicPayoutTransport($this->nium))->transactions($this->intent);
    }
}
