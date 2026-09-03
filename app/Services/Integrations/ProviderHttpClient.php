<?php

namespace App\Services\Integrations;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\Contracts\ProviderClient;
use App\Services\Nium\NiumEvidencePersistenceException;
use App\Services\Nium\NiumSafeValueProjector;
use App\Support\SensitiveDataSanitizer;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ProviderHttpClient implements ProviderClient
{
    public function __construct(
        private readonly IntegrationProvider $provider,
        private readonly string $serviceConfigKey,
        private readonly array $headers = [],
        private readonly SensitiveDataSanitizer $sensitiveDataSanitizer = new SensitiveDataSanitizer,
        private readonly ?NiumSafeValueProjector $niumSafeValueProjector = null,
        private readonly array $operationalContext = [],
    ) {}

    public function get(string $path, array $query = [], ?User $user = null, ?int $relatedTransferId = null): Response
    {
        return $this->send('GET', $path, $query, $user, $relatedTransferId);
    }

    public function post(string $path, array $payload, ?User $user = null, ?int $relatedTransferId = null): Response
    {
        return $this->send('POST', $path, $payload, $user, $relatedTransferId);
    }

    public function put(string $path, array $payload, ?User $user = null, ?int $relatedTransferId = null): Response
    {
        return $this->send('PUT', $path, $payload, $user, $relatedTransferId);
    }

    public function patch(string $path, array $payload, ?User $user = null, ?int $relatedTransferId = null): Response
    {
        return $this->send('PATCH', $path, $payload, $user, $relatedTransferId);
    }

    public function delete(string $path, array $payload = [], ?User $user = null, ?int $relatedTransferId = null): Response
    {
        return $this->send('DELETE', $path, $payload, $user, $relatedTransferId);
    }

    private function baseRequest(): PendingRequest
    {
        return Http::timeout((int) config("services.{$this->serviceConfigKey}.timeout", 30))
            ->acceptJson()
            ->withHeaders($this->resolveHeaders());
    }

    private function buildUrl(string $path): string
    {
        if (preg_match('/^https?:\/\//i', $path) === 1) {
            return $path;
        }

        $baseUrl = rtrim((string) config("services.{$this->serviceConfigKey}.base_url"), '/');

        return $baseUrl.'/'.ltrim($path, '/');
    }

    private function send(
        string $method,
        string $path,
        array $payload,
        ?User $user,
        ?int $relatedTransferId,
    ): Response {
        logger()->info('NIUM_SEND_PAYLOAD_DEBUG', [
    'path' => $path,
    'payload' => $payload,
]);
        $request = $this->baseRequest();
        $url = $this->buildUrl($path);
        $startedAt = microtime(true);
        $startedAtUtc = CarbonImmutable::now('UTC');

        try {
            $response = match ($method) {
                'GET' => $request->get($url, $payload),
                'POST' => $request->asJson()->post($url, $payload),
                'PUT' => $request->asJson()->put($url, $payload),
                'PATCH' => $request->asJson()->patch($url, $payload),
                'DELETE' => empty($payload) ? $request->delete($url) : $request->asJson()->delete($url, $payload),
                default => throw new \InvalidArgumentException("Unsupported HTTP method [{$method}]."),
            };
        } catch (ConnectionException $exception) {
            if ($this->serviceConfigKey === 'nium') {
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                $this->logNiumRequest(
                    $user,
                    $relatedTransferId,
                    $method,
                    $url,
                    $payload,
                    null,
                    [],
                    $durationMs,
                    $startedAtUtc,
                    CarbonImmutable::now('UTC'),
                    $this->connectionOutcome($exception),
                );
            }

            throw $exception;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->logRequest(
            $user,
            $relatedTransferId,
            $method,
            $url,
            $payload,
            $response,
            $durationMs,
            $startedAtUtc,
            CarbonImmutable::now('UTC'),
        );

        return $response;
    }

    private function resolveHeaders(): array
    {
        $customAuthorizationHeader = collect($this->headers)
            ->keys()
            ->contains(static fn ($header) => strtolower((string) $header) === 'authorization');

        return array_filter([
            ...($customAuthorizationHeader ? [] : $this->resolveAuthHeaders()),
            ...$this->headers,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function resolveAuthHeaders(): array
    {
        $authConfig = (array) config("services.{$this->serviceConfigKey}.auth", []);
        $mode = strtolower((string) ($authConfig['mode'] ?? 'none'));

        return match ($mode) {
            '', 'none', 'static_headers' => [],
            'basic_auth' => $this->getBasicAuthHeaders($authConfig),
            'bearer_token' => filled($authConfig['token'] ?? null)
                ? ['Authorization' => 'Bearer '.$authConfig['token']]
                : [],
            'airwallex_access_token' => ['Authorization' => 'Bearer '.$this->getAirwallexAccessToken($authConfig)],
            'header' => filled($authConfig['header_name'] ?? null) && array_key_exists('header_value', $authConfig)
                ? [(string) $authConfig['header_name'] => $authConfig['header_value']]
                : [],
            'client_credentials' => ['Authorization' => 'Bearer '.$this->getClientCredentialsAccessToken($authConfig)],
            'unlimit_access_token' => ['Authorization' => 'Bearer '.$this->getUnlimitAccessToken($authConfig)],
            'pingpong_access_token' => ['Authorization' => $this->getPingPongAccessToken($authConfig)],
            default => throw new RuntimeException("Unsupported auth mode [{$mode}] for provider [{$this->serviceConfigKey}]."),
        };
    }

    private function getBasicAuthHeaders(array $authConfig): array
    {
        $username = (string) ($authConfig['username'] ?? '');
        $password = (string) ($authConfig['password'] ?? '');

        if ($username === '' || $password === '') {
            throw new RuntimeException("Basic auth credentials are not configured for provider [{$this->serviceConfigKey}].");
        }

        return [
            'Authorization' => 'Basic '.base64_encode($username.':'.$password),
        ];
    }

    private function getAirwallexAccessToken(array $authConfig): string
    {
        $cacheKey = (string) ($authConfig['cache_key'] ?? "provider_http_client:{$this->serviceConfigKey}:access_token");
        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        $clientId = (string) ($authConfig['client_id'] ?? '');
        $apiKey = (string) config("services.{$this->serviceConfigKey}.x_api_key", '');

        if ($clientId === '' || $apiKey === '') {
            throw new RuntimeException("Airwallex credentials are not configured for provider [{$this->serviceConfigKey}].");
        }

        $tokenEndpoint = (string) ($authConfig['token_endpoint'] ?? '/api/v1/authentication/login');
        $tokenUrl = (string) ($authConfig['token_url'] ?? '');
        $request = Http::timeout((int) config("services.{$this->serviceConfigKey}.timeout", 30))
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'x-api-key' => $apiKey,
                'x-client-id' => $clientId,
            ]);

        foreach ((array) ($authConfig['headers'] ?? []) as $headerName => $headerValue) {
            $request = $request->withHeader((string) $headerName, $headerValue);
        }

        $payload = array_filter([
            'client_secret' => $authConfig['client_secret'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        $response = $request->post(
            $tokenUrl !== '' ? $this->buildUrl($tokenUrl) : $this->buildUrl($tokenEndpoint),
            $payload,
        );
        $responseData = $response->json() ?? [];
        $token = $responseData['token'] ?? $responseData['access_token'] ?? null;

        if (! $response->successful() || ! filled($token)) {
            throw new RuntimeException("Failed to obtain access token for provider [{$this->serviceConfigKey}].");
        }

        $expiresAt = $responseData['expires_at'] ?? null;
        $bufferSeconds = (int) ($authConfig['cache_buffer_seconds'] ?? 30);
        $ttl = 300;

        if (is_string($expiresAt) && $expiresAt !== '') {
            try {
                $ttl = max(60, now()->diffInSeconds(Carbon::parse($expiresAt), false) - $bufferSeconds);
            } catch (Throwable) {
                $ttl = 300;
            }
        }

        Cache::put($cacheKey, (string) $token, now()->addSeconds($ttl));

        return (string) $token;
    }

    private function getClientCredentialsAccessToken(array $authConfig): string
    {
        $cacheKey = (string) ($authConfig['cache_key'] ?? "provider_http_client:{$this->serviceConfigKey}:access_token");
        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        $clientId = (string) ($authConfig['client_id'] ?? '');
        $clientSecret = (string) ($authConfig['client_secret'] ?? '');

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException("Client credentials are not configured for provider [{$this->serviceConfigKey}].");
        }

        $tokenEndpoint = (string) ($authConfig['token_endpoint'] ?? '');
        $tokenUrl = (string) ($authConfig['token_url'] ?? '');
        $credentialsIn = strtolower((string) ($authConfig['credentials_in'] ?? 'body'));

        if ($tokenUrl === '' && $tokenEndpoint === '') {
            throw new RuntimeException("Token endpoint is not configured for provider [{$this->serviceConfigKey}].");
        }

        $request = Http::timeout((int) config("services.{$this->serviceConfigKey}.timeout", 30))
            ->acceptJson()
            ->asForm();

        foreach ((array) ($authConfig['headers'] ?? []) as $headerName => $headerValue) {
            $request = $request->withHeader((string) $headerName, $headerValue);
        }

        $payload = array_filter([
            'grant_type' => (string) ($authConfig['grant_type'] ?? 'client_credentials'),
            'scope' => $authConfig['scope'] ?? null,
            'audience' => $authConfig['audience'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        if ($credentialsIn === 'basic') {
            $request = $request->withBasicAuth($clientId, $clientSecret);
        } else {
            $payload['client_id'] = $clientId;
            $payload['client_secret'] = $clientSecret;
        }

        $response = $request->post(
            $tokenUrl !== '' ? $this->buildUrl($tokenUrl) : $this->buildUrl($tokenEndpoint),
            $payload,
        );

        $responseData = $response->json() ?? [];

        if (! $response->successful() || ! filled($responseData['access_token'] ?? null)) {
            throw new RuntimeException("Failed to obtain access token for provider [{$this->serviceConfigKey}].");
        }

        $token = (string) $responseData['access_token'];
        $expiresIn = max(60, (int) ($responseData['expires_in'] ?? 300) - (int) ($authConfig['cache_buffer_seconds'] ?? 30));

        Cache::put($cacheKey, $token, now()->addSeconds($expiresIn));

        return $token;
    }

    private function getUnlimitAccessToken(array $authConfig): string
    {
        $cacheKey = (string) ($authConfig['cache_key'] ?? "provider_http_client:{$this->serviceConfigKey}:access_token");
        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        $terminalCode = (string) ($authConfig['terminal_code'] ?? '');
        $password = (string) ($authConfig['password'] ?? '');

        if ($terminalCode === '' || $password === '') {
            throw new RuntimeException("Unlimit terminal credentials are not configured for provider [{$this->serviceConfigKey}].");
        }

        $tokenEndpoint = (string) ($authConfig['token_endpoint'] ?? '/auth/token');
        $tokenUrl = (string) ($authConfig['token_url'] ?? '');

        $response = Http::timeout((int) config("services.{$this->serviceConfigKey}.timeout", 30))
            ->acceptJson()
            ->asForm()
            ->post(
                $tokenUrl !== '' ? $this->buildUrl($tokenUrl) : $this->buildUrl($tokenEndpoint),
                [
                    'grant_type' => (string) ($authConfig['grant_type'] ?? 'password'),
                    'terminal_code' => $terminalCode,
                    'password' => $password,
                ],
            );

        $responseData = $response->json() ?? [];

        if (! $response->successful() || ! filled($responseData['access_token'] ?? null)) {
            throw new RuntimeException("Failed to obtain Unlimit access token for provider [{$this->serviceConfigKey}].");
        }

        $token = (string) $responseData['access_token'];
        $expiresIn = max(60, (int) ($responseData['expires_in'] ?? 300) - (int) ($authConfig['cache_buffer_seconds'] ?? 30));

        Cache::put($cacheKey, $token, now()->addSeconds($expiresIn));

        return $token;
    }

    private function getPingPongAccessToken(array $authConfig): string
    {
        $cacheKey = (string) ($authConfig['cache_key'] ?? "provider_http_client:{$this->serviceConfigKey}:access_token");
        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        $appId = (string) ($authConfig['app_id'] ?? '');
        $appSecret = (string) ($authConfig['app_secret'] ?? '');

        if ($appId === '' || $appSecret === '') {
            throw new RuntimeException("PingPong credentials are not configured for provider [{$this->serviceConfigKey}].");
        }

        $tokenEndpoint = (string) ($authConfig['token_endpoint'] ?? '/v2/token/get');

        $response = Http::timeout((int) config("services.{$this->serviceConfigKey}.timeout", 30))
            ->acceptJson()
            ->get($this->buildUrl($tokenEndpoint), [
                'app_id' => $appId,
                'app_secret' => $appSecret,
            ]);

        $responseData = $response->json() ?? [];

        if (! $response->successful() || ! filled($responseData['access_token'] ?? null)) {
            throw new RuntimeException("Failed to obtain PingPong access token for provider [{$this->serviceConfigKey}].");
        }

        $token = (string) $responseData['access_token'];
        $expiresIn = max(60, (int) ($responseData['expires_in'] ?? 7200) - (int) ($authConfig['cache_buffer_seconds'] ?? 300));

        Cache::put($cacheKey, $token, now()->addSeconds($expiresIn));

        return $token;
    }

    private function logRequest(
        ?User $user,
        ?int $relatedTransferId,
        string $method,
        string $url,
        array $payload,
        Response $response,
        int $durationMs,
        CarbonImmutable $startedAt,
        CarbonImmutable $finishedAt,
    ): void {
        if ($this->serviceConfigKey === 'nium') {
            [$responseBody, $malformedJson] = $this->decodedResponse($response);
            $this->logNiumRequest(
                $user,
                $relatedTransferId,
                $method,
                $url,
                $payload,
                $response,
                $responseBody,
                $durationMs,
                $startedAt,
                $finishedAt,
                $malformedJson ? 'malformed_response' : 'response_received',
            );

            return;
        }

        $responseBody = $response->json() ?? ['raw' => $response->body()];
        $responseData = is_array($responseBody) ? $responseBody : [];

        ApiRequestLog::create([
            'provider_id' => $this->provider->id,
            'user_id' => $user?->id,
            'related_transfer_id' => $relatedTransferId,
            'request_method' => $method,
            'request_url' => $this->sensitiveDataSanitizer->sanitize($url),
            'request_headers' => $this->sensitiveDataSanitizer->sanitize($this->resolveHeaders()),
            'request_body' => $this->sensitiveDataSanitizer->sanitize($payload),
            'response_status' => $response->status(),
            'response_headers' => $this->sensitiveDataSanitizer->sanitize(
                Arr::map($response->headers(), static fn ($value) => implode(', ', $value))
            ),
            'response_body' => $this->sensitiveDataSanitizer->sanitize($responseBody),
            'duration_ms' => $durationMs,
            'is_success' => $response->successful(),
        ]);
    }

    private function logNiumRequest(
        ?User $user,
        ?int $relatedTransferId,
        string $method,
        string $url,
        array $payload,
        ?Response $response,
        mixed $responseBody,
        int $durationMs,
        CarbonImmutable $startedAt,
        CarbonImmutable $finishedAt,
        string $transportOutcome,
    ): void {
        $requestId = collect($this->headers)
            ->first(fn ($value, $key) => strtolower((string) $key) === 'x-request-id');
        $responseData = is_array($responseBody) ? $responseBody : [];
        if (str_contains($url, '/transactions')) {
    logger()->info('NIUM_TRANSACTION_RAW_DEBUG', [
        'url' => $url,
        'body' => $responseData,
    ]);
}
        $projector = $this->niumSafeValueProjector
            ?? new NiumSafeValueProjector($this->sensitiveDataSanitizer);
        $operation = $projector->providerIdentifier($this->operationalContext['operation'] ?? $this->inferOperation($method, $url));
        $stagingTransferDiagnostics = app()->environment('staging') && $operation === 'transfer_money';
        $transferIdentifiers = $stagingTransferDiagnostics
            ? $projector->transferMoneyIdentifierDiagnostics($url)
            : [];
        $requestHeaders = $projector->apiRequestHeaders($requestId);
        $requestBody = $stagingTransferDiagnostics
            ? array_replace($projector->transferMoneyRequestBody($payload), $transferIdentifiers)
            : $projector->apiRequestBody($payload);
        if ($operation === 'beneficiary_create') {
    \Log::channel('single')->info('NIUM_BENEFICIARY_PAYLOAD_DEBUG', [
        'payload' => $payload,
    ]);
}
        $responseHeaders = $projector->apiResponseHeaders($response?->header('x-request-id'));
        if (str_contains((string) $operation, 'transaction_rfi')) {
    logger()->info('NIUM_RFI_RAW_RESPONSE', [
        'operation' => $operation,
        'status' => $response?->status(),
        'body' => $responseData,
    ]);
}
        $safeResponseBody = $projector->apiResponseBody($responseData, $response?->status());
        if ($stagingTransferDiagnostics) {
            $safeResponseBody = array_replace(
                $safeResponseBody,
                $projector->transferMoneyResponseBody($responseData),
                $transferIdentifiers,
            );
        }
        $safeResponseBody = array_filter([
            ...$safeResponseBody,
            'response_received' => $response !== null,
            'no_response_received' => $response === null,
            'external_outcome' => $response === null && $method !== 'GET' ? 'unknown_external_outcome' : null,
        ], static fn ($value): bool => $value !== null);

        $attributes = [
            'provider_id' => $this->provider->id,
            'user_id' => $user?->id,
            'related_transfer_id' => $relatedTransferId,
            'operation' => $operation,
            'client_hash_id' => $projector->clientHashId($this->operationalContext['client_hash_id'] ?? config('services.nium.client_id')),
            'external_reference' => $projector->providerIdentifier($this->operationalContext['external_reference'] ?? null),
            'request_method' => $method,
            'request_url' => $this->safeNiumUrl($url),
            'endpoint_path' => $this->safeNiumEndpointPath($url),
            'request_headers' => $requestHeaders,
            'request_body' => $requestBody,
            'response_status' => $projector->safeHttpStatus($response?->status()),
            'response_headers' => $responseHeaders,
            'response_body' => $safeResponseBody,
            'request_started_at' => $startedAt,
            'request_finished_at' => $finishedAt,
            'content_type' => $projector->contentType($response?->header('Content-Type')),
            'transport_outcome' => $transportOutcome,
            'duration_ms' => $durationMs,
            'is_success' => $response?->successful(),
        ];

        try {
            ApiRequestLog::create($attributes);
        } catch (Throwable $exception) {
            throw new NiumEvidencePersistenceException([
                'client_hash_id' => $attributes['client_hash_id'],
                'operation' => $attributes['operation'],
                'endpoint' => $attributes['endpoint_path'],
                'external_reference' => $attributes['external_reference'],
                'x_request_id' => $requestHeaders['x-request-id'] ?? null,
                'request_started_at_utc' => $startedAt->toIso8601String(),
                'request_finished_at_utc' => $finishedAt->toIso8601String(),
                'http_status' => $attributes['response_status'],
                'transport_outcome' => 'persistence_failure',
            ], $exception);
        }
    }

    private function decodedResponse(Response $response): array
    {
        $body = $response->body();

        if ($body === '') {
            return [[], false];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            return [is_array($decoded) ? $decoded : [], ! is_array($decoded)];
        } catch (\JsonException) {
            return [[], true];
        }
    }

    private function connectionOutcome(ConnectionException $exception): string
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timed out') || str_contains($message, 'curl error 28')
            ? 'timeout_before_response'
            : 'connection_failure';
    }

    private function inferOperation(string $method, string $url): string
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return match (true) {
            $method === 'POST' && str_ends_with($path, '/customers') => 'customer_create',
            $method === 'POST' && str_ends_with($path, '/remittance') => 'transfer_money',
            $method === 'POST' && str_ends_with($path, '/paymentid') => 'assign_payment_id',
            $method === 'POST' && str_ends_with($path, '/beneficiaries') => 'beneficiary_create',
            default => strtolower($method).'_nium_api',
        };
    }

    private function safeNiumEndpointPath(string $url): string
    {
        $safeUrl = $this->safeNiumUrl($url);
        $path = (string) parse_url($safeUrl, PHP_URL_PATH);

        return $path !== '' ? $path : '/[REDACTED]';
    }

    private function safeNiumUrl(string $url): string
    {
        $fallback = 'https://configured-nium-host/[REDACTED]';
        $configuredParts = parse_url((string) config('services.nium.base_url'));

        if (! is_array($configuredParts)) {
            return $fallback;
        }

        $scheme = strtolower((string) ($configuredParts['scheme'] ?? ''));
        $host = strtolower((string) ($configuredParts['host'] ?? ''));
        $validHost = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            || filter_var($host, FILTER_VALIDATE_IP) !== false;

        if (! in_array($scheme, ['http', 'https'], true) || ! $validHost) {
            return $fallback;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return $fallback;
        }

        $segments = explode('/', (string) ($parts['path'] ?? '/'));
        $redactNext = false;

        foreach ($segments as $index => $segment) {
            $decoded = rawurldecode($segment);
            $normalized = strtolower($decoded);

            if (preg_match('/[[:cntrl:]]/', $decoded) === 1) {
                return $fallback;
            }

            if ($redactNext) {
                $segments[$index] = '[REDACTED]';
                $redactNext = false;

                continue;
            }

            if (in_array($normalized, ['client', 'clients', 'customer', 'customers', 'wallet', 'wallets'], true)) {
                $redactNext = true;

                continue;
            }

            if (in_array($normalized, ['api', 'v5'], true)) {
                continue;
            }

            if (
                preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $decoded) === 1
                || preg_match('/^[a-z0-9_-]{16,}$/i', $decoded) === 1
            ) {
                $segments[$index] = '[REDACTED]';
            }
        }

        $path = implode('/', $segments);
        $path = str_starts_with($path, '/') ? $path : '/'.$path;
        $candidate = $scheme.'://'.$host.($path !== '' ? $path : '/');

        if (strlen($candidate) > 512) {
            return $fallback;
        }

        $sanitized = $this->sensitiveDataSanitizer->sanitize($candidate);

        return is_string($sanitized) && $sanitized === $candidate
            ? $candidate
            : $fallback;
    }
}
