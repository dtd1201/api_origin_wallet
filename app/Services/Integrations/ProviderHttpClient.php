<?php

namespace App\Services\Integrations;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\Contracts\ProviderClient;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class ProviderHttpClient implements ProviderClient
{
    public function __construct(
        private readonly IntegrationProvider $provider,
        private readonly string $serviceConfigKey,
        private readonly array $headers = [],
        private readonly SensitiveDataSanitizer $sensitiveDataSanitizer = new SensitiveDataSanitizer(),
    ) {
    }

    public function post(string $path, array $payload, ?User $user = null, ?int $relatedTransferId = null): Response
    {
        return $this->send('POST', $path, $payload, $user, $relatedTransferId);
    }

    public function put(string $path, array $payload, ?User $user = null, ?int $relatedTransferId = null): Response
    {
        return $this->send('PUT', $path, $payload, $user, $relatedTransferId);
    }

    public function delete(string $path, array $payload = [], ?User $user = null, ?int $relatedTransferId = null): Response
    {
        return $this->send('DELETE', $path, $payload, $user, $relatedTransferId);
    }

    private function baseRequest(): PendingRequest
    {
        return Http::timeout((int) config("services.{$this->serviceConfigKey}.timeout", 30))
            ->acceptJson()
            ->asJson()
            ->withHeaders($this->headers);
    }

    private function buildUrl(string $path): string
    {
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
        $request = $this->baseRequest();
        $url = $this->buildUrl($path);
        $startedAt = microtime(true);

        $response = match ($method) {
            'POST' => $request->post($url, $payload),
            'PUT' => $request->put($url, $payload),
            'DELETE' => empty($payload) ? $request->delete($url) : $request->delete($url, $payload),
            default => throw new \InvalidArgumentException("Unsupported HTTP method [{$method}]."),
        };

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->logRequest($user, $relatedTransferId, $method, $url, $payload, $response, $durationMs);

        return $response;
    }

    private function logRequest(
        ?User $user,
        ?int $relatedTransferId,
        string $method,
        string $url,
        array $payload,
        Response $response,
        int $durationMs,
    ): void {
        ApiRequestLog::create([
            'provider_id' => $this->provider->id,
            'user_id' => $user?->id,
            'related_transfer_id' => $relatedTransferId,
            'request_method' => $method,
            'request_url' => $url,
            'request_headers' => $this->sensitiveDataSanitizer->sanitize($this->headers),
            'request_body' => $this->sensitiveDataSanitizer->sanitize($payload),
            'response_status' => $response->status(),
            'response_headers' => $this->sensitiveDataSanitizer->sanitize(
                Arr::map($response->headers(), static fn ($value) => implode(', ', $value))
            ),
            'response_body' => $this->sensitiveDataSanitizer->sanitize(
                $response->json() ?? ['raw' => $response->body()]
            ),
            'duration_ms' => $durationMs,
            'is_success' => $response->successful(),
        ]);
    }
}
