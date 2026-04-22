<?php

namespace Database\Seeders;

use App\Models\IntegrationProvider;
use Illuminate\Database\Seeder;

class IntegrationProviderSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['code' => 'currenxie', 'name' => 'Currenxie'],
            ['code' => 'wise', 'name' => 'Wise'],
            ['code' => 'airwallex', 'name' => 'Airwallex'],
            ['code' => 'pingpong', 'name' => 'PingPong'],
            ['code' => 'tazapay', 'name' => 'Tazapay'],
            ['code' => 'nium', 'name' => 'Nium'],
        ])->each(function (array $provider): void {
            IntegrationProvider::query()->updateOrCreate(
                ['code' => $provider['code']],
                [
                    'name' => $provider['name'],
                    'status' => 'active',
                ]
            );
        });
    }
}
