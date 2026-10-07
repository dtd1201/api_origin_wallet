<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\NiumApiExchangeEvidence;
use App\Models\User;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class NiumApiExchangeEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_kyc_preserves_exact_encrypted_request_and_response_without_changing_projection(): void
    {
        [$provider, $user] = $this->context();
        $apiKey = 'never-store-this-api-key';
        $biometricUrl = 'https://verify.example.test/exact-sensitive-session';
        $rawResponse = json_encode([
            'biometricUrl' => $biometricUrl,
            'referenceId' => 'provider-reference-1',
            'kycStatus' => 'initiated',
            'kycMode' => 'biometric_kyc',
            'entityType' => 'applicant',
            'externalId' => 'origin-wallet-applicant-4',
            'providerTimestamp' => '2026-10-07T03:00:00Z',
            'unprojectedProviderField' => ['exact' => true],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $payload = [
            'region' => 'HK',
            'entityType' => 'applicant',
            'entityReferenceId' => '7205cfe0-bf31-415e-b07d-e911b7e9d58e',
            'kycMode' => 'biometric_kyc',
            'isResident' => false,
            'proofOfIdentityDocument' => [[
                'type' => 'passport',
                'identificationNumber' => 'P1234567',
                'issuanceCountry' => 'HK',
                'expiryDate' => '2099-12-31',
            ]],
        ];
        $sentBody = null;

        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => $apiKey,
        ]);
        Http::fake(function (Request $request) use (&$sentBody, $rawResponse) {
            $sentBody = $request->body();

            return Http::response($rawResponse, 200, [
                'Content-Type' => 'application/json',
                'Set-Cookie' => 'provider-session=must-not-be-stored',
                'X-Partner-Secret' => 'provider-response-secret',
                'x-request-id' => '11111111-1111-4111-8111-111111111111',
            ]);
        });

        (new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'nium',
            headers: [
                'x-request-id' => '22222222-2222-4222-8222-222222222222',
                'Authorization' => 'Bearer unknown-runtime-authorization',
                'Cookie' => 'local-session=must-not-be-stored',
                'X-Signature' => 'unknown-runtime-signature',
                'X-Client-Key' => 'unknown-runtime-client-key',
                'X-Random-Token' => 'unknown-runtime-token',
                'X-Partner-Secret' => 'unknown-runtime-partner-secret',
            ],
            operationalContext: ['operation' => 'submit_kyc', 'external_reference' => 'internal-manual-reference'],
        ))->post('/api/v5/client/client-id/customer/customer-id/submitKyc', $payload, $user);

        $log = ApiRequestLog::query()->sole();
        $evidence = NiumApiExchangeEvidence::query()->sole();

        $this->assertSame($sentBody, $evidence->raw_request_body);
        $this->assertSame($payload, json_decode($evidence->raw_request_body, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($rawResponse, $evidence->raw_response_body);
        $this->assertStringContainsString($biometricUrl, $evidence->raw_response_body);
        $this->assertSame(hash('sha256', $sentBody), $evidence->request_body_sha256);
        $this->assertSame(hash('sha256', $rawResponse), $evidence->response_body_sha256);
        $this->assertSame(
            hash('sha256', json_encode($evidence->request_headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            $evidence->request_headers_sha256,
        );
        $this->assertSame(
            hash('sha256', json_encode($evidence->response_headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            $evidence->response_headers_sha256,
        );
        $this->assertSame(strlen($sentBody), $evidence->request_byte_length);
        $this->assertSame(strlen($rawResponse), $evidence->response_byte_length);
        $this->assertTrue($evidence->response_received);
        $this->assertArrayNotHasKey('authorization', $evidence->request_headers);
        $this->assertArrayNotHasKey('x-api-key', $evidence->request_headers);
        $this->assertArrayNotHasKey('cookie', $evidence->request_headers);
        $this->assertArrayNotHasKey('x-signature', $evidence->request_headers);
        $this->assertArrayNotHasKey('x-client-key', $evidence->request_headers);
        $this->assertArrayNotHasKey('x-random-token', $evidence->request_headers);
        $this->assertArrayNotHasKey('x-partner-secret', $evidence->request_headers);
        $this->assertSame(
            ['22222222-2222-4222-8222-222222222222'],
            $evidence->request_headers['x-request-id'],
        );
        $this->assertArrayNotHasKey('set-cookie', $evidence->response_headers);
        $this->assertArrayNotHasKey('x-partner-secret', $evidence->response_headers);
        $storedHeaders = json_encode([
            $evidence->request_headers,
            $evidence->response_headers,
        ], JSON_THROW_ON_ERROR);
        foreach ([
            'unknown-runtime-authorization',
            'local-session=must-not-be-stored',
            'unknown-runtime-signature',
            'unknown-runtime-client-key',
            'unknown-runtime-token',
            'unknown-runtime-partner-secret',
            'provider-response-secret',
        ] as $secretHeaderValue) {
            $this->assertStringNotContainsString($secretHeaderValue, $storedHeaders);
        }
        $this->assertSame(['region' => 'HK'], $log->request_body);
        $this->assertSame('initiated', $log->response_body['kyc_status']);
        $this->assertArrayNotHasKey('biometricUrl', $log->response_body);

        $this->assertArrayNotHasKey('nium_exchange_evidence', $log->toArray());
        $serializedLog = json_encode($log->load('niumExchangeEvidence')->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($biometricUrl, $serializedLog);
        $this->assertStringNotContainsString('P1234567', $serializedLog);
        $this->assertArrayNotHasKey('raw_request_body', $evidence->toArray());
        $this->assertArrayNotHasKey('raw_response_body', $evidence->toArray());

        $stored = DB::table('nium_api_exchange_evidence')->sole();
        $this->assertNotSame($sentBody, $stored->raw_request_body);
        $this->assertNotSame($rawResponse, $stored->raw_response_body);
        $this->assertStringNotContainsString($apiKey, json_encode($stored, JSON_THROW_ON_ERROR));
    }

    public function test_non_json_response_bodies_are_preserved_exactly(): void
    {
        [$provider] = $this->context();
        $responses = [
            ['{"broken":', 'application/json'],
            ["<html>\n<body>Nium unavailable</body>\n</html>", 'text/html'],
            ["plain provider response\n", 'text/plain'],
        ];
        $sequence = Http::sequence();
        foreach ($responses as [$body, $contentType]) {
            $sequence->push($body, 502, ['Content-Type' => $contentType]);
        }
        Http::fake(['*' => $sequence]);

        foreach ($responses as [$body, $contentType]) {
            (new ProviderHttpClient($provider, 'nium'))->post('/api/v5/test', ['sequence' => $contentType]);

            $log = ApiRequestLog::query()->latest('id')->firstOrFail();
            $evidence = $log->niumExchangeEvidence()->firstOrFail();
            $this->assertSame('malformed_response', $log->transport_outcome);
            $this->assertSame($body, $evidence->raw_response_body);
            $this->assertSame(hash('sha256', $body), $evidence->response_body_sha256);
        }
    }

    public function test_connection_failure_preserves_request_and_records_no_response(): void
    {
        [$provider] = $this->context();
        $payload = ['entityType' => 'applicant', 'entityReferenceId' => 'provider-reference'];
        Http::fake(['*' => Http::failedConnection('synthetic connection failure')]);

        try {
            (new ProviderHttpClient($provider, 'nium'))->post('/api/v5/test', $payload);
            $this->fail('Expected connection failure.');
        } catch (ConnectionException) {
            // The operational exception remains unchanged.
        }

        $log = ApiRequestLog::query()->sole();
        $evidence = NiumApiExchangeEvidence::query()->sole();
        $this->assertSame($payload, json_decode($evidence->raw_request_body, true, 512, JSON_THROW_ON_ERROR));
        $this->assertFalse($evidence->response_received);
        $this->assertNull($evidence->raw_response_body);
        $this->assertNull($evidence->response_body_sha256);
        $this->assertNull($evidence->response_byte_length);
        $this->assertSame('connection_failure', $log->transport_outcome);
    }

    private function context(): array
    {
        config()->set('services.nium.base_url', 'https://gateway.nium.test');
        config()->set('services.nium.timeout', 30);
        config()->set('services.nium.auth.mode', 'none');

        return [
            IntegrationProvider::query()->create(['code' => 'nium', 'name' => 'Nium', 'status' => 'active']),
            User::factory()->create(),
        ];
    }
}
