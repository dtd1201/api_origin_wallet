<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NiumDynamicIntent;
use App\Services\Nium\DynamicConfirmation\NiumDynamicPayoutTransport;
use App\Services\Nium\NiumService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Mockery;
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
}
