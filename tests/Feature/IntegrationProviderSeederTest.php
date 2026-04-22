<?php

namespace Tests\Feature;

use Database\Seeders\IntegrationProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationProviderSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_registers_default_multi_provider_set(): void
    {
        $this->seed(IntegrationProviderSeeder::class);

        $this->assertDatabaseHas('integration_providers', [
            'code' => 'currenxie',
            'name' => 'Currenxie',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('integration_providers', [
            'code' => 'wise',
            'name' => 'Wise',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('integration_providers', [
            'code' => 'airwallex',
            'name' => 'Airwallex',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('integration_providers', [
            'code' => 'tazapay',
            'name' => 'Tazapay',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('integration_providers', [
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
    }
}
