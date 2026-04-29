<?php

namespace App\Services\Unlimit;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\WebhookEvent;
use App\Services\Integrations\Contracts\WebhookProvider;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class UnlimitWebhookService implements WebhookProvider
{
    public function __construct(
        private readonly SensitiveDataSanitizer $sensitiveDataSanitizer,
    ) {
    }

    public function handleWebhook(IntegrationProvider $provider, Request $request): array
    {
        $this->verifyWebhookIfConfigured($request);

        $payload = $request->json()->all();
        $payoutId = Arr::get($payload, 'payout_data.id');
        $status = Arr::get($payload, 'payout_data.status');
        $eventId = $this->eventId($payload);

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
            DB::transaction(function () use ($provider, $payload, $eventId, $payoutId, $status, $request): void {
                WebhookEvent::query()->create([
                    'provider_id' => $provider->id,
                    'event_id' => $eventId,
                    'event_type' => 'payout.'.strtolower((string) ($status ?: 'processed')),
                    'external_resource_id' => $payoutId,
                    'payload' => $this->sensitiveDataSanitizer->sanitize($payload),
                    'signature' => $this->receivedSignature($request) !== '' ? '[REDACTED]' : null,
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
        $secret = (string) config('services.unlimit.webhook_secret', '');
        $receivedSignature = $this->receivedSignature($request);

        if ($secret === '' || $receivedSignature === '') {
            return;
        }

        $expectedSignature = hash('sha512', $request->getContent().$secret);

        if (! hash_equals($expectedSignature, $receivedSignature)) {
            throw new AccessDeniedHttpException('Invalid Unlimit webhook signature.');
        }
    }

    private function syncTransfer(IntegrationProvider $provider, array $payload): void
    {
        $payoutData = (array) Arr::get($payload, 'payout_data', []);
        $payoutId = $payoutData['id'] ?? null;
        $merchantOrderId = Arr::get($payload, 'merchant_order.id');

        if (! filled($payoutId) && ! filled($merchantOrderId)) {
            return;
        }

        $transfer = Transfer::query()
            ->where('provider_id', $provider->id)
            ->where(function ($query) use ($payoutId, $merchantOrderId): void {
                if (filled($payoutId)) {
                    $query->where('external_transfer_id', $payoutId)
                        ->orWhere('external_payment_id', $payoutId);
                }

                if (filled($merchantOrderId)) {
                    $query->orWhere('transfer_no', $merchantOrderId)
                        ->orWhere('client_reference', $merchantOrderId);
                }
            })
            ->first();

        if ($transfer === null) {
            return;
        }

        $status = $this->normalizeTransferStatus($payoutData['status'] ?? null);

        $transfer->update([
            'external_transfer_id' => $payoutId ?? $transfer->external_transfer_id,
            'external_payment_id' => Arr::get($payload, 'payment_data.id')
                ?? $payoutData['rrn']
                ?? $payoutData['arn']
                ?? $transfer->external_payment_id,
            'target_amount' => $payoutData['amount'] ?? $transfer->target_amount,
            'status' => $status,
            'failure_code' => $status === 'failed'
                ? (string) ($payoutData['decline_code'] ?? $transfer->failure_code ?? 'provider_error')
                : null,
            'failure_reason' => $status === 'failed'
                ? ($payoutData['extended_decline_reason'] ?? $payoutData['decline_reason'] ?? $transfer->failure_reason)
                : null,
            'completed_at' => in_array($status, ['completed', 'failed', 'cancelled'], true)
                ? ($payoutData['created'] ?? Arr::get($payload, 'callback_time') ?? now())
                : $transfer->completed_at,
            'raw_data' => array_merge($transfer->raw_data ?? [], [
                'last_webhook_payload' => $payload,
            ]),
        ]);
    }

    private function eventId(array $payload): ?string
    {
        $payoutId = Arr::get($payload, 'payout_data.id');

        if (! filled($payoutId)) {
            return null;
        }

        return implode(':', array_filter([
            $payoutId,
            Arr::get($payload, 'payout_data.status'),
            Arr::get($payload, 'callback_time'),
        ], static fn ($value) => filled($value)));
    }

    private function normalizeTransferStatus(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'COMPLETED' => 'completed',
            'DECLINED', 'TERMINATED', 'CHARGED_BACK', 'CHARGEBACK_RESOLVED', 'REFUNDED' => 'failed',
            'CANCELLED', 'VOIDED' => 'cancelled',
            'NEW', 'IN_PROGRESS', 'AUTHORIZED' => 'pending',
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
        $headerName = (string) config('services.unlimit.webhook_signature_header', 'Signature');

        return $headerName !== '' ? (string) $request->header($headerName) : '';
    }
}
