<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Beneficiary;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutFinancialAuthorization;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NiumDynamicPayoutFinancialAuthorizationTest extends TestCase
{
    private function beneficiary(string $country, string $currency, string $rail = 'LOCAL'): Beneficiary
    {
        return (new Beneficiary)->forceFill(['country_code' => $country, 'currency' => $currency, 'payout_method' => $rail]);
    }

    public static function acceptedRows(): array
    {
        return [['HK', 'HKD', '0.13'], ['SG', 'SGD', '0.13'], ['GB', 'GBP', '0.13'],
            ['DK', 'EUR', '0.13'], ['DE', 'EUR', '0.13'], ['JP', 'JPY', '0.28'],
            ['TH', 'THB', '0.28'], ['KR', 'KRW', '0.44'], ['CH', 'EUR', '0.30']];
    }

    #[DataProvider('acceptedRows')]
    public function test_exact_signed_local_row_authorizes_customer_price_only(string $country, string $currency, string $margin): void
    {
        $authorization = new DynamicPayoutFinancialAuthorization;
        $beneficiary = $this->beneficiary($country, $currency);
        $this->assertSame([], $authorization->unavailableReasons($beneficiary));
        $price = $authorization->pricing($beneficiary);
        $this->assertSame($country, $price['country']);
        $this->assertSame($currency, $price['destination']);
        $this->assertSame($margin, $price['margin_percent']);
        $this->assertSame('20', $price['fee']);
        $this->assertSame('USD', $price['fee_currency']);
        $this->assertSame('0', $price['origin_service_fee_usd']);
        $this->assertSame('INCLUDED_IN_TOTAL_USD_DEBIT', $price['fee_treatment']);
        $this->assertSame('PRESERVE_NIUM_QUOTED_RATE_NO_ADDITIONAL_MARGIN', $price['rate_treatment']);
        $this->assertSame('INDEPENDENT_AUTHENTICATED_RECONCILIATION', $price['provider_settlement']);
        $this->assertSame(hash('sha256', json_encode($price, JSON_THROW_ON_ERROR)), $authorization->fingerprint($beneficiary));
    }

    public static function unsupportedRows(): array
    {
        return [['FR', 'EUR', 'LOCAL'], ['CH', 'GBP', 'LOCAL'], ['HK', 'USD', 'SWIFT'],
            ['GB', 'GBP', 'WIRE'], ['GB', 'GBP', 'SWIFT'], ['GB', 'GBP', 'local'], ['gb', 'GBP', 'LOCAL']];
    }

    #[DataProvider('unsupportedRows')]
    public function test_no_country_currency_or_rail_fallback_grants_authority(string $country, string $currency, string $rail): void
    {
        $authorization = new DynamicPayoutFinancialAuthorization;
        $beneficiary = $this->beneficiary($country, $currency, $rail);
        $this->assertSame(['dynamic_pricing_corridor_unsupported'], $authorization->unavailableReasons($beneficiary));
        $this->expectExceptionMessage('dynamic_pricing_corridor_unsupported');
        $authorization->pricing($beneficiary);
    }

    public function test_pricing_fingerprint_changes_with_signed_country_scope(): void
    {
        $authorization = new DynamicPayoutFinancialAuthorization;
        $this->assertNotSame($authorization->fingerprint($this->beneficiary('DE', 'EUR')),
            $authorization->fingerprint($this->beneficiary('DK', 'EUR')));
        $this->assertNotSame($authorization->fingerprint($this->beneficiary('DE', 'EUR')),
            $authorization->fingerprint($this->beneficiary('CH', 'EUR')));
        $this->assertSame(['dynamic_financial_scope_required'], $authorization->unavailableReasons());
    }
}
