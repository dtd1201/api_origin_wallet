<?php

namespace App\Services\Currenxie;

use App\Models\IntegrationProvider;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Integrations\Contracts\WebhookProvider;
use App\Services\Integrations\ProviderAccountStatusManager;
use App\Services\Integrations\Support\HmacWebhookSignatureVerifier;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CurrenxieWebhookService implements WebhookProvider
{
    public function __construct(
        private readonly HmacWebhookSignatureVerifier $signatureVerifier,
        private readonly SensitiveDataSanitizer $sensitiveDataSanitizer,
    ) {
    }

    public function handleWebhook(IntegrationProvider $provider, Request $request): array
    {
        $signatureHeader = (string) config('services.currenxie.webhook_signature_header', 'X-Currenxie-Signature');
        $signatureAlgorithm = (string) config('services.currenxie.webhook_signature_algorithm', 'sha256');
        $webhookSecret = (string) config('services.currenxie.webhook_secret', '');
        $receivedSignature = (string) $request->header($signatureHeader);
        $rawPayload = $request->getContent();

        if ($webhookSecret === '') {
            throw new RuntimeException('Currenxie webhook secret is not configured.');
        }

        if (! $this->signatureVerifier->isValid($rawPayload, $webhookSecret, $receivedSignature, $signatureAlgorithm)) {
            throw new AccessDeniedHttpException('Invalid webhook signature.');
        }

        $payload = $request->json()->all();
        $eventId = $payload['event_id'] ?? $payload['id'] ?? null;

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
            DB::transaction(function () use ($provider, $payload, $receivedSignature, $eventId): void {
                $event = WebhookEvent::query()->create([
                    'provider_id' => $provider->id,
                    'event_id' => $eventId,
                    'event_type' => $payload['event_type'] ?? $payload['type'] ?? 'unknown',
                    'external_resource_id' => $payload['account_id'] ?? $payload['customer_id'] ?? null,
                    'payload' => $this->sensitiveDataSanitizer->sanitize($payload),
                    'signature' => '[REDACTED]',
                    'processing_status' => 'processed',
                    'processed_at' => now(),
                ]);

                $externalAccountId = $payload['account_id'] ?? null;

                if ($externalAccountId !== null) {
                    $providerAccount = UserProviderAccount::query()
                        ->where('provider_id', $provider->id)
                        ->where('external_account_id', $externalAccountId)
                        ->first();

                    if ($providerAccount !== null) {
                        $providerAccount->update([
                            'status' => $payload['status'] ?? 'active',
                            'metadata' => array_merge($providerAccount->metadata ?? [], [
                                'last_webhook_event_id' => $event->id,
                                'last_webhook_payload' => $payload,
                            ]),
                        ]);

                        app(ProviderAccountStatusManager::class)
                            ->syncUserStatusFromProviderAccount($providerAccount->fresh('user'));
                    }
                }

                $externalTransferId = $payload['transfer_id'] ?? $payload['payment_id'] ?? null;

                if ($externalTransferId !== null) {
                    $transfer = Transfer::query()
                        ->where('provider_id', $provider->id)
                        ->where(function ($query) use ($externalTransferId): void {
                            $query->where('external_transfer_id', $externalTransferId)
                                ->orWhere('external_payment_id', $externalTransferId);
                        })
                        ->first();

                    if ($transfer !== null) {
                        $transfer->update([
                            'status' => $payload['status'] ?? $transfer->status,
                            'failure_code' => $payload['failure_code'] ?? $transfer->failure_code,
                            'failure_reason' => $payload['failure_reason'] ?? $payload['message'] ?? $transfer->failure_reason,
                            'completed_at' => ($payload['status'] ?? null) === 'completed' ? now() : $transfer->completed_at,
                            'raw_data' => array_merge($transfer->raw_data ?? [], [
                                'last_webhook_payload' => $payload,
                            ]),
                        ]);
                    }
                }

                $externalTransactionId = $payload['transaction_id'] ?? null;

                if ($externalTransactionId !== null) {
                    $transaction = Transaction::query()
                        ->where('provider_id', $provider->id)
                        ->where('external_transaction_id', $externalTransactionId)
                        ->first();

                    if ($transaction !== null) {
                        $transaction->update([
                            'status' => $payload['status'] ?? $transaction->status,
                            'description' => $payload['description'] ?? $transaction->description,
                            'reference_text' => $payload['reference_text'] ?? $transaction->reference_text,
                            'raw_data' => array_merge($transaction->raw_data ?? [], [
                                'last_webhook_payload' => $payload,
                            ]),
                        ]);
                    }
                }
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

    private function isDuplicateWebhookEventException(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $constraint = $exception->errorInfo[2] ?? '';

        return $sqlState === '23505'
            && str_contains($constraint, 'webhook_events_provider_id_event_id_unique');
    }
}
