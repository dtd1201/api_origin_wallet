<?php

namespace Database\Seeders;

use App\Models\ManagedExchangeRate;
use Illuminate\Database\Seeder;

class ManagedExchangeRateSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['code' => 'vcb', 'name' => 'Vietcombank', 'buy' => 25220, 'sell' => 25590, 'order' => 10],
            ['code' => 'bidv', 'name' => 'BIDV', 'buy' => 25235, 'sell' => 25595, 'order' => 20],
            ['code' => 'vietinbank', 'name' => 'VietinBank', 'buy' => 25210, 'sell' => 25600, 'order' => 30],
            ['code' => 'techcombank', 'name' => 'Techcombank', 'buy' => 25190, 'sell' => 25610, 'order' => 40],
            ['code' => 'acb', 'name' => 'ACB', 'buy' => 25200, 'sell' => 25580, 'order' => 50],
            ['code' => 'mbbank', 'name' => 'MB Bank', 'buy' => 25205, 'sell' => 25585, 'order' => 60],
        ])->each(function (array $bank): void {
            foreach ([ManagedExchangeRate::AUDIENCE_PUBLIC, ManagedExchangeRate::AUDIENCE_AUTHENTICATED] as $audience) {
                $spreadAdjustment = $audience === ManagedExchangeRate::AUDIENCE_AUTHENTICATED ? -10 : 0;

                ManagedExchangeRate::query()->updateOrCreate(
                    [
                        'rate_type' => ManagedExchangeRate::TYPE_BANK,
                        'audience' => $audience,
                        'source_code' => $bank['code'],
                        'source_currency' => 'USD',
                        'target_currency' => 'VND',
                    ],
                    [
                        'source_name' => $bank['name'],
                        'buy_rate' => $bank['buy'],
                        'sell_rate' => $bank['sell'] + $spreadAdjustment,
                        'mid_rate' => (($bank['buy'] + $bank['sell'] + $spreadAdjustment) / 2),
                        'fee_amount' => 0,
                        'status' => 'active',
                        'display_order' => $bank['order'],
                        'notes' => 'Seeded admin-maintained reference rate. Update this value in admin before production use.',
                        'published_at' => now(),
                    ],
                );
            }
        });
    }
}
