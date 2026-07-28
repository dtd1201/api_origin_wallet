<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\ProviderHttpClient;
use App\Support\SensitiveDataSanitizer;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Utils as Psr7Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderHttpClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_request_supports_query_params_and_static_headers(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'TEST_PROVIDER',
            'name' => 'Test Provider',
            'status' => 'active',
        ]);

        config()->set('services.test_provider.base_url', 'https://provider.example.test');
        config()->set('services.test_provider.timeout', 30);

        Http::fake([
            'https://provider.example.test/accounts*' => Http::response(['data' => []], 200),
        ]);

        $client = new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'test_provider',
            headers: [
                'X-API-KEY' => 'demo-key',
            ],
        );

        $response = $client->get('/accounts', ['page' => 2, 'per_page' => 50]);

        $this->assertTrue($response->successful());

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && $request->hasHeader('X-API-KEY', 'demo-key')
                && $request->url() === 'https://provider.example.test/accounts?page=2&per_page=50';
        });
    }

    public function test_client_credentials_auth_fetches_and_caches_access_token(): void
    {
        Cache::flush();

        $provider = IntegrationProvider::query()->create([
            'code' => 'OAUTH_PROVIDER',
            'name' => 'OAuth Provider',
            'status' => 'active',
        ]);

        config()->set('services.oauth_provider.base_url', 'https://oauth.example.test');
        config()->set('services.oauth_provider.timeout', 30);
        config()->set('services.oauth_provider.auth', [
            'mode' => 'client_credentials',
            'token_endpoint' => '/oauth2/token',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'scope' => 'accounts:read',
            'credentials_in' => 'body',
            'cache_key' => 'tests:oauth_provider:token',
            'cache_buffer_seconds' => 30,
        ]);

        $tokenRequests = 0;
        $apiRequests = 0;

        Http::fake(function ($request) use (&$tokenRequests, &$apiRequests) {
            if ($request->url() === 'https://oauth.example.test/oauth2/token') {
                $tokenRequests++;

                return Http::response([
                    'access_token' => 'cached-access-token',
                    'expires_in' => 300,
                    'token_type' => 'Bearer',
                ], 200);
            }

            if (str_starts_with($request->url(), 'https://oauth.example.test/accounts')) {
                $apiRequests++;

                return Http::response(['data' => []], 200);
            }

            return Http::response([], 404);
        });

        $client = new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'oauth_provider',
        );

        $client->get('/accounts');
        $client->get('/accounts', ['page' => 2]);

        $this->assertSame(1, $tokenRequests);
        $this->assertSame(2, $apiRequests);

        Http::assertSent(function ($request): bool {
            if (! str_starts_with($request->url(), 'https://oauth.example.test/accounts')) {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer cached-access-token');
        });
    }

    public function test_pingpong_auth_fetches_and_caches_access_token_without_bearer_prefix(): void
    {
        Cache::flush();

        $provider = IntegrationProvider::query()->create([
            'code' => 'PINGPONG',
            'name' => 'PingPong',
            'status' => 'active',
        ]);

        config()->set('services.pingpong_provider.base_url', 'https://test-gateway.pingpongx.com');
        config()->set('services.pingpong_provider.timeout', 30);
        config()->set('services.pingpong_provider.auth', [
            'mode' => 'pingpong_access_token',
            'token_endpoint' => '/v2/token/get',
            'app_id' => 'pingpong-app-id',
            'app_secret' => 'pingpong-app-secret',
            'cache_key' => 'tests:pingpong_provider:token',
            'cache_buffer_seconds' => 300,
        ]);

        $tokenRequests = 0;
        $apiRequests = 0;

        Http::fake(function ($request) use (&$tokenRequests, &$apiRequests) {
            if (str_starts_with($request->url(), 'https://test-gateway.pingpongx.com/v2/token/get')) {
                $tokenRequests++;

                return Http::response([
                    'access_token' => 'pingpong-raw-access-token',
                    'expires_in' => 7200,
                ], 200);
            }

            if ($request->url() === 'https://test-gateway.pingpongx.com/api/recipient/v2/create') {
                $apiRequests++;

                return Http::response([
                    'code' => 'SUCCESS',
                    'data' => ['biz_id' => 'R202501080950209789'],
                ], 200);
            }

            return Http::response([], 404);
        });

        $client = new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'pingpong_provider',
            headers: [
                'on-behalf-of' => 'managed-account-123',
            ],
        );

        $client->post('/api/recipient/v2/create', ['holder_type' => 'PERSONAL']);
        $client->post('/api/recipient/v2/create', ['holder_type' => 'COMPANY']);

        $this->assertSame(1, $tokenRequests);
        $this->assertSame(2, $apiRequests);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://test-gateway.pingpongx.com/api/recipient/v2/create') {
                return false;
            }

            return $request->hasHeader('Authorization', 'pingpong-raw-access-token')
                && ! $request->hasHeader('Authorization', 'Bearer pingpong-raw-access-token')
                && $request->hasHeader('on-behalf-of', 'managed-account-123');
        });
    }

    public function test_unlimit_auth_fetches_password_grant_token_and_caches_access_token(): void
    {
        Cache::flush();

        $provider = IntegrationProvider::query()->create([
            'code' => 'UNLIMIT_PROVIDER',
            'name' => 'Unlimit Provider',
            'status' => 'active',
        ]);

        config()->set('services.unlimit_provider.base_url', 'https://sandbox.cardpay.com/api');
        config()->set('services.unlimit_provider.timeout', 30);
        config()->set('services.unlimit_provider.auth', [
            'mode' => 'unlimit_access_token',
            'token_endpoint' => '/auth/token',
            'terminal_code' => 'terminal-123',
            'password' => 'terminal-secret',
            'cache_key' => 'tests:unlimit_provider:token',
            'cache_buffer_seconds' => 30,
        ]);

        $tokenRequests = 0;
        $apiRequests = 0;

        Http::fake(function ($request) use (&$tokenRequests, &$apiRequests) {
            if ($request->url() === 'https://sandbox.cardpay.com/api/auth/token') {
                $tokenRequests++;

                return Http::response([
                    'access_token' => 'unlimit-access-token',
                    'expires_in' => 300,
                    'refresh_token' => 'unlimit-refresh-token',
                    'token_type' => 'bearer',
                ], 200);
            }

            if ($request->url() === 'https://sandbox.cardpay.com/api/payouts') {
                $apiRequests++;

                return Http::response([
                    'payout_data' => [
                        'id' => '4237264',
                        'status' => 'NEW',
                    ],
                ], 201);
            }

            return Http::response([], 404);
        });

        $client = new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'unlimit_provider',
        );

        $client->post('/payouts', ['payment_method' => 'BANKTRANSFERSIDR']);
        $client->post('/payouts', ['payment_method' => 'BANKTRANSFERSIDR']);

        $this->assertSame(1, $tokenRequests);
        $this->assertSame(2, $apiRequests);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://sandbox.cardpay.com/api/auth/token'
                && $data['grant_type'] === 'password'
                && $data['terminal_code'] === 'terminal-123'
                && $data['password'] === 'terminal-secret';
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://sandbox.cardpay.com/api/payouts'
                && $request->hasHeader('Authorization', 'Bearer unlimit-access-token');
        });
    }

    public function test_airwallex_auth_fetches_and_caches_access_token(): void
    {
        Cache::flush();

        $provider = IntegrationProvider::query()->create([
            'code' => 'AIRWALLEX_PROVIDER',
            'name' => 'Airwallex Provider',
            'status' => 'active',
        ]);

        config()->set('services.airwallex_provider.base_url', 'https://api-demo.airwallex.com');
        config()->set('services.airwallex_provider.timeout', 30);
        config()->set('services.airwallex_provider.x_api_key', 'airwallex-api-key');
        config()->set('services.airwallex_provider.auth', [
            'mode' => 'airwallex_access_token',
            'token_endpoint' => '/api/v1/authentication/login',
            'client_id' => 'airwallex-client-id',
            'cache_key' => 'tests:airwallex_provider:token',
            'cache_buffer_seconds' => 30,
        ]);

        $tokenRequests = 0;
        $apiRequests = 0;

        Http::fake(function ($request) use (&$tokenRequests, &$apiRequests) {
            if ($request->url() === 'https://api-demo.airwallex.com/api/v1/authentication/login') {
                $tokenRequests++;

                return Http::response([
                    'token' => 'airwallex-access-token',
                    'expires_at' => now()->addMinutes(20)->toISOString(),
                ], 200);
            }

            if ($request->url() === 'https://api-demo.airwallex.com/api/v1/global_accounts') {
                $apiRequests++;

                return Http::response(['items' => []], 200);
            }

            return Http::response([], 404);
        });

        $client = new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'airwallex_provider',
        );

        $client->get('/api/v1/global_accounts');
        $client->get('/api/v1/global_accounts');

        $this->assertSame(1, $tokenRequests);
        $this->assertSame(2, $apiRequests);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api-demo.airwallex.com/api/v1/global_accounts') {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer airwallex-access-token');
        });
    }

    public function test_basic_auth_mode_sends_encoded_authorization_header(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'TAZAPAY_PROVIDER',
            'name' => 'Tazapay Provider',
            'status' => 'active',
        ]);

        config()->set('services.tazapay_provider.base_url', 'https://service-sandbox.tazapay.com');
        config()->set('services.tazapay_provider.timeout', 30);
        config()->set('services.tazapay_provider.auth', [
            'mode' => 'basic_auth',
            'username' => 'tzp_live_key',
            'password' => 'tzp_live_secret',
        ]);

        Http::fake([
            'https://service-sandbox.tazapay.com/v3/balance' => Http::response(['data' => []], 200),
        ]);

        $client = new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'tazapay_provider',
            headers: [
                'tz-account-id' => 'acc_123',
            ],
        );

        $response = $client->get('/v3/balance');

        $this->assertTrue($response->successful());

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://service-sandbox.tazapay.com/v3/balance'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('tzp_live_key:tzp_live_secret'))
                && $request->hasHeader('tz-account-id', 'acc_123');
        });
    }

    public function test_custom_authorization_header_skips_provider_auth_resolution(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'WISE_PROVIDER',
            'name' => 'Wise Provider',
            'status' => 'active',
        ]);

        config()->set('services.wise_provider.base_url', 'https://api.wise-sandbox.com');
        config()->set('services.wise_provider.timeout', 30);
        config()->set('services.wise_provider.auth', [
            'mode' => 'client_credentials',
            'token_endpoint' => '/oauth/token',
            'client_id' => 'wise-client-id',
            'client_secret' => 'wise-client-secret',
            'credentials_in' => 'basic',
        ]);

        Http::fake([
            'https://api.wise-sandbox.com/v2/profiles' => Http::response([], 200),
        ]);

        $client = new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'wise_provider',
            headers: [
                'Authorization' => 'Bearer wise-user-token',
            ],
        );

        $response = $client->get('/v2/profiles');

        $this->assertTrue($response->successful());

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.wise-sandbox.com/v2/profiles'
                && $request->hasHeader('Authorization', 'Bearer wise-user-token');
        });
    }

    public function test_nium_completed_error_response_persists_one_allowlisted_sanitizer_stable_projection(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $apiKey = 'sandbox-secret-api-key';
        $fileId = '55555555-5555-4555-8555-555555555555';
        $requestId = '11111111-1111-4111-8111-111111111111';
        $responseRequestId = '22222222-2222-4222-8222-222222222222';
        $customerId = '33333333-3333-4333-8333-333333333333';
        $walletId = '44444444-4444-4444-8444-444444444444';

        config()->set('services.nium.base_url', 'https://gateway.nium.test');
        config()->set('services.nium.timeout', 30);
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => $apiKey,
        ]);

        Http::fake([
            '*' => Http::response([
                'status' => 'CLEAR',
                'subStatus' => 'RFI_REQUESTED',
                'customerHashId' => $customerId,
                'walletHashId' => $walletId,
                'errors' => [[
                    'code' => 'invalid_input',
                    'field' => 'jane@example.test',
                    'path' => '+65 8123 4567',
                    'parameter' => 'jane@example.test',
                    'description' => 'Invalid role for Jane Doe at jane@example.test. '
                        ."Value: ultimate_beneficial_owner. Bearer {$apiKey}. "
                        ."File {$fileId}. Address 10 Private Road.",
                ]],
                'rawResponse' => 'must-not-be-persisted',
                'authorization' => 'must-not-be-persisted',
            ], 400, ['x-request-id' => $responseRequestId]),
        ]);

        $client = new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'nium',
            headers: [
                'x-request-id' => $requestId,
                'Authorization' => 'Bearer must-not-be-persisted',
                'x-api-key' => $apiKey,
            ],
        );

        $client->post('/api/v5/client/client-id/customers', [
            'type' => 'corporate',
            'region' => 'SG',
            'externalId' => 'external-reference-secret',
            'stakeholders' => [
                'individual' => [[
                    'firstName' => 'Jane',
                    'lastName' => 'Doe',
                    'email' => 'jane@example.test',
                    'address' => ['addressLine1' => '10 Private Road'],
                    'positions' => [['title' => 'ultimate_beneficial_owner']],
                    'documents' => [['fileIds' => [$fileId]]],
                ]],
            ],
        ], $user);

        $log = ApiRequestLog::query()->sole();
        $responseBody = $log->response_body;
        $serializedLog = json_encode($log->toArray(), JSON_THROW_ON_ERROR);

        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame(['x-request-id' => $requestId], $log->request_headers);
        $this->assertSame(['x-request-id' => $responseRequestId], $log->response_headers);
        $this->assertSame([
            'external_id_fingerprint' => substr(hash('sha256', 'external-reference-secret'), 0, 16),
            'customer_type' => 'corporate',
            'region' => 'SG',
        ], $log->request_body);
        $this->assertSame(400, $responseBody['http_status']);
        $this->assertSame('clear', $responseBody['status']);
        $this->assertSame('rfi_requested', $responseBody['sub_status']);
        $this->assertSame('invalid_input', $responseBody['error_code']);
        $this->assertSame(substr(hash('sha256', 'jane@example.test'), 0, 16), $responseBody['error_field_fingerprint']);
        $this->assertSame(substr(hash('sha256', '+65 8123 4567'), 0, 16), $responseBody['error_path_fingerprint']);
        $this->assertSame(substr(hash('sha256', 'jane@example.test'), 0, 16), $responseBody['error_parameter_fingerprint']);
        $this->assertTrue($responseBody['customer_id_present']);
        $this->assertTrue($responseBody['wallet_id_present']);
        $this->assertSame(substr(hash('sha256', $customerId), 0, 16), $responseBody['customer_id_fingerprint']);
        $this->assertSame(substr(hash('sha256', $walletId), 0, 16), $responseBody['wallet_id_fingerprint']);
        $this->assertSame($responseBody, app(SensitiveDataSanitizer::class)->sanitize($responseBody));
        $this->assertArrayNotHasKey('error_description', $responseBody);

        foreach ([
            'Jane',
            'Doe',
            'jane@example.test',
            '10 Private Road',
            'ultimate_beneficial_owner',
            $fileId,
            $apiKey,
            'external-reference-secret',
            'must-not-be-persisted',
            $customerId,
            $walletId,
            '+65 8123 4567',
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $serializedLog);
        }

        $this->assertArrayNotHasKey('errors', $responseBody);
        $this->assertArrayNotHasKey('rawResponse', $responseBody);
        $this->assertArrayNotHasKey('authorization', $responseBody);
    }

    public function test_nium_unknown_and_configured_secret_values_fail_closed_without_losing_completed_500_log(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
        $statusSecret = 'provider-status-secret';
        $subStatusSecret = 'provider-substatus-secret';
        $errorSecret = 'provider-error-secret';
        $requestIdSecret = 'provider-request-id-secret';

        config()->set('services.nium.base_url', 'https://gateway.nium.test');
        config()->set('services.nium.timeout', 30);
        config()->set('services.nium.auth', [
            'mode' => 'header',
            'header_name' => 'x-api-key',
            'header_value' => $statusSecret,
            'client_secret' => $subStatusSecret,
            'x_api_key' => $errorSecret,
            'webhook_secret' => $requestIdSecret,
        ]);
        Http::fake(['*' => Http::response([
            'status' => $statusSecret,
            'subStatus' => $subStatusSecret,
            'errors' => [['code' => $errorSecret]],
            'customerHashId' => null,
            'walletHashId' => null,
        ], 500, ['x-request-id' => $requestIdSecret])]);

        (new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'nium',
            headers: ['x-request-id' => $requestIdSecret],
        ))->post('/api/v5/client/client-id/customers', [
            'type' => 'person@example.test',
            'region' => 'https://unsafe.example.test',
        ]);

        $log = ApiRequestLog::query()->sole();
        $serialized = json_encode($log->toArray(), JSON_THROW_ON_ERROR);

        $this->assertSame([], $log->request_headers);
        $this->assertSame('unknown', $log->request_body['customer_type']);
        $this->assertSame('unknown', $log->request_body['region']);
        $this->assertSame([], $log->response_headers);
        $this->assertSame('unknown', $log->response_body['status']);
        $this->assertSame('unknown', $log->response_body['sub_status']);
        $this->assertSame('unclassified', $log->response_body['error_category']);
        $this->assertArrayNotHasKey('error_code', $log->response_body);
        $this->assertFalse($log->response_body['customer_id_present']);
        $this->assertFalse($log->response_body['wallet_id_present']);
        $this->assertFalse($log->is_success);
        $this->assertDatabaseCount('api_request_logs', 1);

        foreach ([$statusSecret, $subStatusSecret, $errorSecret, $requestIdSecret] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_nium_ambiguous_200_response_creates_exactly_one_minimal_safe_log(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
        config()->set('services.nium.base_url', 'https://gateway.nium.test');
        config()->set('services.nium.auth.mode', 'none');
        Http::fake(['*' => Http::response(['message' => 'ambiguous free text'], 200)]);

        (new ProviderHttpClient($provider, 'nium'))->get('/api/v5/client/client-id/customers');

        $log = ApiRequestLog::query()->sole();
        $this->assertSame([
            'http_status' => 200,
            'customer_id_present' => false,
            'wallet_id_present' => false,
        ], $log->response_body);
        $this->assertTrue($log->is_success);
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertStringNotContainsString('ambiguous free text', json_encode($log->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_nium_url_log_uses_configured_host_and_redacts_all_identifier_forms_without_changing_dispatch(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
        $uuid = '11111111-1111-4111-8111-111111111111';
        $standaloneToken = 'abcdef0123456789abcdef';
        $querySecret = 'query-secret-value';
        $fragmentSecret = 'fragment-secret-value';
        $inputUrl = 'https://attacker.example.test/api/v5'
            .'/client/singular-client-id/customer/singular-customer-id/wallet/singular-wallet-id'
            .'/clients/plural-client-id/customers/plural-customer-id/wallets/plural-wallet-id'
            .'/status/'.$uuid.'/'.$standaloneToken
            .'?credential='.$querySecret.'#'.$fragmentSecret;
        $dispatchedUrl = null;
        $dispatchedMethod = null;
        $dispatchedBody = null;
        $providerRequests = 0;

        config()->set('services.nium.base_url', 'https://configured-user:configured-password@gateway.nium.test:8443/base');
        config()->set('services.nium.auth.mode', 'none');
        Http::fake(function ($request) use (&$dispatchedUrl, &$dispatchedMethod, &$dispatchedBody, &$providerRequests) {
            $providerRequests++;
            $dispatchedUrl = $request->url();
            $dispatchedMethod = $request->method();
            $dispatchedBody = $request->body();

            return Http::response(['status' => 'CLEAR'], 200);
        });

        $client = new ProviderHttpClient($provider, 'nium');
        $client->get($inputUrl);

        $log = ApiRequestLog::query()->sole();
        $serialized = json_encode($log->toArray(), JSON_THROW_ON_ERROR);
        $frameworkNormalizedUrl = (string) Psr7Utils::modifyRequest(
            new Psr7Request('GET', $inputUrl),
            ['query' => http_build_query([], '', '&', PHP_QUERY_RFC3986)],
        )->getUri();

        $this->assertSame($frameworkNormalizedUrl, $dispatchedUrl);
        $this->assertSame('GET', $dispatchedMethod);
        $this->assertSame('', $dispatchedBody);
        $this->assertStringContainsString('attacker.example.test', $dispatchedUrl);
        $this->assertNotSame($log->request_url, $dispatchedUrl);
        $this->assertSame(1, $providerRequests);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame(
            'https://gateway.nium.test/api/v5'
                .'/client/[REDACTED]/customer/[REDACTED]/wallet/[REDACTED]'
                .'/clients/[REDACTED]/customers/[REDACTED]/wallets/[REDACTED]'
                .'/status/[REDACTED]/[REDACTED]',
            $log->request_url,
        );
        $this->assertStringContainsString('/api/v5/', $log->request_url);
        foreach ([
            'attacker.example.test',
            'configured-user',
            'configured-password',
            ':8443',
            $querySecret,
            $fragmentSecret,
            'singular-client-id',
            'singular-customer-id',
            'singular-wallet-id',
            'plural-client-id',
            'plural-customer-id',
            'plural-wallet-id',
            $uuid,
            $standaloneToken,
        ] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $serialized);
        }
    }

    public function test_nium_url_log_uses_fixed_fallback_for_invalid_config_without_changing_dispatch(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
        $inputUrl = 'https://dispatch-user:dispatch-password@operational-provider.test:9443/api/v5'
            .'/customers/fallback-customer-id/clients/fallback-client-id/wallets/fallback-wallet-id'
            .'?credential=fallback-query-value#fallback-fragment-value';
        $dispatchedUrl = null;
        $dispatchedMethod = null;
        $dispatchedBody = null;
        $providerRequests = 0;

        config()->set('services.nium.base_url', 'not-a-valid-absolute-url');
        config()->set('services.nium.auth.mode', 'none');
        Http::fake(function ($request) use (&$dispatchedUrl, &$dispatchedMethod, &$dispatchedBody, &$providerRequests) {
            $providerRequests++;
            $dispatchedUrl = $request->url();
            $dispatchedMethod = $request->method();
            $dispatchedBody = $request->body();

            return Http::response(['status' => 'CLEAR'], 200);
        });

        (new ProviderHttpClient($provider, 'nium'))->get($inputUrl);

        $log = ApiRequestLog::query()->sole();
        $serialized = json_encode($log->toArray(), JSON_THROW_ON_ERROR);
        $frameworkNormalizedUrl = (string) Psr7Utils::modifyRequest(
            new Psr7Request('GET', $inputUrl),
            ['query' => http_build_query([], '', '&', PHP_QUERY_RFC3986)],
        )->getUri();

        $this->assertSame($frameworkNormalizedUrl, $dispatchedUrl);
        $this->assertStringContainsString('operational-provider.test:9443', $dispatchedUrl);
        $this->assertStringNotContainsString('not-a-valid-absolute-url', $dispatchedUrl);
        $this->assertSame('GET', $dispatchedMethod);
        $this->assertSame('', $dispatchedBody);
        $this->assertSame(1, $providerRequests);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('api_request_logs', 1);
        $this->assertSame('https://configured-nium-host/[REDACTED]', $log->request_url);

        foreach ([
            'not-a-valid-absolute-url',
            'operational-provider.test',
            'dispatch-user',
            'dispatch-password',
            ':9443',
            'fallback-query-value',
            'fallback-fragment-value',
            'fallback-customer-id',
            'fallback-client-id',
            'fallback-wallet-id',
        ] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $serialized);
        }
    }

    public function test_nium_network_exception_creates_no_completed_response_log(): void
    {
        $provider = IntegrationProvider::query()->create([
            'code' => 'nium',
            'name' => 'Nium',
            'status' => 'active',
        ]);
        config()->set('services.nium.base_url', 'https://gateway.nium.test');
        config()->set('services.nium.auth.mode', 'none');
        Http::fake(['*' => Http::failedConnection('synthetic network failure')]);

        try {
            (new ProviderHttpClient($provider, 'nium'))->get('/api/v5/client/client-id/customers');
            $this->fail('Expected the synthetic connection failure.');
        } catch (ConnectionException $exception) {
            $this->assertStringContainsString('synthetic network failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('api_request_logs', 0);
    }
}
