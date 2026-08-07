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

    public function test_client_capabilities_requires_live_before_http(): void
    {
        $this->configurePhaseOne();
        Http::fake();

        $exitCode = Artisan::call('nium:smoke-test', ['--client-capabilities' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--client-capabilities requires', Artisan::output());
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
        $this->assertSame('sandbox-client-id', $requestLog->client_hash_id);
        $this->assertStringNotContainsString('sandbox-api-key-secret', $serializedLog);
        $this->assertStringContainsString('[REDACTED]', $requestLog->request_url);

        Http::assertSent(fn ($request) => $request->url() === 'https://gateway.sandbox.nium.test/api/v1/client/sandbox-client-id'
            && $request->hasHeader('x-api-key', 'sandbox-api-key-secret'));
    }

    public function test_live_client_capabilities_prints_only_safe_projection_from_exactly_one_get(): void
    {
        $this->configurePhaseOne();
        $rawSecret = 'raw-provider-token-value';
        $email = 'person@example.test';

        Http::fake([
            'https://gateway.sandbox.nium.test/api/v1/client/sandbox-client-id' => Http::response([
                'region' => 'SG',
                'country' => 'Singapore',
                'program' => 'CORPORATE',
                'status' => 'ACTIVE',
                'nested' => ['supportedKycTypes' => ['FULL']],
                'clientHashId' => 'sandbox-client-id',
                'token' => $rawSecret,
                'email' => $email,
                'unknown' => ['raw' => 'complete-object'],
            ]),
        ]);

        $exitCode = Artisan::call('nium:smoke-test', [
            '--live' => true,
            '--client-capabilities' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Connectivity check status: 200', $output);
        $this->assertStringContainsString('Client capability projection:', $output);
        $this->assertStringContainsString('"region": "SG"', $output);
        $this->assertStringContainsString('"supportedKycTypes"', $output);
        $this->assertStringNotContainsString('sandbox-client-id', $output);
        $this->assertStringNotContainsString($rawSecret, $output);
        $this->assertStringNotContainsString($email, $output);
        $this->assertStringNotContainsString('complete-object', $output);

        $requestLog = ApiRequestLog::query()->sole();
        $serializedResponseLog = json_encode($requestLog->response_body, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Singapore', $serializedResponseLog);
        $this->assertStringNotContainsString('CORPORATE', $serializedResponseLog);
        $this->assertStringNotContainsString('supportedKycTypes', $serializedResponseLog);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.sandbox.nium.test/api/v1/client/sandbox-client-id');
        Http::assertNotSent(fn ($request): bool => in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true));
    }

    public function test_live_client_capability_failure_never_prints_raw_response(): void
    {
        $this->configurePhaseOne();
        $rawResponseValue = 'raw-provider-failure-value';
        Http::fake([
            'https://gateway.sandbox.nium.test/api/v1/client/sandbox-client-id' => Http::response([
                'message' => $rawResponseValue,
                'token' => 'raw-provider-failure-token',
            ], 403),
        ]);

        $exitCode = Artisan::call('nium:smoke-test', [
            '--live' => true,
            '--client-capabilities' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Connectivity check status: 403', $output);
        $this->assertStringContainsString('Nium connectivity check failed.', $output);
        $this->assertStringNotContainsString($rawResponseValue, $output);
        $this->assertStringNotContainsString('raw-provider-failure-token', $output);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET');
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
