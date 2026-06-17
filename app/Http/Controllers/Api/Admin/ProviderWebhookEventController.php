<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\WebhookEvent;
use App\Services\Integrations\Contracts\ReprocessesWebhookEvent;
use App\Services\Integrations\ProviderRegistry;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProviderWebhookEventController extends Controller
{
    public function __construct(
        private readonly SensitiveDataSanitizer $sensitiveDataSanitizer,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_code' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:30'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $events = WebhookEvent::query()
            ->with('provider')
            ->when(
                filled($validated['provider_code'] ?? null) && $validated['provider_code'] !== 'all',
                fn ($query) => $query->whereHas('provider', fn ($query) => $query->where('code', $validated['provider_code']))
            )
            ->when(
                filled($validated['status'] ?? null) && $validated['status'] !== 'all',
                fn ($query) => $query->where('processing_status', $validated['status'])
            )
            ->when(filled($validated['search'] ?? null), function ($query) use ($validated): void {
                $search = (string) $validated['search'];

                $query->where(function ($query) use ($search): void {
                    $query->where('event_id', 'like', "%{$search}%")
                        ->orWhere('event_type', 'like', "%{$search}%")
                        ->orWhere('external_resource_id', 'like', "%{$search}%")
                        ->orWhere('error_message', 'like', "%{$search}%")
                        ->orWhereHas('provider', function ($query) use ($search): void {
                            $query->where('code', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('id')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json($events->through(fn (WebhookEvent $event) => $this->payload($event)));
    }

    public function show(WebhookEvent $providerWebhookEvent): JsonResponse
    {
        return response()->json($this->payload($providerWebhookEvent->load('provider')));
    }

    public function retry(
        Request $request,
        WebhookEvent $providerWebhookEvent,
        ProviderRegistry $registry,
    ): JsonResponse {
        if ($providerWebhookEvent->processing_status === 'processed') {
            return response()->json([
                'message' => 'This webhook event is already processed.',
            ], 422);
        }

        $event = DB::transaction(function () use ($request, $providerWebhookEvent): WebhookEvent {
            $oldData = $this->sensitiveDataSanitizer->sanitize($providerWebhookEvent->toArray());
            $payload = (array) ($providerWebhookEvent->payload ?? []);
            $currentRetry = (array) Arr::get($payload, '_origin_retry', []);
            $retryCount = (int) ($currentRetry['count'] ?? 0) + 1;

            $payload['_origin_retry'] = [
                'count' => $retryCount,
                'requested_at' => now()->toISOString(),
                'requested_by_user_id' => $request->user()?->id,
            ];

            $providerWebhookEvent->update([
                'processing_status' => 'retrying',
                'error_message' => null,
                'payload' => $payload,
            ]);

            AuditLog::query()->create([
                'user_id' => $request->user()?->id,
                'action' => 'webhook.retry_requested',
                'entity_type' => 'webhook_event',
                'entity_id' => (string) $providerWebhookEvent->id,
                'old_data' => $oldData,
                'new_data' => $this->sensitiveDataSanitizer->sanitize($providerWebhookEvent->fresh()->toArray()),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ]);

            return $providerWebhookEvent->fresh('provider');
        });

        try {
            if ($event->provider !== null && $event->provider->supportsWebhooks()) {
                $webhookProvider = $registry->resolveWebhookProvider($event->provider);

                if ($webhookProvider instanceof ReprocessesWebhookEvent) {
                    $event = $webhookProvider->reprocessWebhookEvent($event->provider, $event);
                }
            }
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'Webhook retry failed.',
                'error' => $exception->getMessage(),
                'webhook_event' => $this->payload($event->fresh('provider')),
            ], 422);
        }

        return response()->json([
            'message' => 'Webhook retry has been requested.',
            'webhook_event' => $this->payload($event),
        ]);
    }

    private function payload(WebhookEvent $event): array
    {
        $retry = (array) Arr::get((array) ($event->payload ?? []), '_origin_retry', []);

        return [
            'id' => $event->id,
            'provider_id' => $event->provider_id,
            'provider_code' => $event->provider?->code ?? '',
            'provider' => $event->provider?->summaryPayload(),
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'status' => $event->processing_status,
            'related_reference' => $event->external_resource_id,
            'attempts' => (int) ($retry['count'] ?? 0),
            'received_at' => $event->received_at,
            'processed_at' => $event->processed_at,
            'next_retry_at' => null,
            'error_message' => $event->error_message,
            'payload' => $this->sensitiveDataSanitizer->sanitize($event->payload),
        ];
    }
}
