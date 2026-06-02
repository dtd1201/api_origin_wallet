<?php

namespace Tests\Feature;

use App\Models\ManagedExchangeRate;
use App\Services\BankRates\BankRateSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BankRateSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_imports_vietcombank_and_techcombank_rates_for_all_audiences(): void
    {
        Http::fake([
            'https://portal.vietcombank.com.vn/Usercontrols/TVPortal.TyGia/pXML.aspx' => Http::response($this->vietcombankXml(), 200),
            'https://techcombank.com/content/techcombank/web/vn/en/cong-cu-tien-ich/ty-gia/_jcr_content.exchange-rates.integration.json' => Http::response($this->techcombankJson(), 200),
        ]);

        $summary = app(BankRateSyncService::class)->sync(['vcb', 'techcombank']);

        $this->assertSame(8, $summary['upserted']);
        $this->assertSame(0, $summary['failed']);

        $vcbUsd = ManagedExchangeRate::query()
            ->where('source_code', 'vcb')
            ->where('audience', ManagedExchangeRate::AUDIENCE_PUBLIC)
            ->where('source_currency', 'USD')
            ->where('target_currency', 'VND')
            ->firstOrFail();

        $this->assertSame(26118.0, (float) $vcbUsd->buy_rate);
        $this->assertSame(26398.0, (float) $vcbUsd->sell_rate);
        $this->assertNotNull($vcbUsd->published_at);

        $techcombankUsd = ManagedExchangeRate::query()
            ->where('source_code', 'techcombank')
            ->where('audience', ManagedExchangeRate::AUDIENCE_AUTHENTICATED)
            ->where('source_currency', 'USD')
            ->where('target_currency', 'VND')
            ->firstOrFail();

        $this->assertSame(26149.0, (float) $techcombankUsd->buy_rate);
        $this->assertSame(26398.0, (float) $techcombankUsd->sell_rate);
    }

    public function test_synced_bank_rates_are_served_by_public_bank_rate_api(): void
    {
        Http::fake([
            'https://portal.vietcombank.com.vn/Usercontrols/TVPortal.TyGia/pXML.aspx' => Http::response($this->vietcombankXml(), 200),
        ]);

        app(BankRateSyncService::class)->sync(['vcb']);

        $response = $this->getJson('/api/bank-rates?source_currency=eur&target_currency=vnd&source_amount=1');

        $response
            ->assertOk()
            ->assertJsonPath('meta.source_currency', 'EUR')
            ->assertJsonPath('meta.target_currency', 'VND')
            ->assertJsonPath('data.0.source_code', 'vcb')
            ->assertJsonPath('data.0.source_currency', 'EUR')
            ->assertJsonPath('data.0.buy_rate', 30151.01)
            ->assertJsonPath('data.0.sell_rate', 31423.1);
    }

    private function vietcombankXml(): string
    {
        return <<<'XML'
<!--For reference only. Only one request every 5 minutes!-->
<ExrateList>
  <DateTime>6/2/2026 3:51:33 PM</DateTime>
  <Exrate CurrencyCode="USD" CurrencyName="US DOLLAR           " Buy="26,088.00" Transfer="26,118.00" Sell="26,398.00" />
  <Exrate CurrencyCode="EUR" CurrencyName="EURO                " Buy="29,849.50" Transfer="30,151.01" Sell="31,423.10" />
</ExrateList>
XML;
    }

    private function techcombankJson(): array
    {
        return [
            'exchangeRate' => [
                'data' => [
                    [
                        'label' => 'USD (1,2)',
                        'sourceCurrency' => 'USD',
                        'targetCurrency' => 'VND',
                        'bidRateTM' => '26065',
                        'askRateTM' => '26398',
                        'inputDate' => '2026-06-02T15:32:01.398Z',
                    ],
                    [
                        'label' => 'USD (50,100)',
                        'sourceCurrency' => 'USD',
                        'targetCurrency' => 'VND',
                        'bidRateTM' => '26135',
                        'bidRateCK' => '26149',
                        'askRate' => '26398',
                        'askRateTM' => '26398',
                        'inputDate' => '2026-06-02T15:32:01.398Z',
                    ],
                    [
                        'label' => 'EUR',
                        'sourceCurrency' => 'EUR',
                        'targetCurrency' => 'VND',
                        'bidRateTM' => '30031',
                        'bidRateCK' => '30304',
                        'askRate' => '31328',
                        'askRateTM' => '31406',
                        'inputDate' => '2026-06-02T15:32:01.398Z',
                    ],
                ],
            ],
        ];
    }
}
