<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\ProviderHealthController;
use App\Models\IntegrationProvider;
use App\Services\Nium\NiumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
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
            'health_endpoint' => '/api/v1/client/{clientHashId}',
            'customer_create_endpoint' => '/api/v5/client/{clientHashId}/customers',
            'customer_get_endpoint' => '/api/v5/client/{clientHashId}/customer/{customerHashId}',
            'customer_list_endpoint' => '/api/v5/client/{clientHashId}/customers',
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

    public function test_onboarding_fails_closed_when_webhook_or_endpoint_configuration_is_missing(): void
    {
        config()->set('services.nium', $this->validConfiguration());
        config()->set('services.nium.webhook.static_header_value', '');
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);

        $this->assertFalse($provider->isConfigured());
        $this->assertFalse($provider->isAvailableForOnboarding());
        $this->expectException(RuntimeException::class);
        $provider->assertSupportsCapability('onboarding');
    }

    public function test_unsafe_base_url_and_unresolved_endpoint_are_rejected_before_request(): void
    {
        config()->set('services.nium', $this->validConfiguration());
        config()->set('services.nium.base_url', 'http://gateway.nium.test');
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
        Http::fake();

        $this->assertFalse($provider->isConfigured());
        $response = app(ProviderHealthController::class)->check($provider);
        $this->assertSame(422, $response->status());
        $this->assertSame('not_configured', $response->getData(true)['provider_health']['status']);
        Http::assertNothingSent();

        $this->expectException(RuntimeException::class);
        app(NiumService::class)->path('/api/v5/client/{clientHashId}/customer/{customerHashId}', [
            'clientHashId' => 'client-id',
        ]);
    }

    public function test_every_required_onboarding_configuration_rejects_missing_blank_or_malformed_values(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
        $invalidValues = [
            'base_url_missing' => ['base_url', null],
            'base_url_blank' => ['base_url', '   '],
            'base_url_invalid' => ['base_url', 'not-a-url'],
            'base_url_http' => ['base_url', 'http://gateway.nium.test'],
            'client_missing' => ['client_id', null],
            'client_blank' => ['client_id', '   '],
            'api_key_missing' => ['auth.header_value', null],
            'api_key_blank' => ['auth.header_value', '   '],
            'auth_mode' => ['auth.mode', 'bearer_token'],
            'auth_header' => ['auth.header_name', 'Authorization'],
            'health_missing' => ['health_endpoint', null],
            'health_placeholder' => ['health_endpoint', '/api/v1/client/{client}'],
            'create_missing' => ['customer_create_endpoint', null],
            'create_absolute' => ['customer_create_endpoint', 'https://evil.test/customers'],
            'list_missing' => ['customer_list_endpoint', null],
            'list_whitespace' => ['customer_list_endpoint', '/api/v5/client/{clientHashId}/customers bad'],
            'get_missing' => ['customer_get_endpoint', null],
            'get_customer_placeholder' => ['customer_get_endpoint', '/api/v5/client/{clientHashId}/customer'],
            'partner_name' => ['webhook.static_header_name', 'x-other-key'],
            'partner_value_missing' => ['webhook.static_header_value', null],
            'partner_value_blank' => ['webhook.static_header_value', '   '],
        ];

        foreach ($invalidValues as $case => [$key, $value]) {
            config()->set('services.nium', $this->validConfiguration());
            config()->set("services.nium.{$key}", $value);
            $this->assertFalse($provider->isAvailableForOnboarding(), "Configuration case {$case} must fail closed.");
        }
    }

    private function validConfiguration(): array
    {
        return [
            'base_url' => 'https://gateway.sandbox.nium.com',
            'client_id' => 'sandbox-client-hash-id',
            'auth' => [
                'mode' => 'header',
                'header_name' => 'x-api-key',
                'header_value' => 'sandbox-api-key',
            ],
            'health_endpoint' => '/api/v1/client/{clientHashId}',
            'customer_create_endpoint' => '/api/v5/client/{clientHashId}/customers',
            'customer_get_endpoint' => '/api/v5/client/{clientHashId}/customer/{customerHashId}',
            'customer_list_endpoint' => '/api/v5/client/{clientHashId}/customers',
            'webhook' => [
                'static_header_name' => 'x-partner-key',
                'static_header_value' => 'webhook-partner-key',
            ],
        ];
    }
}
