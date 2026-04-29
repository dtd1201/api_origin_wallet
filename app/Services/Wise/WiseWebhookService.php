<?php

namespace App\Services\Wise;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\WebhookEvent;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Database\QueryException;
use App\Services\Integrations\Contracts\WebhookProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class WiseWebhookService implements WebhookProvider
{
    use WiseDataFormatter;

    public function __construct(
        private readonly SensitiveDataSanitizer $sensitiveDataSanitizer,
    ) {
    }

    public function handleWebhook(IntegrationProvider $provider, Request $request): array
    {
        $this->verifyWebhookIfConfigured($request);

        $payload = $request->json()->all();
        $eventId = (string) ($request->header('X-Delivery-Id') ?: $payload['subscription_id'] ?? '');
        $eventType = (string) ($payload['event_type'] ?? 'unknown');
        $externalResourceId = (string) ($payload['data']['resource']['id'] ?? '');

        try {
            DB::transaction(function () use ($provider, $request, $payload, $eventId, $eventType, $externalResourceId): void {
                WebhookEvent::query()->create([
                    'provider_id' => $provider->id,
                    'event_id' => $eventId !== '' ? $eventId : null,
                    'event_type' => $eventType,
                    'external_resource_id' => $externalResourceId !== '' ? $externalResourceId : null,
                    'payload' => $this->sensitiveDataSanitizer->sanitize($payload),
                    'signature' => $request->header((string) config('services.wise.webhook_signature_header')) ? '[REDACTED]' : null,
                    'processing_status' => 'processed',
                    'processed_at' => now(),
                ]);

                $this->syncTransfer($provider, $payload);
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicateWebhookEventException($exception)) {
                return [
                    'message' => 'Webhook already processed.',
                    'provider' => $provider->code,
                    'event_id' => $eventId,
                    'duplicate' => true,
                ];
            }

            throw $exception;
        }

        return [
            'message' => 'Webhook received.',
            'provider' => $provider->code,
            'event_id' => $eventId,
        ];
    }

    private function verifyWebhookIfConfigured(Request $request): void
    {
        $publicKey = (string) config('services.wise.webhook_public_key', '');
        $headerName = (string) config('services.wise.webhook_signature_header', 'X-Signature-SHA256');

        if ($publicKey === '') {
            return;
        }

        $signature = (string) $request->header($headerName);

        if ($signature === '') {
            throw new AccessDeniedHttpException('Missing Wise webhook signature.');
        }

        $verified = openssl_verify(
            $request->getContent(),
            base64_decode($signature, true) ?: '',
            $publicKey,
            OPENSSL_ALGO_SHA256,
        );

        if ($verified !== 1) {
            throw new AccessDeniedHttpException('Invalid Wise webhook signature.');
        }
    }

    private function syncTransfer(IntegrationProvider $provider, array $payload): void
    {
        $externalTransferId = $payload['data']['resource']['id'] ?? null;

        if (! filled($externalTransferId)) {
            return;
        }

        $transfer = Transfer::query()
            ->where('provider_id', $provider->id)
            ->where('external_transfer_id', (string) $externalTransferId)
            ->first();

        if ($transfer === null) {
            return;
        }

        $status = $this->normalizeWebhookTransferStatus($payload['data']['current_state'] ?? null);

        $transfer->update([
            'status' => $status,
            'failure_code' => $status === 'failed' ? 'webhook_state_failure' : null,
            'failure_reason' => $status === 'failed'
                ? (string) ($payload['data']['current_state'] ?? 'Wise transfer failed.')
                : null,
            'completed_at' => in_array($status, ['completed', 'failed', 'cancelled'], true)
                ? ($payload['data']['occurred_at'] ?? now())
                : $transfer->completed_at,
            'raw_data' => array_merge($transfer->raw_data ?? [], [
                'last_webhook_payload' => $payload,
            ]),
        ]);
    }

    private function isDuplicateWebhookEventException(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $constraint = $exception->errorInfo[2] ?? '';

        return $sqlState === '23505'
            && str_contains($constraint, 'webhook_events_provider_id_event_id_unique');
    }
}
