<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Throwable;

class ProviderHealthController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_code' => ['nullable', 'string', 'max:50'],
        ]);

        $providers = IntegrationProvider::query()
            ->when(
                filled($validated['provider_code'] ?? null) && $validated['provider_code'] !== 'all',
                fn ($query) => $query->where('code', $validated['provider_code'])
            )
            ->latest('id')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json($providers->through(fn (IntegrationProvider $provider) => $this->payload($provider)));
    }

    public function check(IntegrationProvider $provider): JsonResponse
    {
        $serviceConfigKey = strtolower($provider->code);

        if (! $provider->isConfigured()) {
            return response()->json([
                'message' => "{$provider->name} base URL is not configured.",
            ], 422);
        }

        $healthEndpoint = $this->healthEndpoint($serviceConfigKey);

        try {
            $response = (new ProviderHttpClient($provider, $serviceConfigKey))->get($healthEndpoint);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => "{$provider->name} health check failed.",
                'error' => $exception->getMessage(),
            ], 422);
        }

        if (! $response->successful()) {
            return response()->json([
                'message' => "{$provider->name} health check completed with a non-success response.",
                'provider_health' => $this->payload($provider->fresh()),
            ], 422);
        }

        return response()->json([
            'message' => "{$provider->name} health check completed.",
            'provider_health' => $this->payload($provider->fresh()),
        ]);
    }

    private function payload(IntegrationProvider $provider): array
    {
        $latestLog = ApiRequestLog::query()
            ->where('provider_id', $provider->id)
            ->latest('id')
            ->first();
        $latestSuccess = ApiRequestLog::query()
            ->where('provider_id', $provider->id)
            ->where('is_success', true)
            ->latest('id')
            ->first();
        $latestFailure = ApiRequestLog::query()
            ->where('provider_id', $provider->id)
            ->where('is_success', false)
            ->latest('id')
            ->first();

        return [
            'id' => $provider->id,
            'provider_id' => $provider->id,
            'provider_code' => $provider->code,
            'provider' => $provider->summaryPayload(),
            'status' => $this->status($provider, $latestLog),
            'environment' => config('services.'.strtolower($provider->code).'.environment')
                ?: config('app.env'),
            'last_checked_at' => $latestLog?->created_at,
            'last_success_at' => $latestSuccess?->created_at,
            'last_failure_at' => $latestFailure?->created_at,
            'latency_ms' => $latestLog?->duration_ms,
            'error_message' => $latestLog && ! $latestLog->is_success ? $this->errorMessage($latestLog) : null,
            'metadata' => [
                'is_configured' => $provider->isConfigured(),
                'supports_data_sync' => $provider->supportsDataSync(),
                'supports_transfers' => $provider->supportsTransfers(),
                'supports_webhooks' => $provider->supportsWebhooks(),
                'latest_response_status' => $latestLog?->response_status,
            ],
        ];
    }

    private function status(IntegrationProvider $provider, ?ApiRequestLog $latestLog): string
    {
        if ($provider->status !== 'active') {
            return 'maintenance';
        }

        if (! $provider->isConfigured()) {
            return 'degraded';
        }

        if ($latestLog === null) {
            return 'degraded';
        }

        return $latestLog->is_success ? 'operational' : 'down';
    }

    private function errorMessage(ApiRequestLog $log): string
    {
        $body = (array) ($log->response_body ?? []);

        return (string) (
            Arr::get($body, 'message')
            ?? Arr::get($body, 'error')
            ?? ($log->response_status ? "HTTP {$log->response_status}" : 'Provider request failed.')
        );
    }

    private function healthEndpoint(string $serviceConfigKey): string
    {
        $endpoint = (string) config("services.{$serviceConfigKey}.health_endpoint", '/');
        $clientId = (string) config("services.{$serviceConfigKey}.client_id", '');

        if ($clientId !== '') {
            $endpoint = str_replace('{client}', urlencode($clientId), $endpoint);
        }

        return $endpoint !== '' ? $endpoint : '/';
    }
}
