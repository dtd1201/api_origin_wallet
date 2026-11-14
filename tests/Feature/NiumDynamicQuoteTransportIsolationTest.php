<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutQuoteRequest;
use App\Models\FxQuote;
use App\Models\LedgerEntry;
use App\Models\NiumDynamicIntent;
use App\Models\Transfer;
use App\Services\Nium\DynamicConfirmation\ExactProviderJson;
use App\Services\Nium\NiumService;
use App\Services\Nium\NiumSgdAdmissionFence;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesDynamicConfirmationFixtures;
use Tests\Support\OwnerFirstPostgresDatabase;
use Tests\TestCase;

/** Real transport boundary with HTTP faked; no live provider or legacy SGD activation. */
final class NiumDynamicQuoteTransportIsolationTest extends TestCase
{
    use OwnerFirstPostgresDatabase;
    use CreatesDynamicConfirmationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDynamicFixtures('2000');
        config(['services.nium.base_url' => 'https://gatewaysandbox.nium.com',
            'services.nium.auth.mode' => 'header', 'services.nium.auth.header_name' => 'x-api-key',
            'services.nium.auth.header_value' => 'synthetic-offline-key',
            'services.nium.webhook.static_header_name' => 'x-partner-key',
            'services.nium.webhook.static_header_value' => 'synthetic-offline-webhook-key',
            'services.nium.client_id' => 'fixture-client', 'services.nium.payout_fx_enabled' => true,
            'services.nium.health_endpoint' => '/api/v1/client/{clientHashId}',
            'services.nium.customer_create_endpoint' => '/api/v5/client/{clientHashId}/customers',
            'services.nium.customer_list_endpoint' => '/api/v5/client/{clientHashId}/customers',
            'services.nium.customer_get_endpoint' => '/api/v5/client/{clientHashId}/customer/{customerHashId}',
            'services.nium.quote_endpoint' => '/api/v1/client/{clientHashId}/quotes',
            'sgd_admission.directory' => __DIR__.'/nonexistent-dynamic-test-fence']);
        Http::preventStrayRequests();
        Http::fake(['https://gatewaysandbox.nium.com/api/v1/client/fixture-client/quotes' => Http::response(['id' => 'offline-quote'])]);
    }

    private function quote(string $currency = 'SGD', PayoutAmountMode $mode = PayoutAmountMode::Source): PayoutQuoteRequest
    {
        return new PayoutQuoteRequest('fixture-customer', 'USD', $currency, $mode,
            $mode === PayoutAmountMode::Source ? '980' : '784');
    }

    public static function tuplesAndModes(): array
    {
        return [['SGD', PayoutAmountMode::Source], ['SGD', PayoutAmountMode::Destination],
            ['GBP', PayoutAmountMode::Source], ['GBP', PayoutAmountMode::Destination]];
    }

    #[DataProvider('tuplesAndModes')]
    public function test_typed_dynamic_quote_uses_its_own_transport_without_opening_the_legacy_fence(string $currency, PayoutAmountMode $mode): void
    {
        $quote = $this->quote($currency, $mode);
        $checked = false;
        $response = app(NiumService::class)->post('/api/v1/client/fixture-client/quotes',
            json_decode($quote->json(), true, flags: JSON_THROW_ON_ERROR), $this->owner,
            operation: 'dynamic_payout_reference_quote',
            beforeDispatch: function (Request $request) use ($quote, &$checked): void {
                $this->assertSame(ExactProviderJson::decode($quote->json()), ExactProviderJson::decode($request->body()));
                $checked = true;
            }, dynamicReferenceQuote: $quote);
        $this->assertTrue($response->successful());
        $this->assertTrue($checked);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gatewaysandbox.nium.com/api/v1/client/fixture-client/quotes'
            && $request->hasHeader('x-api-key', 'synthetic-offline-key')
            && $request['destinationCurrencyCode'] === $currency);
        $this->assertSame(0, Transfer::query()->count());
        $this->assertSame(0, FxQuote::query()->count());
        $this->assertSame(0, NiumDynamicIntent::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public static function invalidBindings(): array
    {
        return [['wrong amount'], ['wrong customer'], ['wrong endpoint'], ['legacy transfer'],
            ['wrong operation'], ['missing callback'], ['quote disabled'], ['changed at dispatch']];
    }

    #[DataProvider('invalidBindings')]
    public function test_dynamic_quote_transport_refuses_changed_or_incomplete_bindings(string $change): void
    {
        $quote = $this->quote();
        $payload = json_decode($quote->json(), true, flags: JSON_THROW_ON_ERROR);
        if ($change === 'wrong amount') $payload['sourceAmount'] = 1000;
        if ($change === 'wrong customer') $quote = new PayoutQuoteRequest('another-customer', 'USD', 'SGD', PayoutAmountMode::Source, '980');
        if ($change === 'wrong customer') $payload = json_decode($quote->json(), true, flags: JSON_THROW_ON_ERROR);
        if ($change === 'quote disabled') config(['nium_dynamic_quotes.quote_contract_enabled' => false]);
        try {
            app(NiumService::class)->post(
                $change === 'wrong endpoint' ? '/api/v1/client/fixture-client/customer/fixture-customer/wallet/fixture-wallet/remittance'
                    : '/api/v1/client/fixture-client/quotes',
                $payload, $this->owner, $change === 'legacy transfer' ? 123 : null,
                operation: $change === 'wrong operation' ? 'legacy_quote' : 'dynamic_payout_reference_quote',
                beforeDispatch: $change === 'missing callback' ? null : static function () use ($change): void {
                    if ($change === 'changed at dispatch') config(['nium_dynamic_quotes.quote_contract_enabled' => false]);
                }, dynamicReferenceQuote: $quote);
            $this->fail('An unbound Dynamic quote reached transport.');
        } catch (RuntimeException $exception) {
            $this->assertSame('dynamic_quote_transport_binding_changed', $exception->getMessage());
        }
        Http::assertNothingSent();
        $this->assertSame(0, Transfer::query()->count());
        $this->assertSame(0, NiumDynamicIntent::query()->count());
    }

    public function test_an_operation_label_alone_cannot_bypass_the_standard_sgd_claim(): void
    {
        $quote = $this->quote();
        try {
            app(NiumService::class)->post('/api/v1/client/fixture-client/quotes',
                json_decode($quote->json(), true, flags: JSON_THROW_ON_ERROR), $this->owner,
                operation: 'dynamic_payout_reference_quote', beforeDispatch: static function (): void {});
            $this->fail('A label bypassed the legacy quote fence.');
        } catch (RuntimeException $exception) {
            $this->assertSame(NiumSgdAdmissionFence::CLOSED, $exception->getMessage());
        }
        Http::assertNothingSent();
    }
}
