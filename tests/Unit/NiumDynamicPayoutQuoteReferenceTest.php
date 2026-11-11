<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Nium\DynamicMultiCurrency\Financial\FinalizedUsdPayoutPreparation;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutRateQuote;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NiumDynamicPayoutQuoteReferenceTest extends TestCase
{
    public static function modes(): array
    {
        return [[PayoutAmountMode::Source, '1000', 'sourceAmount', '980'],
            [PayoutAmountMode::Destination, '784', 'destinationAmount', '784']];
    }

    private function body(): string
    {
        return '{"id":"quote-1","quoteType":"payout","quoteIntent":"EXECUTABLE",'
            .'"customerHashId":"customer","sourceCurrencyCode":"USD","destinationCurrencyCode":"GBP",'
            .'"conversionSchedule":"immediate","executionType":"at_conversion_time","lockPeriod":"5_mins",'
            .'"sourceAmount":980,"destinationAmount":784,"netExchangeRate":0.800000000000000001,'
            .'"expiryTime":"2030-01-01T01:00:00Z"}';
    }

    #[DataProvider('modes')]
    public function test_exact_provider_rate_and_reference_amounts_never_become_fee_authority(
        PayoutAmountMode $mode, string $entered, string $field, string $amount): void
    {
        $request = FinalizedUsdPayoutPreparation::quoteRequestForAmount('customer', 'GBP', $mode, $entered);
        $this->assertStringContainsString('"'.$field.'":'.$amount, $request->json());
        $other = $mode === PayoutAmountMode::Source ? 'destinationAmount' : 'sourceAmount';
        $this->assertStringNotContainsString('"'.$other.'":', $request->json());
        $reference = PayoutRateQuote::referenceFromJson($this->body(), $request);
        $this->assertSame('980', $reference['sourceAmount']->value);
        $this->assertSame('784', $reference['destinationAmount']->value);
        $this->assertSame('0.800000000000000001', $reference['netExchangeRate']->value);
        $this->assertSame('1000', FinalizedUsdPayoutPreparation::plannedDebitForPrincipal($reference['sourceAmount'])->amount->value);
        $this->assertSame('quote-1', $reference['id']);
        $this->assertSame('2030-01-01T01:00:00+00:00', $reference['expiresAt']->format('c'));
        $this->assertArrayNotHasKey('feePlanId', $reference);
        $this->assertArrayNotHasKey('calculationReference', $reference);
    }

    public static function invalidResponses(): array
    {
        return [
            ['"executionType":"at_conversion_time"', '"executionType":"manual"'],
            ['"customerHashId":"customer"', '"customerHashId":"foreign"'],
            ['"sourceAmount":980', '"sourceAmount":981'],
            ['"destinationCurrencyCode":"GBP"', '"destinationCurrencyCode":"EUR"'],
            ['"netExchangeRate":0.800000000000000001,', ''],
            ['"id":"quote-1"', '"id":"quote-1","id":"quote-2"'],
            ['2030-01-01T01:00:00Z', '2030-01-01T08:00:00'],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_missing_or_mismatched_provider_contract_is_rejected(string $from, string $to): void
    {
        $this->expectException(InvalidArgumentException::class);
        PayoutRateQuote::referenceFromJson(str_replace($from, $to, $this->body()),
            FinalizedUsdPayoutPreparation::quoteRequestForAmount('customer', 'GBP', PayoutAmountMode::Source, '1000'));
    }
}
