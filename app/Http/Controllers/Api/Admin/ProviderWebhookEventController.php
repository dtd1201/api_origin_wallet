<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\WebhookEvent;
use App\Services\Integrations\Contracts\ReprocessesWebhookEvent;
use App\Services\Integrations\ProviderRegistry;
use App\Support\NiumOperationalData;
use App\Support\PrimaryProvider;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class ProviderWebhookEventController extends Controller
{
    public function __construct(
        private readonly SensitiveDataSanitizer $sensitiveDataSanitizer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_code' => ['nullable', 'string', Rule::in(['all', PrimaryProvider::code()])],
            'status' => ['nullable', 'string', 'max:30'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $events = WebhookEvent::query()
            ->with('provider')
            ->whereHas('provider', fn ($query) => $query->where('code', PrimaryProvider::code()))
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
        abort_unless($providerWebhookEvent->provider !== null && PrimaryProvider::isPrimary($providerWebhookEvent->provider), 404);

        return response()->json($this->payload($providerWebhookEvent->load('provider')));
    }

    public function retry(
        Request $request,
        WebhookEvent $providerWebhookEvent,
        ProviderRegistry $registry,
    ): JsonResponse {
        abort_unless($providerWebhookEvent->provider !== null && PrimaryProvider::isPrimary($providerWebhookEvent->provider), 404);

        if ($providerWebhookEvent->processing_status === 'processed') {
            return response()->json([
                'message' => 'This webhook event is already processed.',
            ], 422);
        }

        $event = $providerWebhookEvent;
        try {
            $event = DB::transaction(function () use ($request, $providerWebhookEvent): WebhookEvent {
                $providerWebhookEvent = WebhookEvent::query()->lockForUpdate()->findOrFail($providerWebhookEvent->id);
                if ($providerWebhookEvent->processing_status === 'processed') {
                    throw new \RuntimeException('This webhook event is already processed.');
                }
                $oldData = $this->sensitiveDataSanitizer->sanitize($providerWebhookEvent->toArray());
                $retry = [
                    'requested_at' => now()->toISOString(),
                    'requested_by_user_id' => $request->user()?->id,
                ];
                if ($providerWebhookEvent->nium_va_evidence !== null) {
                    $metadata = $providerWebhookEvent->nium_va_recovery ?? [];
                    $providerWebhookEvent->update(['nium_va_recovery' => array_replace($metadata, [
                        'admin_retries' => ($metadata['admin_retries'] ?? 0) + 1,
                        'last_admin_retry' => $retry,
                    ])]);
                } else {
                    $payload = (array) ($providerWebhookEvent->payload ?? []);
                    $retry['count'] = (int) Arr::get($payload, '_origin_retry.count', 0) + 1;
                    $payload['_origin_retry'] = $retry;
                    $providerWebhookEvent->update([
                        'processing_status' => 'retrying', 'error_message' => null, 'payload' => $payload,
                    ]);
                }

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

            if ($event->provider !== null && $event->provider->supportsWebhooks()) {
                $webhookProvider = $registry->resolveWebhookProvider($event->provider);

                if ($webhookProvider instanceof ReprocessesWebhookEvent) {
                    $event = $webhookProvider->reprocessWebhookEvent($event->provider, $event);
                }
            }
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'Webhook retry failed.',
                'error' => $event->nium_va_evidence !== null
                    ? 'Nium VA webhook recovery failed; original evidence retained.' : $exception->getMessage(),
                'webhook_event' => $this->payload($event->fresh('provider')),
            ], 422);
        }

        if ($event->nium_va_evidence !== null && $event->processing_status === 'processing') {
            return response()->json([
                'message' => 'Webhook recovery is already in progress.',
                'webhook_event' => $this->payload($event),
            ], 422);
        }

        return response()->json([
            'message' => $event->processing_status === 'observed'
                ? 'Webhook retry evaluated; assignment evidence remains unresolved.'
                : 'Webhook retry has been requested.',
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
            'attempts' => $event->nium_va_evidence !== null
                ? (int) ($event->nium_va_recovery['admin_retries'] ?? 0) : (int) ($retry['count'] ?? 0),
            'received_at' => $event->received_at,
            'processed_at' => $event->processed_at,
            'next_retry_at' => null,
            'error_message' => $event->error_message,
            'payload' => strtolower((string) $event->provider?->code) === 'nium'
                ? NiumOperationalData::project($event->payload)
                : $this->sensitiveDataSanitizer->sanitize($event->payload),
        ];
    }
}
