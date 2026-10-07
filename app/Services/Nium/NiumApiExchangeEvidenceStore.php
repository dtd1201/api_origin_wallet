<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\NiumApiExchangeEvidence;
use App\Support\SensitiveDataSanitizer;
use JsonException;

final class NiumApiExchangeEvidenceStore
{
    private const CAPTURE_VERSION = 1;

    private const SECRET_HEADER_NAME_PATTERNS = [
        'auth',
        'authorization',
        'token',
        'secret',
        'key',
        'signature',
        'credential',
        'password',
        'cookie',
        'apikey',
        'accesskey',
        'privatekey',
    ];

    public function __construct(
        private readonly SensitiveDataSanitizer $sensitiveDataSanitizer,
    ) {}

    public function persist(
        ApiRequestLog $log,
        string $requestMethod,
        string $requestUrl,
        ?string $requestContentType,
        ?string $responseContentType,
        ?string $rawRequestBody,
        ?string $rawResponseBody,
        array $requestHeaders,
        array $responseHeaders,
        bool $responseReceived,
    ): NiumApiExchangeEvidence {
        $safeRequestHeaders = $this->safeHeaders($requestHeaders);
        $safeResponseHeaders = $this->safeHeaders($responseHeaders);
        $requestHeadersPlaintext = $this->encodedHeaders($safeRequestHeaders);
        $responseHeadersPlaintext = $this->encodedHeaders($safeResponseHeaders);

        return NiumApiExchangeEvidence::query()->updateOrCreate(
            ['api_request_log_id' => $log->getKey()],
            [
                'request_method' => strtoupper($requestMethod),
                'request_url' => $requestUrl,
                'request_content_type' => $this->contentType($requestContentType),
                'response_content_type' => $this->contentType($responseContentType),
                'raw_request_body' => $rawRequestBody,
                'raw_response_body' => $responseReceived ? $rawResponseBody : null,
                'request_headers' => $safeRequestHeaders,
                'response_headers' => $safeResponseHeaders,
                'request_body_sha256' => $this->hash($rawRequestBody),
                'response_body_sha256' => $responseReceived ? $this->hash($rawResponseBody) : null,
                'request_headers_sha256' => hash('sha256', $requestHeadersPlaintext),
                'response_headers_sha256' => hash('sha256', $responseHeadersPlaintext),
                'request_byte_length' => $rawRequestBody === null ? null : strlen($rawRequestBody),
                'response_byte_length' => ! $responseReceived || $rawResponseBody === null ? null : strlen($rawResponseBody),
                'capture_version' => self::CAPTURE_VERSION,
                'response_received' => $responseReceived,
            ],
        );
    }

    private function safeHeaders(array $headers): array
    {
        $safe = [];

        foreach ($headers as $name => $values) {
            $normalizedName = strtolower(trim((string) $name));
            if ($normalizedName === '' || $this->isSensitiveHeaderName($normalizedName)) {
                continue;
            }

            $values = is_array($values) ? $values : [$values];
            $safeValues = [];
            foreach ($values as $value) {
                if (! is_scalar($value)) {
                    continue;
                }
                $value = (string) $value;
                $sanitized = $this->sensitiveDataSanitizer->sanitize($value);
                if (is_string($sanitized) && ! str_contains($sanitized, '[REDACTED]')) {
                    $safeValues[] = $sanitized;
                }
            }

            if ($safeValues !== []) {
                $safe[$normalizedName] = $safeValues;
            }
        }

        ksort($safe);

        return $safe;
    }

    private function isSensitiveHeaderName(string $name): bool
    {
        $normalizedName = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $name));

        foreach (self::SECRET_HEADER_NAME_PATTERNS as $pattern) {
            if (str_contains($normalizedName, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function encodedHeaders(array $headers): string
    {
        try {
            return json_encode($headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return '{}';
        }
    }

    private function contentType(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? substr($value, 0, 255) : null;
    }

    private function hash(?string $value): ?string
    {
        return $value === null ? null : hash('sha256', $value);
    }
}
