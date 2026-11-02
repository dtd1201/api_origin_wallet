<?php

namespace Tests\Unit;

use App\Services\Nium\NiumPayoutQuoteContract;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NiumPayoutQuoteContractTest extends TestCase
{
    protected function setUp(): void
    {
        Carbon::setTestNow('2026-11-02T02:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    private function response(): array
    {
        return ['id' => 'synthetic-payout-quote', 'quoteType' => 'payout', 'quoteIntent' => 'EXECUTABLE',
            'sourceAmount' => '5.00', 'sourceCurrencyCode' => 'USD', 'destinationCurrencyCode' => 'SGD',
            'netExchangeRate' => '1.276738080', 'expiryTime' => '2026-11-02T02:05:00.999Z'];
    }

    public function test_request_uses_documented_fifteen_minute_payout_lock_and_fixed_source_amount(): void
    {
        $this->assertSame([
            'sourceCurrencyCode' => 'USD', 'destinationCurrencyCode' => 'SGD', 'sourceAmount' => 5,
            'customerHashId' => 'synthetic-customer', 'quoteType' => 'payout', 'quoteIntent' => 'EXECUTABLE',
            'conversionSchedule' => 'immediate', 'executionType' => 'manual', 'lockPeriod' => '15_mins',
        ], NiumPayoutQuoteContract::request('synthetic-customer', 'USD', 'SGD', '5.00'));
    }

    public function test_zero_markup_is_not_provider_fee_confirmation_or_permission_to_extend_expiry(): void
    {
        $response = NiumPayoutQuoteContract::decodeResponse(json_encode([
            ...$this->response(), 'exchangeRate' => '1.276738080', 'markupRate' => 0.0, 'clientMarkupRate' => '0.000',
        ], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $contract = NiumPayoutQuoteContract::parse($response, 'USD', 'SGD', '5');
        // Even a request for 15 minutes must use the actual shorter returned expiry.
        $this->assertSame('2026-11-02T02:05:00.000000Z', $contract['expires_at']);
        $this->assertArrayNotHasKey('fee_amount', $contract);
        $this->assertArrayNotHasKey('provider_verified', $contract);
    }

    public function test_quote_is_bound_to_exact_usd_principal_and_never_fabricates_final_credit(): void
    {
        $contract = NiumPayoutQuoteContract::parse($this->response(), 'USD', 'SGD', '5.00000000');
        $this->assertSame('5', $contract['source_amount']);
        $this->assertSame('1.276738080', $contract['rate']);
        $this->assertSame('2026-11-02T02:05:00.000000Z', $contract['expires_at']);
        $this->assertNull($contract['conversion_amount']);
        $this->assertArrayNotHasKey('beneficiary_credit', $contract);
        $this->assertArrayNotHasKey('audit_id', $contract);
    }

    public function test_explicit_conversion_amount_remains_separate_from_final_credit(): void
    {
        $contract = NiumPayoutQuoteContract::parse([...$this->response(), 'destinationAmount' => '6.3836904'], 'USD', 'SGD', '5');
        $this->assertSame('6.3836904', $contract['conversion_amount']);
        $this->assertArrayNotHasKey('beneficiary_credit', $contract);
    }

    public static function numericJsonRates(): array
    {
        return [['1.276738080', '1.276738080'], ['1.276738080e0', '1.276738080'], ['1276738080e-9', '1.276738080']];
    }

    #[DataProvider('numericJsonRates')]
    public function test_numeric_json_preserves_exact_provider_decimal_tokens(string $rate, string $expected): void
    {
        $json = '{"nested":{"netExchangeRate":9.99},"text":'.json_encode('"netExchangeRate":99').','
            .'"id":"synthetic-payout-quote","quoteType":"payout","sourceAmount":5.00,'
            .'"netExchangeRate":'.$rate.',"destinationAmount":6.3836904,"expiryTime":"2026-11-02T02:05:00Z"}';
        $decoded = NiumPayoutQuoteContract::decodeResponse($json);
        $this->assertSame($expected, $decoded['netExchangeRate']);
        $this->assertSame('5.00', $decoded['sourceAmount']);
        $contract = NiumPayoutQuoteContract::parse($decoded, 'USD', 'SGD', '5');
        $this->assertSame($expected, $contract['rate']);
        $this->assertSame('6.3836904', $contract['conversion_amount']);
    }

    public function test_duplicate_top_level_values_use_the_same_last_value_as_json_decode(): void
    {
        $decoded = NiumPayoutQuoteContract::decodeResponse('{"netExchangeRate":9.99,"netExchangeRate":1.276738080}');
        $this->assertSame('1.276738080', $decoded['netExchangeRate']);
    }

    public function test_extreme_numeric_json_exponents_fail_before_decimal_expansion(): void
    {
        $this->expectException(RuntimeException::class);
        NiumPayoutQuoteContract::decodeResponse('{"netExchangeRate":1e999999999}');
    }

    public static function invalidQuotes(): array
    {
        return [
            'balance transfer' => ['quoteType', 'balance_transfer'], 'indicative' => ['quoteIntent', 'INDICATIVE'],
            'scheduled' => ['conversionSchedule', 'scheduled'], 'automatic' => ['executionType', 'automatic'],
            'no quote ID' => ['id', null], 'unsafe quote ID' => ['id', '../wrong'],
            'legacy integer audit ID' => ['id', 123456], 'wrong principal' => ['sourceAmount', '5.01'],
            'missing principal' => ['sourceAmount', null], 'wrong source currency' => ['sourceCurrencyCode', 'SGD'],
            'wrong destination' => ['destinationCurrencyCode', 'USD'], 'zero rate' => ['netExchangeRate', '0'],
            'binary float rate' => ['netExchangeRate', 1.27673808],
            'unrepresentable rate precision' => ['netExchangeRate', '1.12345678901'],
            'expired at boundary' => ['expiryTime', '2026-11-02T02:00:00Z'],
            'expired' => ['expiryTime', '2026-11-02T01:59:59Z'],
            'relative expiry' => ['expiryTime', '+5 minutes'], 'invalid calendar date' => ['expiryTime', '2026-02-30T02:05:00Z'],
            'no expiry' => ['expiryTime', null], 'zero conversion' => ['destinationAmount', '0'],
            'NIUM markup' => ['markupRate', '0.001'], 'Origin markup' => ['clientMarkupRate', '0.001'],
            'unknown markup' => ['markupRate', null], 'negative markup' => ['markupRate', '-0.001'],
            'implicit markup' => ['exchangeRate', '1.29'],
            'unmapped lock fee' => ['feeAmount', '0.01'], 'unmapped fee structure' => ['fees', []],
            'unmapped debit' => ['totalWalletDebit', '5.01'],
        ];
    }

    #[DataProvider('invalidQuotes')]
    public function test_invalid_provider_terms_fail_closed(string $field, mixed $value): void
    {
        $this->expectException(RuntimeException::class);
        NiumPayoutQuoteContract::parse([...$this->response(), $field => $value], 'USD', 'SGD', '5');
    }
}
