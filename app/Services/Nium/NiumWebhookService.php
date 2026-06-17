<?php

namespace App\Services\Nium;

use App\Models\IntegrationProvider;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\WebhookEvent;
use App\Services\Integrations\Contracts\ReprocessesWebhookEvent;
use App\Services\Integrations\Contracts\WebhookProvider;
use App\Services\Integrations\Support\HmacWebhookSignatureVerifier;
use App\Services\Wallet\LedgerService;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

class NiumWebhookService implements ReprocessesWebhookEvent, WebhookProvider
{
    public function __construct(
        private readonly SensitiveDataSanitizer $sensitiveDataSanitizer,
        private readonly HmacWebhookSignatureVerifier $signatureVerifier,
        private readonly LedgerService $ledgerService,
    ) {}

    public function handleWebhook(IntegrationProvider $provider, Request $request): array
    {
        $this->verifyWebhookIfConfigured($request);

        $payload = $request->json()->all();
        $eventId = $this->eventId($payload, $request);
        $eventType = $this->eventType($payload);
        $resource = $this->resourcePayload($payload);

        $existingEvent = WebhookEvent::query()
            ->where('provider_id', $provider->id)
            ->where('event_id', $eventId)
            ->first();

        if ($existingEvent !== null) {
            return [
                'message' => 'Webhook already received.',
                'provider' => $provider->code,
                'event_id' => $eventId,
                'duplicate' => true,
            ];
        }

        $event = null;

        try {
            DB::transaction(function () use (&$event, $eventId, $eventType, $payload, $provider, $request, $resource): void {
                $event = WebhookEvent::query()->create([
                    'provider_id' => $provider->id,
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'external_resource_id' => $this->externalResourceId($payload, $resource),
                    'payload' => $this->sensitiveDataSanitizer->sanitize($payload),
                    'signature' => $this->receivedSignature($request) !== '' ? '[REDACTED]' : null,
                    'processing_status' => 'received',
                ]);

                $this->processPayload($provider, $payload, $resource);

                $event->update([
                    'processing_status' => 'processed',
                    'processed_at' => now(),
                    'error_message' => null,
                ]);
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicateWebhookEventException($exception)) {
                return [
                    'message' => 'Webhook already received.',
                    'provider' => $provider->code,
                    'event_id' => $eventId,
                    'duplicate' => true,
                ];
            }

            throw $exception;
        } catch (Throwable $exception) {
            $event?->update([
                'processing_status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);

            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }

        return [
            'message' => 'Webhook received.',
            'provider' => $provider->code,
            'event_id' => $eventId,
        ];
    }

    public function reprocessWebhookEvent(IntegrationProvider $provider, WebhookEvent $event): WebhookEvent
    {
        return DB::transaction(function () use ($event, $provider): WebhookEvent {
            $event = WebhookEvent::query()
                ->whereKey($event->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($event->processing_status === 'processed') {
                return $event->fresh('provider');
            }

            $payload = (array) ($event->payload ?? []);

            try {
                $event->update([
                    'processing_status' => 'retrying',
                    'error_message' => null,
                ]);

                $this->processPayload($provider, $payload, $this->resourcePayload($payload));

                $event->update([
                    'processing_status' => 'processed',
                    'processed_at' => now(),
                    'error_message' => null,
                ]);
            } catch (Throwable $exception) {
                $event->update([
                    'processing_status' => 'failed',
                    'error_message' => $exception->getMessage(),
                ]);

                throw new RuntimeException($exception->getMessage(), previous: $exception);
            }

            return $event->fresh('provider');
        });
    }

    private function processPayload(IntegrationProvider $provider, array $payload, array $resource): void
    {
        $transfer = $this->findTransfer($provider, $payload, $resource);

        if ($transfer !== null) {
            $status = $this->normalizeTransferStatus(
                $this->value($resource, ['status', 'subStatus', 'paymentStatus'])
                    ?? $this->value($payload, ['status', 'eventStatus']),
                $this->eventType($payload),
            );

            $transfer->update([
                'external_transfer_id' => $this->value($resource, [
                    'systemReferenceNumber',
                    'system_reference_number',
                    'remittanceId',
                    'remittance_id',
                    'id',
                ]) ?? $transfer->external_transfer_id,
                'external_payment_id' => $this->value($resource, [
                    'paymentId',
                    'payment_id',
                    'paymentReferenceNumber',
                    'payment_reference_number',
                ]) ?? $transfer->external_payment_id,
                'status' => $status,
                'failure_code' => $status === 'failed'
                    ? (string) ($this->value($resource, ['code', 'failureCode', 'errorCode']) ?? 'provider_error')
                    : null,
                'failure_reason' => $status === 'failed'
                    ? (string) ($this->value($resource, ['message', 'remarks', 'failureReason', 'errorMessage']) ?? 'Nium transfer failed.')
                    : null,
                'completed_at' => in_array($status, ['completed', 'failed', 'cancelled'], true)
                    ? ($this->value($resource, ['dateTime', 'updatedAt', 'completedAt']) ?? now())
                    : $transfer->completed_at,
                'raw_data' => array_merge($transfer->raw_data ?? [], [
                    'last_webhook_payload' => $this->sensitiveDataSanitizer->sanitize($payload),
                ]),
            ]);

            $transfer = $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
            $this->ledgerService->applyTransferTerminalStatus($transfer);
            $this->syncTransaction($provider, $transfer, $payload, $resource);
        }
    }

    private function syncTransaction(IntegrationProvider $provider, Transfer $transfer, array $payload, array $resource): void
    {
        $externalTransactionId = $this->value($resource, [
            'transactionId',
            'transaction_id',
            'paymentReferenceNumber',
            'payment_reference_number',
            'paymentId',
            'payment_id',
        ]);

        if (! filled($externalTransactionId)) {
            return;
        }

        Transaction::query()->updateOrCreate(
            [
                'provider_id' => $provider->id,
                'external_transaction_id' => (string) $externalTransactionId,
            ],
            [
                'user_id' => $transfer->user_id,
                'bank_account_id' => $transfer->source_bank_account_id,
                'transfer_id' => $transfer->id,
                'transaction_type' => $this->eventType($payload),
                'direction' => 'debit',
                'currency' => $transfer->source_currency,
                'amount' => $transfer->source_amount,
                'fee_amount' => $transfer->fee_amount ?? 0,
                'description' => $this->value($resource, ['remarks', 'message', 'description']),
                'reference_text' => $transfer->client_reference ?: $transfer->reference_text,
                'status' => $this->normalizeTransactionStatus($transfer->status),
                'booked_at' => $this->value($resource, ['dateTime', 'updatedAt', 'completedAt']) ?? now(),
                'value_date' => $this->value($resource, ['valueDate', 'date']) ?? now(),
                'raw_data' => $this->sensitiveDataSanitizer->sanitize($resource),
            ],
        );
    }

    private function verifyWebhookIfConfigured(Request $request): void
    {
        $secret = (string) config('services.nium.webhook_secret', '');
        $headerName = (string) config('services.nium.webhook_signature_header', '');

        if ($secret === '' || $headerName === '') {
            return;
        }

        $receivedSignature = (string) $request->header($headerName);
        $algorithm = (string) config('services.nium.webhook_signature_algorithm', 'sha256');

        if (! $this->signatureVerifier->isValid($request->getContent(), $secret, $receivedSignature, $algorithm)) {
            throw new AccessDeniedHttpException('Invalid Nium webhook signature.');
        }
    }

    private function findTransfer(IntegrationProvider $provider, array $payload, array $resource): ?Transfer
    {
        $references = array_filter([
            $this->value($resource, ['systemReferenceNumber', 'system_reference_number', 'remittanceId', 'remittance_id', 'id']),
            $this->value($resource, ['paymentId', 'payment_id', 'paymentReferenceNumber', 'payment_reference_number']),
            $this->value($resource, ['clientReference', 'client_reference', 'customerComments']),
            $this->value($payload, ['clientReference', 'client_reference']),
        ], static fn ($value) => filled($value));

        if ($references === []) {
            return null;
        }

        return Transfer::query()
            ->where('provider_id', $provider->id)
            ->where(function ($query) use ($references): void {
                foreach ($references as $reference) {
                    $query->orWhere('external_transfer_id', $reference)
                        ->orWhere('external_payment_id', $reference)
                        ->orWhere('transfer_no', $reference)
                        ->orWhere('client_reference', $reference);
                }
            })
            ->first();
    }

    private function eventId(array $payload, Request $request): string
    {
        $explicitId = $this->value($payload, [
            'id',
            'eventId',
            'event_id',
            'webhookEventId',
            'webhook_event_id',
        ]);

        if (filled($explicitId)) {
            return (string) $explicitId;
        }

        $requestId = $request->header('X-Request-Id') ?: $request->header('X-Nium-Request-Id');

        if (filled($requestId)) {
            return (string) $requestId;
        }

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: serialize($payload));
    }

    private function eventType(array $payload): string
    {
        return (string) ($this->value($payload, ['eventType', 'event_type', 'type', 'name']) ?? 'nium.webhook');
    }

    private function resourcePayload(array $payload): array
    {
        return (array) (Arr::get($payload, 'data.resource')
            ?? Arr::get($payload, 'resource')
            ?? Arr::get($payload, 'data')
            ?? Arr::get($payload, 'payload')
            ?? $payload);
    }

    private function externalResourceId(array $payload, array $resource): ?string
    {
        $value = $this->value($resource, [
            'systemReferenceNumber',
            'system_reference_number',
            'paymentId',
            'payment_id',
            'id',
        ]) ?? $this->value($payload, ['resourceId', 'resource_id']);

        return filled($value) ? (string) $value : null;
    }

    private function value(array $item, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = Arr::get($item, $path);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function normalizeTransferStatus(mixed $status, string $eventType): string
    {
        $normalizedEvent = strtolower($eventType);

        if (str_contains($normalizedEvent, 'cancel')) {
            return 'cancelled';
        }

        if (str_contains($normalizedEvent, 'fail') || str_contains($normalizedEvent, 'reject') || str_contains($normalizedEvent, 'return')) {
            return 'failed';
        }

        if (str_contains($normalizedEvent, 'paid') || str_contains($normalizedEvent, 'complete') || str_contains($normalizedEvent, 'success')) {
            return 'completed';
        }

        return match (strtoupper((string) $status)) {
            'PAID', 'SUCCESS', 'SUCCEEDED', 'COMPLETED' => 'completed',
            'FAILED', 'ERROR', 'REJECTED', 'RETURNED' => 'failed',
            'CANCELLED', 'CANCELED', 'VOIDED' => 'cancelled',
            'PENDING', 'PROCESSING', 'IN_PROGRESS', 'ACCEPTED' => 'pending',
            default => 'submitted',
        };
    }

    private function normalizeTransactionStatus(string $transferStatus): string
    {
        return match ($transferStatus) {
            'completed' => 'completed',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            default => 'pending',
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
        $headerName = (string) config('services.nium.webhook_signature_header', '');

        return $headerName !== '' ? (string) $request->header($headerName) : '';
    }
}
