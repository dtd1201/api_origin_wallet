<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NiumSmokeTestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_smoke_test_checks_connectivity_and_prints_callback_urls_without_secrets(): void
    {
        config()->set('app.url', 'https://api.originwallet.asia');
        config()->set('services.nium.base_url', 'https://gateway.sandbox.nium.test');
        config()->set('services.nium.client_id', 'sandbox-client-id');
        config()->set('services.nium.auth.header_name', 'x-api-key');
        config()->set('services.nium.auth.header_value', 'sandbox-api-key-secret');
        config()->set('services.nium.health_endpoint', '/api/v1/client/{clientHashId}');
        config()->set('services.nium.webhook.static_header_name', 'x-partner-key');
        config()->set('services.nium.webhook.static_header_value', 'webhook-static-secret');
        config()->set('services.nium.compliance_callback.static_header_name', 'x-partner-key');
        config()->set('services.nium.compliance_callback.static_header_value', 'compliance-static-secret');

        Http::fake([
            'https://gateway.sandbox.nium.test/api/v1/client/sandbox-client-id' => Http::response([
                'clientHashId' => 'sandbox-client-id',
            ]),
        ]);

        $exitCode = Artisan::call('nium:smoke-test');
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
}
