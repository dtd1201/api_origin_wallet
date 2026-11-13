<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nium\NiumSgdAdmissionFence;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Classifies synthetic unbound requests only; no database or provider traffic. */
final class NiumDynamicQuoteSpendBoundaryTest extends TestCase
{
    public function test_non_sgd_dynamic_quote_does_not_enter_the_standard_sgd_fence(): void
    {
        DB::shouldReceive('connection')->never();
        $fence = new NiumSgdAdmissionFence;
        $path = 'https://api.spend.nium.com/api/v1/client/fixture-client/quotes';
        $this->assertNull($fence->wireKind('POST', $path, [
            'sourceCurrencyCode' => 'USD', 'destinationCurrencyCode' => 'GBP',
            'executionType' => 'at_conversion_time', 'conversionSchedule' => 'immediate',
        ], null));
        $this->assertSame('quote', $fence->wireKind('POST', $path, [
            'destinationCurrencyCode' => 'SGD', 'executionType' => 'manual',
        ], null));
        $this->assertSame('quote', $fence->wireKind('POST', $path, [], null));
        $this->assertSame('quote', $fence->wireKind('POST', $path, ['destinationCurrencyCode' => []], null));
        $this->assertNull($fence->wireKind('POST', '/api/v1/client/fixture-client/customer/fixture-customer/wallet/fixture-wallet/remittance', [
            'payout' => ['sourceCurrency' => 'USD', 'destinationCurrency' => 'USD', 'payoutMethod' => 'SWIFT'],
        ], null));
    }
}
