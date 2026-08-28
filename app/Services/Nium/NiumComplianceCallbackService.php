<?php

namespace App\Services\Nium;

use App\Models\IntegrationProvider;
use App\Models\NiumComplianceEvent;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\UserProviderAccount;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NiumComplianceCallbackService
{
    public function __construct(
        private readonly SensitiveDataSanitizer $sensitiveDataSanitizer,
        private readonly NiumTransactionRfiService $transactionRfiService,
    ) {}

    public function handle(Request $request): array
    {
        $payload = $request->all();
        $transactionNudge = $this->transactionNudge($request);
        $provider = IntegrationProvider::query()->firstOrCreate(
            ['code' => 'nium'],
            ['name' => 'Nium', 'status' => 'active'],
        );
        $requestId = $this->requestId($payload, $request);
        $references = $transactionNudge !== null ? [$transactionNudge] : $this->valuesForKeys($payload, [
            'systemReferenceNumber',
            'system_reference_number',
            'remittanceId',
            'remittance_id',
            'paymentId',
            'payment_id',
            'paymentReferenceNumber',
            'payment_reference_number',
            'transactionId',
            'transaction_id',
            'clientReference',
            'client_reference',
            'transferReference',
            'transfer_reference',
            'reference',
        ]);
        $customerReferences = $this->valuesForKeys($payload, [
            'customerHashId',
            'customer_hash_id',
            'customerId',
            'customer_id',
        ]);
        // The callback is only a nudge; query callbacks never supply authoritative compliance state.
        $status = $transactionNudge !== null ? null : $this->complianceStatus($payload);
        $eventPayload = $transactionNudge !== null
            ? ['type' => 'TRANSACTION', 'value' => $transactionNudge]
            : $payload;
        $immutableNudgeId = $transactionNudge !== null
            ? ($requestId ?? $this->immutableCallbackEventId($payload))
            : null;
        $eventId = $transactionNudge !== null
            ? ($immutableNudgeId ?? 'transaction-nudge:'.Str::uuid())
            : $this->eventId($eventPayload, $request, $requestId, $references, $status);
        $sanitizedPayload = (array) $this->sensitiveDataSanitizer->sanitize($eventPayload);

        $event = NiumComplianceEvent::query()->firstOrCreate(
            ['event_id' => $eventId],
            [
                'provider_id' => $provider->id,
                'request_id' => $requestId,
                'reference' => $references[0] ?? null,
                'customer_reference' => $customerReferences[0] ?? null,
                'event_type' => $transactionNudge !== null ? 'TRANSACTION' : $this->eventType($payload),
                'compliance_status' => $status,
                'payload' => $sanitizedPayload,
                'processing_status' => 'received',
            ],
        );

        if (! $event->wasRecentlyCreated) {
            $event->increment('duplicate_count');
            $event->update(['last_received_at' => now()]);

            Log::info('Duplicate Nium compliance callback ignored.', [
                'event_id' => $eventId,
            ]);

            return $this->responsePayload($event->fresh(), true);
        }

        try {
            $event = DB::transaction(function () use (
                $customerReferences,
                $event,
                $provider,
                $references,
                $status,
            ): NiumComplianceEvent {
                $transaction = $this->findTransaction($provider, $references);
                $transfer = $transaction?->transfer ?? $this->findTransfer($provider, $references);
                $providerAccount = $this->findProviderAccount($provider, $customerReferences);
                $userId = $transaction?->user_id ?? $transfer?->user_id ?? $providerAccount?->user_id;
                $requiresAction = $this->requiresAction($status, (array) $event->payload);
                $matchStatus = $transaction !== null
                    ? 'matched_transaction'
                    : ($transfer !== null
                        ? 'matched_transfer'
                        : ($providerAccount !== null ? 'matched_customer' : 'unmatched'));
                $reviewStatus = $requiresAction || in_array($matchStatus, ['unmatched', 'matched_customer'], true)
                    ? 'pending'
                    : 'not_required';

                if ($requiresAction && $transaction !== null) {
                    $transaction->update([
                        'compliance_review_required' => true,
                        'compliance_status' => $status ?: 'ACTION_REQUIRED',
                        'compliance_reviewed_at' => null,
                    ]);
                }

                if ($requiresAction && $transfer !== null) {
                    $transfer->update([
                        'compliance_review_required' => true,
                        'compliance_status' => $status ?: 'ACTION_REQUIRED',
                        'compliance_reviewed_at' => null,
                    ]);
                }

                $event->update([
                    'user_id' => $userId,
                    'transfer_id' => $transfer?->id,
                    'transaction_id' => $transaction?->id,
                    'match_status' => $matchStatus,
                    'review_status' => $reviewStatus,
                    'requires_action' => $requiresAction,
                    'processing_status' => 'processed',
                    'processed_at' => now(),
                    'error_message' => null,
                ]);

                return $event->fresh();
            });

            if ($transactionNudge !== null) {
                $transaction = $this->transactionRfiService->fetchAndReconcile($provider, $transactionNudge);
                $authoritativeStatus = strtoupper((string) $transaction->compliance_status);
                $requiresAction = in_array($authoritativeStatus, ['RFI_REQUESTED', 'REJECT'], true);
                $event->update([
                    'user_id' => $transaction->user_id,
                    'transfer_id' => $transaction->transfer_id,
                    'transaction_id' => $transaction->id,
                    'match_status' => 'matched_transaction',
                    'compliance_status' => $authoritativeStatus,
                    'requires_action' => $requiresAction,
                    'review_status' => $requiresAction ? 'pending' : 'not_required',
                    'processing_status' => 'processed',
                    'processed_at' => now(),
                ]);
                $event = $event->fresh();
            }
        } catch (Throwable $exception) {
            $event->update([
                'processing_status' => 'failed',
                'review_status' => 'pending',
                'error_message' => (string) $this->sensitiveDataSanitizer->sanitize($exception->getMessage()),
            ]);

            Log::error('Failed to process Nium compliance callback.', [
                'event_id' => $eventId,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        if ($event->match_status === 'unmatched') {
            Log::warning('Nium compliance callback requires manual matching.', [
                'event_id' => $event->event_id,
            ]);
        }

        return $this->responsePayload($event, false);
    }

    private function transactionNudge(Request $request): ?string
    {
        $hasType = $request->query->has('type');
        $hasValue = $request->query->has('value');
        if (! $hasType && ! $hasValue) {
            return null;
        }

        $type = strtoupper(trim((string) $request->query('type', '')));
        $value = trim((string) $request->query('value', ''));
        if ($type !== 'TRANSACTION') {
            throw new RuntimeException('Unsupported Nium compliance callback query type.');
        }
        if ($value === '') {
            throw new RuntimeException('Nium transaction compliance callback value is required.');
        }

        return $this->boundedIdentifier($value);
    }

    private function immutableCallbackEventId(array $payload): ?string
    {
        $value = Arr::get($payload, 'eventId')
            ?? Arr::get($payload, 'event_id')
            ?? Arr::get($payload, 'webhookEventId')
            ?? Arr::get($payload, 'webhook_event_id');

        return filled($value) ? $this->boundedIdentifier((string) $value) : null;
    }

    private function findTransaction(IntegrationProvider $provider, array $references): ?Transaction
    {
        if ($references === []) {
            return null;
        }

        return Transaction::query()
            ->with('transfer')
            ->where('provider_id', $provider->id)
            ->where(function ($query) use ($references): void {
                $query->whereIn('external_transaction_id', $references)
                    ->orWhereIn('reference_text', $references);
            })
            ->first();
    }

    private function findTransfer(IntegrationProvider $provider, array $references): ?Transfer
    {
        if ($references === []) {
            return null;
        }

        return Transfer::query()
            ->where('provider_id', $provider->id)
            ->where(function ($query) use ($references): void {
                $query->whereIn('external_transfer_id', $references)
                    ->orWhereIn('external_payment_id', $references)
                    ->orWhereIn('transfer_no', $references)
                    ->orWhereIn('client_reference', $references);
            })
            ->first();
    }

    private function findProviderAccount(IntegrationProvider $provider, array $customerReferences): ?UserProviderAccount
    {
        if ($customerReferences === []) {
            return null;
        }

        return UserProviderAccount::query()
            ->where('provider_id', $provider->id)
            ->whereIn('external_customer_id', $customerReferences)
            ->latest('id')
            ->first();
    }

    private function requestId(array $payload, Request $request): ?string
    {
        $value = $request->header('X-Request-Id')
            ?: $request->header('X-Nium-Request-Id')
            ?: Arr::get($payload, 'requestId')
            ?: Arr::get($payload, 'request_id');

        return filled($value) ? $this->boundedIdentifier((string) $value) : null;
    }

    private function eventId(
        array $payload,
        Request $request,
        ?string $requestId,
        array $references,
        ?string $status,
    ): string {
        $explicitId = Arr::get($payload, 'eventId')
            ?? Arr::get($payload, 'event_id')
            ?? Arr::get($payload, 'webhookEventId')
            ?? Arr::get($payload, 'webhook_event_id')
            ?? Arr::get($payload, 'id');

        if (filled($explicitId)) {
            return $this->boundedIdentifier((string) $explicitId);
        }

        if ($requestId !== null) {
            return $requestId;
        }

        if ($references !== []) {
            return hash('sha256', implode('|', $references).'|'.($status ?? 'UNKNOWN'));
        }

        return hash('sha256', $request->getContent() ?: json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function eventType(array $payload): ?string
    {
        $value = Arr::get($payload, 'eventType')
            ?? Arr::get($payload, 'event_type')
            ?? Arr::get($payload, 'type')
            ?? Arr::get($payload, 'name');

        return filled($value) ? Str::limit((string) $value, 100, '') : null;
    }

    private function complianceStatus(array $payload): ?string
    {
        foreach ([
            ['complianceStatus', 'compliance_status'],
            ['subStatus', 'sub_status'],
            ['eventStatus', 'event_status'],
            ['status'],
        ] as $keys) {
            $values = $this->valuesForKeys($payload, $keys);

            if (isset($values[0])) {
                return Str::limit((string) $values[0], 100, '');
            }
        }

        return null;
    }

    private function requiresAction(?string $status, array $payload): bool
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Z0-9]+/i', '_', (string) $status));
        $actionStatuses = [
            'ACTION_REQUIRED',
            'RFI_REQUESTED',
            'MORE_INFO_REQUIRED',
            'INFORMATION_REQUIRED',
            'REVIEW_REQUIRED',
            'NEEDS_REVIEW',
            'MANUAL_REVIEW',
        ];

        if (in_array($normalized, $actionStatuses, true)) {
            return true;
        }

        foreach ($this->valuesForKeys($payload, ['actionRequired', 'action_required', 'requiresAction', 'requires_action']) as $value) {
            if (filter_var($value, FILTER_VALIDATE_BOOL)) {
                return true;
            }
        }

        return false;
    }

    private function valuesForKeys(array $payload, array $keys): array
    {
        $normalizedKeys = array_map(
            static fn (string $key): string => strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key)),
            $keys,
        );
        $values = [];

        foreach (Arr::dot($payload) as $path => $value) {
            if (! is_scalar($value) || $value === '') {
                continue;
            }

            $leaf = (string) Str::afterLast((string) $path, '.');
            $normalizedLeaf = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $leaf));

            if (in_array($normalizedLeaf, $normalizedKeys, true)) {
                $values[] = Str::limit((string) $value, 255, '');
            }
        }

        return array_values(array_unique($values));
    }

    private function boundedIdentifier(string $value): string
    {
        return strlen($value) <= 255 ? $value : hash('sha256', $value);
    }

    private function responsePayload(NiumComplianceEvent $event, bool $duplicate): array
    {
        return [
            'message' => $duplicate
                ? 'Compliance callback already received.'
                : 'Compliance callback accepted.',
            'event_id' => $event->event_id,
            'duplicate' => $duplicate,
            'matched' => $event->match_status !== 'unmatched',
            'match_status' => $event->match_status,
            'review_status' => $event->review_status,
        ];
    }
}
