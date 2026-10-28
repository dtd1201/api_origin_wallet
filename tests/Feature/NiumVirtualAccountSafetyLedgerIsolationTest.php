<?php

namespace Tests\Feature;

use App\Models\NiumVirtualAccountAssignmentScope;
use App\Models\NiumVirtualAccountDispatchAttempt;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\NiumVirtualAccountRequest;
use App\Services\Nium\NiumPaymentIdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesNiumVirtualAccountLedgerFixtures;
use Tests\TestCase;

class NiumVirtualAccountSafetyLedgerIsolationTest extends TestCase
{
    use CreatesNiumVirtualAccountLedgerFixtures, RefreshDatabase;

    #[DataProvider('baselineResponses')]
    public function test_existing_assignment_keeps_baseline_and_payload_without_using_the_new_ledger(int $baselineStatus): void
    {
        Http::preventStrayRequests();
        config([
            'services.nium.base_url' => 'https://gateway.sandbox.nium.test',
            'services.nium.client_id' => 'client-test',
            'services.nium.auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => 'test-key'],
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'test-partner-key',
        ]);
        $account = $this->ledgerAccount();
        $before = $account->fresh()->getAttributes();
        Http::fake(fn (Request $request) => $request->method() === 'GET'
            ? Http::response(['content' => []], $baselineStatus)
            : Http::response(['uniquePaymentId' => 'VA-EXISTING', 'currencyCode' => 'USD']));

        try {
            app(NiumPaymentIdService::class)->assign($account, 'USD', 'COLLECTION_ACCOUNT', 'LOCAL', operationId: 'live-isolation');
            $this->assertSame(200, $baselineStatus);
        } catch (RuntimeException $exception) {
            $this->assertSame(404, $baselineStatus, $exception->getMessage());
            $this->assertStringContainsString('preflight is unresolved', $exception->getMessage());
        }

        $calls = Http::recorded();
        $this->assertSame('GET', $calls[0][0]->method());
        Http::assertSentCount($baselineStatus === 200 ? 2 : 1);
        if ($baselineStatus === 200) {
            $this->assertSame('POST', $calls[1][0]->method());
            $this->assertSame([
                'currency' => 'USD', 'accountCategory' => 'COLLECTION_ACCOUNT', 'accountType' => 'LOCAL',
            ], $calls[1][0]->data());
            $this->assertStringEndsWith('/api/v2/client/client-test/customer/'.$account->external_customer_id.'/wallet/'.$account->external_account_id.'/paymentId', $calls[1][0]->url());
        }
        $this->assertSame($baselineStatus === 200 ? Operation::ASSIGNED : Operation::PREFLIGHT_FAILED, Operation::query()->sole()->status);
        $this->assertSame($before, $account->fresh()->getAttributes());
        $this->assertSame(0, NiumVirtualAccountRequest::query()->count());
        $this->assertSame(0, NiumVirtualAccountDispatchAttempt::query()->count());
        $this->assertSame(0, NiumVirtualAccountAssignmentScope::query()->count());
    }

    public static function baselineResponses(): array
    {
        return [[200], [404]];
    }
}
