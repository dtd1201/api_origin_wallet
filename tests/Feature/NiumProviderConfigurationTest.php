<?php

namespace Tests\Feature;

use App\Models\IntegrationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NiumProviderConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_status_is_configured_when_all_required_nium_settings_exist(): void
    {
        config()->set('services.nium', [
            'base_url' => 'https://gateway.sandbox.nium.com',
            'client_id' => 'sandbox-client-hash-id',
            'auth' => [
                'mode' => 'header',
                'header_name' => 'x-api-key',
                'header_value' => 'sandbox-api-key',
            ],
            'webhook' => [
                'static_header_name' => 'x-partner-key',
                'static_header_value' => 'webhook-partner-key',
            ],
            'compliance_callback' => [
                'static_header_name' => 'x-partner-key',
                'static_header_value' => 'compliance-partner-key',
            ],
        ]);

        IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);

        $this->getJson('/api/providers')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'nium')
            ->assertJsonPath('data.0.is_configured', true);
    }
}
