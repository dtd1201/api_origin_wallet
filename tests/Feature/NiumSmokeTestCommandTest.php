<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NiumSmokeTestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_smoke_test_passes_without_compliance_callback_configuration(): void
    {
        $this->configurePhaseOne();
        config()->set('services.nium.compliance_callback.static_header_name', null);
        config()->set('services.nium.compliance_callback.static_header_value', null);
        Http::fake();

        $exitCode = Artisan::call('nium:smoke-test');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('configuration validation passed', Artisan::output());
        $this->assertStringNotContainsString('transaction-compliance', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_onboarding_smoke_test_fails_when_a_required_phase_one_variable_is_missing(): void
    {
        $this->configurePhaseOne();
        config()->set('services.nium.customer_get_endpoint', '');
        Http::fake();

        $exitCode = Artisan::call('nium:smoke-test');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('NIUM_CUSTOMER_GET_ENDPOINT', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_onboarding_smoke_test_rejects_unresolved_or_legacy_endpoint_placeholders(): void
    {
        $this->configurePhaseOne();
        config()->set('services.nium.customer_get_endpoint', '/api/v5/client/{client}/customer/{customer}');
        Http::fake();

        $exitCode = Artisan::call('nium:smoke-test');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('NIUM_CUSTOMER_GET_ENDPOINT', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_onboarding_smoke_test_never_sends_http_without_live_flag(): void
    {
        $this->configurePhaseOne();
        Http::fake();

        $exitCode = Artisan::call('nium:smoke-test');

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('api_request_logs', 0);
        Http::assertNothingSent();
    }

    public function test_compliance_callback_validation_remains_available_as_a_separate_option(): void
    {
        $this->configurePhaseOne();
        config()->set('services.nium.compliance_callback.static_header_name', null);
        config()->set('services.nium.compliance_callback.static_header_value', null);
        Http::fake();

        $missingExitCode = Artisan::call('nium:smoke-test', ['--compliance-callback' => true]);

        $this->assertSame(1, $missingExitCode);
        $this->assertStringContainsString(
            'transaction compliance callback authentication is not configured',
            Artisan::output(),
        );

        config()->set('services.nium.compliance_callback.static_header_name', 'x-partner-key');
        config()->set('services.nium.compliance_callback.static_header_value', 'separate-compliance-test-secret');

        $validExitCode = Artisan::call('nium:smoke-test', ['--compliance-callback' => true]);

        $this->assertSame(0, $validExitCode);
        $this->assertStringContainsString('callback configuration is valid', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_live_smoke_test_checks_connectivity_and_prints_urls_without_secrets(): void
    {
        $this->configurePhaseOne();
        config()->set('services.nium.compliance_callback.static_header_name', 'x-partner-key');
        config()->set('services.nium.compliance_callback.static_header_value', 'compliance-static-secret');

        Http::fake([
            'https://gateway.sandbox.nium.test/api/v1/client/sandbox-client-id' => Http::response([
                'clientHashId' => 'sandbox-client-id',
            ]),
        ]);

        $exitCode = Artisan::call('nium:smoke-test', [
            '--live' => true,
            '--compliance-callback' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            'https://api.originwallet.asia/api/webhooks/providers/nium',
            $output,
        );
        $this->assertStringContainsString(
            'https://api.originwallet.asia/api/callbacks/nium/transaction-compliance',
            $output,
        );
        $this->assertStringNotContainsString('sandbox-api-key-secret', $output);
        $this->assertStringNotContainsString('webhook-static-secret', $output);
        $this->assertStringNotContainsString('compliance-static-secret', $output);

        $requestLog = ApiRequestLog::query()->firstOrFail();
        $serializedLog = json_encode($requestLog->toArray());
        $this->assertIsString($serializedLog);
        $this->assertStringNotContainsString('sandbox-client-id', $serializedLog);
        $this->assertStringNotContainsString('sandbox-api-key-secret', $serializedLog);
        $this->assertStringContainsString('[REDACTED]', $requestLog->request_url);

        Http::assertSent(fn ($request) => $request->url() === 'https://gateway.sandbox.nium.test/api/v1/client/sandbox-client-id'
            && $request->hasHeader('x-api-key', 'sandbox-api-key-secret'));
    }

    private function configurePhaseOne(): void
    {
        IntegrationProvider::query()->firstOrCreate(
            ['code' => 'nium'],
            ['name' => 'Nium', 'status' => 'active'],
        );

        config()->set('app.url', 'https://api.originwallet.asia');
        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.test');
        config()->set('services.nium.client_id', 'sandbox-client-id');
        config()->set('services.nium.auth.mode', 'header');
        config()->set('services.nium.auth.header_name', 'x-api-key');
        config()->set('services.nium.auth.header_value', 'sandbox-api-key-secret');
        config()->set('services.nium.health_endpoint', '/api/v1/client/{clientHashId}');
        config()->set('services.nium.customer_create_endpoint', '/api/v5/client/{clientHashId}/customers');
        config()->set('services.nium.customer_get_endpoint', '/api/v5/client/{clientHashId}/customer/{customerHashId}');
        config()->set('services.nium.customer_list_endpoint', '/api/v5/client/{clientHashId}/customers');
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'webhook-static-secret');
    }
}
