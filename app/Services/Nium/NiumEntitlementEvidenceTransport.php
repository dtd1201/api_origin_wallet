<?php

namespace App\Services\Nium;

use Closure;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Command-only transport. No application HTTP factory, events, models, cache or evidence store. */
final class NiumEntitlementEvidenceTransport
{
    public const ENDPOINTS = [
        'client_details' => '/api/v1/client/{clientHashId}',
        'supported_corridors' => '/api/v3/client/{clientHashId}/supportedCorridors',
        'customer_corridors' => '/api/v1/client/{clientHashId}/customer/{customerHashId}/supportedCorridors',
        'beneficiary_schema' => '/api/v2/client/{clientHashId}/customer/{customerHashId}/currency/{currencyCode}/validationSchemas',
        'payout_schema' => '/api/v1/client/{clientHashId}/customer/{customerHashId}/currency/{currencyCode}/payout/schema',
        'fee_plans' => '/api/v3/client/{clientHashId}/fees',
        'purpose_codes' => '/api/v1/remittance/purposeCodes',
    ];

    private Factory $http;

    public function __construct()
    {
        // Deliberately no dispatcher or app/global middleware (including HTTP logging listeners).
        $this->http = new Factory;
    }

    public function assertIsolated(NiumEntitlementEvidenceScope $scope): void
    {
        $auth = config('services.nium.auth');
        if (! is_array($auth) || ($auth['mode'] ?? null) !== 'header'
            || strtolower((string) ($auth['header_name'] ?? '')) !== 'x-api-key'
            || ! is_string($auth['header_value'] ?? null) || strlen($auth['header_value']) < 16
            || preg_match('/[\x00-\x20\x7f]/', $auth['header_value'])
            || $scope->origin !== rtrim((string) config('services.nium.base_url'), '/')
            || $scope->clientId() !== config('services.nium.client_id')
            || $this->http->getDispatcher() !== null || $this->http->getGlobalMiddleware() !== []) {
            throw new RuntimeException('evidence_transport_isolation_unavailable');
        }
    }

    /** Optional server decoder preserves raw JSON types for isolated inspections. */
    public function read(NiumEntitlementEvidenceScope $scope, string $endpoint, array $filters = [], int $page = 0, string $method = 'GET', ?Closure $decode = null): array
    {
        $this->assertIsolated($scope);
        if ($method !== 'GET' || ! isset(self::ENDPOINTS[$endpoint]) || $page < 0 || $page >= 100) {
            throw new RuntimeException('evidence_request_not_allowlisted');
        }
        if ($filters !== [] && ! in_array($filters, $scope->corridors, true)) {
            throw new RuntimeException('evidence_filters_not_approved');
        }
        $template = self::ENDPOINTS[$endpoint];
        if ((str_contains($template, '{customerHashId}') && $scope->customerId() === null)
            || (str_contains($template, '{currencyCode}') && $filters === [])
            || ($endpoint === 'supported_corridors' && $filters === [])
            || (! in_array($endpoint, ['supported_corridors', 'beneficiary_schema', 'payout_schema'], true) && ($filters !== [] || $page !== 0))
            || ($endpoint !== 'supported_corridors' && $page !== 0)) {
            throw new RuntimeException('evidence_scope_required');
        }
        $path = strtr($template, ['{clientHashId}' => $scope->clientId(),
            '{customerHashId}' => $scope->customerId() ?? '', '{currencyCode}' => $filters['destinationCurrency'] ?? '']);
        $query = match ($endpoint) {
            'supported_corridors' => [...$filters, 'page' => $page, 'size' => 100, 'order' => 'ASC'],
            'beneficiary_schema', 'payout_schema' => ['destinationCountry' => $filters['destinationCountry'], 'payoutMethod' => $filters['payoutMethod']],
            default => [],
        };
        $manifest = ['endpoint' => $endpoint, 'method' => 'GET', 'endpoint_template' => $template,
            'filters' => $filters, 'query' => $query, 'request_id' => (string) Str::uuid(),
            'started_at_utc' => now('UTC')->toISOString()];
        try {
            // Laravel retry(1, 0) means one total attempt, with zero retries.
            $response = $this->http->createPendingRequest()->acceptJson()
                ->withHeaders(['x-api-key' => config('services.nium.auth.header_value'), 'x-request-id' => $manifest['request_id']])
                ->timeout(30)->connectTimeout(10)->withoutRedirecting()->retry(1, 0)
                ->withOptions(['debug' => false, 'cookies' => false, 'verify' => true, 'http_errors' => false])
                ->get($scope->origin.$path, $query);
            $manifest['retrieved_at_utc'] = now('UTC')->toISOString();
            $manifest['http_status'] = $response->status();
            $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'), 2)[0]));
            $manifest['content_type'] = in_array($type, ['application/json', 'application/octet-stream', 'text/plain'], true) ? $type : 'UNKNOWN';
            if (! $response->successful()) {
                return ['manifest' => $manifest, 'failure' => 'http_error', 'body' => null];
            }
            if (strlen($response->body()) > 4 * 1024 * 1024) {
                return ['manifest' => $manifest, 'failure' => 'response_size_limit', 'body' => null];
            }
            $body = $decode === null
                ? json_decode($response->body(), true, 40, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING)
                : $decode($response->body());

            return ['manifest' => $manifest, 'failure' => null, 'body' => $body];
        } catch (Throwable) {
            // Exception text, URLs, headers and raw bodies must never reach console/logging.
            return ['manifest' => [...$manifest, 'retrieved_at_utc' => now('UTC')->toISOString()],
                'failure' => 'transport_or_json_failure', 'body' => null];
        }
    }
}
