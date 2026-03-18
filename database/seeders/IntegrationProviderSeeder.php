<?php

namespace Database\Seeders;

use App\Models\IntegrationProvider;
use Illuminate\Database\Seeder;

class IntegrationProviderSeeder extends Seeder
{
    public function run(): void
    {
        IntegrationProvider::query()->updateOrCreate(
            ['code' => 'currenxie'],
            [
                'name' => 'Currenxie',
                'status' => 'active',
            ]
        );
    }
}
