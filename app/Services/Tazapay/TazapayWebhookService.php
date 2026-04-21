<?php

namespace App\Services\Tazapay;

use App\Models\IntegrationProvider;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\WebhookEvent;
use App\Services\Integrations\Contracts\WebhookProvider;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TazapayWebhookService implements WebhookProvider
{
    public function __construct(
        private readonly SensitiveDataSanitizer $sensitiveDataSanitizer,
    ) {
    }

    public function handleWebhook(IntegrationProvider $provider, Request $request): array
    {
        $this->verifyWebhookIfConfigured($request);

        $payload = $request->json()->all();
        $eventId = $payload['id'] ?? $payload['event_id'] ?? Arr::get($payload, 'data.id');

        if ($eventId !== null) {
            $existingEvent = WebhookEvent::query()
                ->where('provider_id', $provider->id)
                ->where('event_id', $eventId)
                ->first();

            if ($existingEvent !== null) {
                return [
                    'message' => 'Webhook already processed.',
                    'provider' => $provider->code,
                    'event_id' => $eventId,
                    'duplicate' => true,
                ];
            }
        }

        try {
            DB::transaction(function () use ($provider, $payload, $eventId, $request): void {
                $resource = $this->resourcePayload($payload);

                WebhookEvent::query()->create([
                    'provider_id' => $provider->id,
                    'event_id' => $eventId,
                    'event_type' => $payload['type'] ?? $payload['event_type'] ?? 'unknown',
                    'external_resource_id' => $resource['id'] ?? null,
                    'payload' => $this->sensitiveDataSanitizer->sanitize($payload),
                    'signature' => $this->receivedSignature($request) !== '' ? '[REDACTED]' : null,
                    'processing_status' => 'processed',
                    'processed_at' => now(),
                ]);

                $this->syncTransfer($provider, $payload, $resource);
                $this->syncTransaction($provider, $payload, $resource);
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
        $secret = (string) config('services.tazapay.webhook_secret', '');
        $headerName = (string) config('services.tazapay.webhook_signature_header', '');

        if ($secret === '' || $headerName === '') {
            return;
        }

        $receivedSignature = (string) $request->header($headerName);

        if ($receivedSignature === '' || ! hash_equals($secret, $receivedSignature)) {
            throw new AccessDeniedHttpException('Invalid Tazapay webhook signature.');
        }
    }

    private function resourcePayload(array $payload): array
    {
        return (array) (Arr::get($payload, 'data') ?? []);
    }

    private function syncTransfer(IntegrationProvider $provider, array $payload, array $resource): void
    {
        $externalTransferId = $resource['id']
            ?? $resource['payout_id']
            ?? Arr::get($payload, 'data.id');

        if (! filled($externalTransferId)) {
            return;
        }

        $transfer = Transfer::query()
            ->where('provider_id', $provider->id)
            ->where(function ($query) use ($externalTransferId): void {
                $query->where('external_transfer_id', $externalTransferId)
                    ->orWhere('external_payment_id', $externalTransferId);
            })
            ->first();

        if ($transfer === null) {
            return;
        }

        $status = $this->normalizeTransferStatus($resource['status'] ?? null, $payload['type'] ?? null);

        $transfer->update([
            'external_payment_id' => Arr::get($resource, 'tracking_details.tracking_number', $transfer->external_payment_id),
            'status' => $status,
            'failure_code' => $status === 'failed'
                ? (string) Arr::get($resource, 'failure.code', $transfer->failure_code ?? 'provider_error')
                : null,
            'failure_reason' => $status === 'failed'
                ? (string) ($resource['status_description'] ?? Arr::get($resource, 'failure.message', $transfer->failure_reason))
                : null,
            'completed_at' => in_array($status, ['completed', 'failed', 'cancelled'], true)
                ? ($resource['updated_at'] ?? $resource['created_at'] ?? now())
                : $transfer->completed_at,
            'raw_data' => array_merge($transfer->raw_data ?? [], [
                'last_webhook_payload' => $payload,
            ]),
        ]);
    }

    private function syncTransaction(IntegrationProvider $provider, array $payload, array $resource): void
    {
        $externalTransactionId = $resource['balance_transaction'] ?? null;

        if (! filled($externalTransactionId)) {
            return;
        }

        $transaction = Transaction::query()
            ->where('provider_id', $provider->id)
            ->where('external_transaction_id', $externalTransactionId)
            ->first();

        if ($transaction === null) {
            return;
        }

        $transaction->update([
            'status' => $this->normalizeTransactionStatus($resource['status'] ?? null),
            'description' => $resource['transaction_description'] ?? $transaction->description,
            'reference_text' => $resource['reference_id'] ?? $transaction->reference_text,
            'raw_data' => array_merge($transaction->raw_data ?? [], [
                'last_webhook_payload' => $payload,
            ]),
        ]);
    }

    private function normalizeTransferStatus(?string $status, ?string $eventType): string
    {
        $normalizedEvent = strtolower((string) $eventType);

        if (str_contains($normalizedEvent, 'reversed')) {
            return 'failed';
        }

        if (str_contains($normalizedEvent, 'cancel')) {
            return 'cancelled';
        }

        if (str_contains($normalizedEvent, 'fail') || str_contains($normalizedEvent, 'reject')) {
            return 'failed';
        }

        return match (strtolower((string) $status)) {
            'succeeded', 'success', 'completed', 'paid' => 'completed',
            'reversed', 'returned', 'failed', 'rejected', 'error' => 'failed',
            'cancelled', 'canceled', 'voided' => 'cancelled',
            'pending', 'processing', 'initiated', 'queued' => 'pending',
            default => 'submitted',
        };
    }

    private function normalizeTransactionStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'succeeded', 'success', 'completed', 'paid' => 'completed',
            'failed', 'reversed', 'returned', 'error' => 'failed',
            'cancelled', 'canceled', 'voided' => 'cancelled',
            'pending', 'processing', 'initiated', 'queued' => 'pending',
            default => 'submitted',
        };
    }

    private function isDuplicateWebhookEventException(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $constraint = $exception->errorInfo[2] ?? '';

        return $sqlState === '23505'
            && str_contains($constraint, 'webhook_events_provider_id_event_id_unique');
    }

    private function receivedSignature(Request $request): string
    {
        $headerName = (string) config('services.tazapay.webhook_signature_header', '');

        return $headerName !== '' ? (string) $request->header($headerName) : '';
    }
}
