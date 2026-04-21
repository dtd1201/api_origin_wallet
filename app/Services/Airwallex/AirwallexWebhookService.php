<?php

namespace App\Services\Airwallex;

use App\Models\IntegrationProvider;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Integrations\Contracts\WebhookProvider;
use App\Services\Integrations\ProviderAccountStatusManager;
use App\Services\Integrations\Support\HmacWebhookSignatureVerifier;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AirwallexWebhookService implements WebhookProvider
{
    public function __construct(
        private readonly HmacWebhookSignatureVerifier $signatureVerifier,
        private readonly SensitiveDataSanitizer $sensitiveDataSanitizer,
    ) {
    }

    public function handleWebhook(IntegrationProvider $provider, Request $request): array
    {
        $this->verifySignature($request);

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
            DB::transaction(function () use ($provider, $payload, $eventId): void {
                $resource = $this->resourcePayload($payload);

                $event = WebhookEvent::query()->create([
                    'provider_id' => $provider->id,
                    'event_id' => $eventId,
                    'event_type' => $payload['name'] ?? $payload['event_type'] ?? $payload['type'] ?? 'unknown',
                    'external_resource_id' => $resource['id'] ?? $resource['account_id'] ?? $resource['transfer_id'] ?? null,
                    'payload' => $this->sensitiveDataSanitizer->sanitize($payload),
                    'signature' => '[REDACTED]',
                    'processing_status' => 'processed',
                    'processed_at' => now(),
                ]);

                $this->syncProviderAccount($provider, $resource, $event->id);
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

    private function verifySignature(Request $request): void
    {
        $signatureHeader = (string) config('services.airwallex.webhook_signature_header', 'x-signature');
        $timestampHeader = (string) config('services.airwallex.webhook_timestamp_header', 'x-timestamp');
        $algorithm = (string) config('services.airwallex.webhook_signature_algorithm', 'sha256');
        $configuredSecret = (string) config('services.airwallex.webhook_secret', '');
        $testSecret = (string) $request->header('client-secret-key', '');
        $secret = $configuredSecret !== '' ? $configuredSecret : $testSecret;
        $timestamp = (string) $request->header($timestampHeader);
        $signature = (string) $request->header($signatureHeader);
        $rawPayload = $request->getContent();

        if ($secret === '') {
            throw new RuntimeException('Airwallex webhook secret is not configured.');
        }

        if ($timestamp === '' || $signature === '') {
            throw new AccessDeniedHttpException('Missing Airwallex webhook signature headers.');
        }

        $signedPayload = $timestamp.$rawPayload;

        if (! $this->signatureVerifier->isValid($signedPayload, $secret, $signature, $algorithm)) {
            throw new AccessDeniedHttpException('Invalid webhook signature.');
        }
    }

    private function resourcePayload(array $payload): array
    {
        return (array) (
            Arr::get($payload, 'data.object')
            ?? Arr::get($payload, 'data.resource')
            ?? Arr::get($payload, 'data')
            ?? []
        );
    }

    private function syncProviderAccount(IntegrationProvider $provider, array $resource, int $webhookEventId): void
    {
        $externalAccountId = $resource['account_id'] ?? $resource['id'] ?? null;

        if (! filled($externalAccountId) || ! str_starts_with((string) $externalAccountId, 'acct_')) {
            return;
        }

        $providerAccount = UserProviderAccount::query()
            ->where('provider_id', $provider->id)
            ->where('external_account_id', $externalAccountId)
            ->first();

        if ($providerAccount === null) {
            return;
        }

        $providerAccount->update([
            'status' => $this->normalizeAccountStatus($resource['status'] ?? null),
            'metadata' => array_merge($providerAccount->metadata ?? [], [
                'last_webhook_event_id' => $webhookEventId,
                'last_webhook_payload' => $resource,
            ]),
        ]);

        app(ProviderAccountStatusManager::class)
            ->syncUserStatusFromProviderAccount($providerAccount->fresh('user'));
    }

    private function syncTransfer(IntegrationProvider $provider, array $payload, array $resource): void
    {
        $externalTransferId = $resource['id']
            ?? $resource['transfer_id']
            ?? Arr::get($payload, 'data.transfer_id')
            ?? null;

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

        $status = $this->normalizeTransferStatus($resource['status'] ?? null, $payload['name'] ?? null);

        $transfer->update([
            'status' => $status,
            'failure_code' => $status === 'failed' ? (string) ($resource['failure_code'] ?? 'provider_error') : null,
            'failure_reason' => $status === 'failed'
                ? ($resource['failure_reason'] ?? $resource['message'] ?? $transfer->failure_reason)
                : null,
            'completed_at' => in_array($status, ['completed', 'failed', 'cancelled'], true)
                ? ($resource['dispatch_date'] ?? $resource['completed_at'] ?? now())
                : $transfer->completed_at,
            'raw_data' => array_merge($transfer->raw_data ?? [], [
                'last_webhook_payload' => $payload,
            ]),
        ]);
    }

    private function syncTransaction(IntegrationProvider $provider, array $payload, array $resource): void
    {
        $externalTransactionId = $resource['transaction_id']
            ?? $resource['payment_event_id']
            ?? null;

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
            'status' => $this->normalizeAccountStatus($resource['status'] ?? null),
            'description' => $resource['description'] ?? $transaction->description,
            'reference_text' => $resource['reference'] ?? $transaction->reference_text,
            'raw_data' => array_merge($transaction->raw_data ?? [], [
                'last_webhook_payload' => $payload,
            ]),
        ]);
    }

    private function normalizeTransferStatus(?string $status, ?string $eventType): string
    {
        $normalizedEvent = strtoupper((string) $eventType);

        if (str_contains($normalizedEvent, 'CANCEL')) {
            return 'cancelled';
        }

        if (str_contains($normalizedEvent, 'FAIL') || str_contains($normalizedEvent, 'REJECT')) {
            return 'failed';
        }

        return match (strtoupper((string) $status)) {
            'PAID', 'SENT', 'SUCCEEDED', 'SUCCESS', 'COMPLETED' => 'completed',
            'FAILED', 'FAIL', 'REJECTED', 'RETURNED' => 'failed',
            'CANCELLED', 'VOIDED' => 'cancelled',
            'IN_APPROVAL', 'AWAITING_FUNDING', 'PROCESSING', 'PENDING', 'SCHEDULED' => 'pending',
            default => 'submitted',
        };
    }

    private function normalizeAccountStatus(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'ACTIVE', 'ENABLED', 'APPROVED', 'COMPLETED' => 'active',
            'FAILED', 'REJECTED', 'ERROR' => 'failed',
            'UNDER_REVIEW', 'IN_REVIEW', 'PENDING', 'PROCESSING', 'SUBMITTED' => 'under_review',
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
}
