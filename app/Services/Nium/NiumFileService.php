<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class NiumFileService
{
    public function createFile(KycDocument $document, ?User $user = null): array
    {
        $diskName = trim((string) ($document->storage_disk
            ?: config('services.kyc.documents_disk', 'kyc_private')));
        $path = trim((string) $document->file_path);

        if ($diskName === '' || $path === '' || ! Storage::disk($diskName)->exists($path)) {
            throw new RuntimeException('The KYC document file is not available for Nium upload.');
        }

        $stream = Storage::disk($diskName)->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException('The KYC document file could not be opened for Nium upload.');
        }

        $fileName = $this->fileName($document, $path);
        $fileType = $this->fileType($document, $diskName, $path);
        $metadata = $this->createMetadata($document, $fileName, $fileType);
        $requestId = (string) Str::uuid();
        $url = $this->url(
            (string) config('services.nium.file_create_endpoint'),
            ['clientHashId' => $this->clientId()],
        );
        $startedAt = microtime(true);

        try {
            $response = Http::timeout((int) config('services.nium.timeout', 30))
                ->acceptJson()
                ->withHeaders($this->headers($requestId))
                ->attach('file', $stream, $fileName, ['Content-Type' => $fileType])
                ->attach(
                    'metadata',
                    json_encode($metadata, JSON_THROW_ON_ERROR),
                    null,
                    ['Content-Type' => 'application/json'],
                )
                ->post($url);
        } finally {
            fclose($stream);
        }

        $data = $this->decodedResponse($response);
        $this->logResponse(
            method: 'POST',
            url: $url,
            requestId: $requestId,
            fileId: $this->responseString($data, 'id'),
            state: $this->responseString($data, 'state'),
            response: $response,
            durationMs: $this->durationMs($startedAt),
            user: $user ?? $document->kycProfile?->user,
            document: $document,
        );

        if (! $response->successful()) {
            throw new RuntimeException("Nium file creation failed with HTTP status {$response->status()}.");
        }

        $sanitized = $this->sanitizeCreateResponse($data);
        $now = now()->toISOString();

        $document->forceFill([
            'metadata' => [
                ...(array) $document->metadata,
                'nium_file_id' => $sanitized['id'],
                'nium_file_state' => $sanitized['state'] ?? null,
                'nium_uploaded_at' => $now,
                'nium_last_checked_at' => $now,
            ],
        ])->save();

        return $sanitized;
    }

    public function fetchFileDetails(string $fileId, ?User $user = null): array
    {
        return $this->fetchFileDetailsRequest($fileId, $user);
    }

    public function refreshDocumentState(KycDocument $document, ?User $user = null): array
    {
        $fileId = $this->metadataString($document, 'nium_file_id');

        if ($fileId === null) {
            throw new RuntimeException('The KYC document does not have a Nium file id.');
        }

        $details = $this->fetchFileDetailsRequest(
            $fileId,
            $user ?? $document->kycProfile?->user,
            $document,
        );
        $metadata = [
            ...(array) $document->metadata,
            'nium_file_state' => $details['state'] ?? null,
            'nium_last_checked_at' => now()->toISOString(),
        ];

        if (array_key_exists('size', $details)) {
            $metadata['nium_file_size'] = $details['size'];
        }

        if (array_key_exists('mimeType', $details)) {
            $metadata['nium_file_mime_type'] = $details['mimeType'];
        }

        $document->forceFill(['metadata' => $metadata])->save();

        return $details;
    }

    private function fetchFileDetailsRequest(
        string $fileId,
        ?User $user = null,
        ?KycDocument $document = null,
    ): array {
        $fileId = trim($fileId);

        if ($fileId === '' || ! Str::isUuid($fileId)) {
            throw new RuntimeException('The Nium file id is invalid.');
        }

        $requestId = (string) Str::uuid();
        $url = $this->url(
            (string) config('services.nium.file_details_endpoint'),
            [
                'clientHashId' => $this->clientId(),
                'fileId' => $fileId,
            ],
        );
        $startedAt = microtime(true);
        $response = Http::timeout((int) config('services.nium.timeout', 30))
            ->acceptJson()
            ->withHeaders($this->headers($requestId))
            ->get($url);
        $data = $this->decodedResponse($response);

        $this->logResponse(
            method: 'GET',
            url: $url,
            requestId: $requestId,
            fileId: $fileId,
            state: $this->responseString($data, 'state'),
            response: $response,
            durationMs: $this->durationMs($startedAt),
            user: $user,
            document: $document,
        );

        if (! $response->successful()) {
            throw new RuntimeException("Nium file details request failed with HTTP status {$response->status()}.");
        }

        $sanitized = $this->sanitizeDetailsResponse($data);

        if (! hash_equals($fileId, $sanitized['id'])) {
            throw new RuntimeException('Nium file details returned a mismatching file id.');
        }

        return $sanitized;
    }

    private function createMetadata(KycDocument $document, string $fileName, string $fileType): array
    {
        $documentType = $this->documentType($document);

        return [
            'documentType' => $documentType,
            'fileName' => $fileName,
            'fileType' => $fileType,
            'description' => $documentType === 'business_registration_doc'
                ? 'KYB document'
                : 'KYC document',
            'environment' => app()->environment('production') ? 'production' : 'sandbox',
            'isVerified' => in_array(
                strtolower(trim((string) $document->status)),
                ['approved', 'verified'],
                true,
            ),
            'label' => $this->label($document),
        ];
    }

    private function documentType(KycDocument $document): string
    {
        $type = strtolower(trim((string) $document->type));
        $mapped = match ($type) {
            'business_registration', 'certificate_of_incorporation' => 'business_registration_doc',
            'passport_front', 'passport_back', 'passport' => 'passport',
            'national_id_front', 'national_id_back', 'national_id' => 'national_id',
            'drivers_license_front', 'drivers_license_back', 'drivers_license' => 'drivers_license',
            default => null,
        };

        if ($mapped !== null) {
            return $mapped;
        }

        $override = $this->metadataString($document, 'nium_document_type');

        return $this->safeIdentifier($override ?? $type, 'document');
    }

    private function fileName(KycDocument $document, string $path): string
    {
        $candidate = trim((string) $document->original_name);

        if ($candidate === '') {
            $candidate = basename(str_replace('\\', '/', $path));
        }

        $candidate = basename(str_replace('\\', '/', $candidate));
        $candidate = preg_replace('/[\x00-\x1F\x7F]/', '', $candidate) ?? '';
        $candidate = trim($candidate);

        return $candidate !== ''
            ? Str::limit($candidate, 255, '')
            : 'kyc-document-'.$document->getKey();
    }

    private function fileType(KycDocument $document, string $diskName, string $path): string
    {
        $candidate = strtolower(trim((string) $document->mime_type));

        if ($candidate === '') {
            $detected = Storage::disk($diskName)->mimeType($path);
            $candidate = is_string($detected) ? strtolower(trim($detected)) : '';
        }

        return preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#', $candidate) === 1
            ? Str::limit($candidate, 100, '')
            : 'application/octet-stream';
    }

    private function label(KycDocument $document): string
    {
        $label = Str::slug(str_replace('_', '-', (string) $document->type));

        return $label !== '' ? Str::limit($label, 100, '') : 'document';
    }

    private function sanitizeCreateResponse(array $data): array
    {
        $id = $this->responseString($data, 'id');

        if ($id === null) {
            throw new RuntimeException('Nium file creation response did not include an id.');
        }

        return array_filter([
            'id' => $id,
            'description' => $this->responseString($data, 'description', 500),
            'state' => $this->responseString($data, 'state', 100),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function sanitizeDetailsResponse(array $data): array
    {
        $id = $this->responseString($data, 'id');

        if ($id === null) {
            throw new RuntimeException('Nium file details response did not include an id.');
        }

        $size = $data['size'] ?? null;
        $size = is_numeric($size) && (int) $size >= 0 ? (int) $size : null;

        return array_filter([
            'id' => $id,
            'name' => $this->responseString($data, 'name', 255),
            'size' => $size,
            'mimeType' => $this->responseString($data, 'mimeType', 100),
            'state' => $this->responseString($data, 'state', 100),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function decodedResponse(Response $response): array
    {
        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    private function responseString(array $data, string $key, int $limit = 255): ?string
    {
        $value = $data[$key] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', trim((string) $value)) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        return $value !== '' ? Str::limit($value, $limit, '') : null;
    }

    private function metadataString(KycDocument $document, string $key): ?string
    {
        $value = ((array) $document->metadata)[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function safeIdentifier(string $value, string $fallback): string
    {
        $value = trim($value);

        return preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $value) === 1 ? $value : $fallback;
    }

    private function headers(string $requestId): array
    {
        $apiKey = (string) config('services.nium.auth.header_value', '');

        if ($apiKey === '') {
            throw new RuntimeException('Nium API authentication is not configured.');
        }

        return [
            'x-api-key' => $apiKey,
            'x-request-id' => $requestId,
        ];
    }

    private function clientId(): string
    {
        $clientId = trim((string) config('services.nium.client_id', ''));

        if ($clientId === '') {
            throw new RuntimeException('Nium client id is not configured.');
        }

        return $clientId;
    }

    private function url(string $endpoint, array $replacements): string
    {
        $baseUrl = rtrim(trim((string) config('services.nium.file_base_url', '')), '/');
        $parts = parse_url($baseUrl);

        if (
            $baseUrl === ''
            || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || blank($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new RuntimeException('Nium file base URL must be a safe HTTPS URL.');
        }

        $endpoint = trim($endpoint);

        if (
            $endpoint === ''
            || ! str_starts_with($endpoint, '/')
            || str_starts_with($endpoint, '//')
            || preg_match('/[\x00-\x20]/', $endpoint) === 1
            || preg_match('#^https?://#i', $endpoint) === 1
        ) {
            throw new RuntimeException('Nium file endpoint must be a safe relative path.');
        }

        foreach ($replacements as $key => $value) {
            $value = trim((string) $value);

            if ($value === '') {
                throw new RuntimeException("Nium file endpoint replacement [{$key}] is empty.");
            }

            $endpoint = str_replace('{'.$key.'}', rawurlencode($value), $endpoint);
        }

        if (preg_match('/\{[^}]+\}/', $endpoint) === 1) {
            throw new RuntimeException('Nium file endpoint contains an unresolved placeholder.');
        }

        return $baseUrl.$endpoint;
    }

    private function logResponse(
        string $method,
        string $url,
        string $requestId,
        ?string $fileId,
        ?string $state,
        Response $response,
        int $durationMs,
        ?User $user,
        ?KycDocument $document,
    ): void {
        $provider = IntegrationProvider::query()->where('code', 'nium')->first();

        if ($provider === null) {
            return;
        }

        ApiRequestLog::create([
            'provider_id' => $provider->id,
            'user_id' => $user?->id,
            'request_method' => $method,
            'request_url' => $this->safeEndpointPath($url),
            'request_headers' => ['x-request-id' => $requestId],
            'request_body' => array_filter([
                'kyc_document_id' => $document?->id,
            ], static fn (mixed $value): bool => $value !== null),
            'response_status' => $response->status(),
            'response_headers' => array_filter([
                'x-request-id' => $response->header('x-request-id'),
                'content-type' => $response->header('content-type'),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'response_body' => array_filter([
                'nium_file_id' => $fileId,
                'state' => $state,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'duration_ms' => $durationMs,
            'is_success' => $response->successful(),
        ]);
    }

    private function safeEndpointPath(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

        return preg_replace('#/client/[^/]+#i', '/client/[REDACTED]', $path) ?: '/';
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
