<?php

namespace App\Services\Nium;

use App\Models\IntegrationProvider;
use App\Models\NiumVirtualAccount;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Integrations\Contracts\ReprocessesWebhookEvent;
use App\Services\Integrations\Contracts\WebhookProvider;
use App\Services\Integrations\Support\StaticWebhookHeaderVerifier;
use App\Services\Wallet\LedgerService;
use App\Support\NiumOperationalData;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

class NiumWebhookService implements ReprocessesWebhookEvent, WebhookProvider
{
    public function __construct(
        private readonly SensitiveDataSanitizer $sensitiveDataSanitizer,
        private readonly StaticWebhookHeaderVerifier $staticHeaderVerifier,
        private readonly LedgerService $ledgerService,
        private readonly NiumProviderAccountStateService $providerAccountStateService,
        private readonly NiumCustomerOnboardingService $customerOnboardingService,
        private readonly NiumRfiWorkflowService $rfiWorkflowService,
    ) {}

    public function handleWebhook(IntegrationProvider $provider, Request $request): array
    {
        $this->verifyWebhookIfConfigured($request);

        $payload = $request->json()->all();
        $template = strtoupper((string) ($payload['template'] ?? ''));
        $isCustomerLifecycle = $this->providerAccountStateService->isCustomerLifecycleTemplate($template);

        if ($isCustomerLifecycle) {
            $this->verifyCustomerLifecycleEnvelope($payload, $request);
        }

        $eventId = $this->eventId($payload, $request, $isCustomerLifecycle);
        $eventType = $isCustomerLifecycle
            ? $template
            : $this->eventType($payload);
        $resource = $this->resourcePayload($payload);

        $existingEvent = WebhookEvent::query()
            ->where('provider_id', $provider->id)
            ->where('event_id', $eventId)
            ->first();

        if ($existingEvent !== null) {
            Log::info('Duplicate Nium webhook ignored.', [
                'provider_id' => $provider->id,
                'event_id' => $eventId,
            ]);

            return [
                'message' => 'Webhook already received.',
                'provider' => $provider->code,
                'event_id' => $eventId,
                'duplicate' => true,
            ];
        }

        try {
            $event = DB::transaction(function () use ($eventId, $eventType, $payload, $provider, $resource): WebhookEvent {
                return WebhookEvent::query()->create([
                    'provider_id' => $provider->id,
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'external_resource_id' => $this->externalResourceId($payload, $resource),
                    'payload' => $this->isVaAssigned($payload)
                        ? NiumOperationalData::project($payload)
                        : $this->sensitiveDataSanitizer->sanitize($payload),
                    'signature' => '[REDACTED]',
                    'processing_status' => 'received',
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
        }

        try {
            $this->processPayload($provider, $payload, $resource, $request);

            $event->update([
                'processing_status' => 'processed',
                'processed_at' => now(),
                'error_message' => null,
            ]);
        } catch (Throwable $exception) {
            $event->update([
                'processing_status' => 'failed',
                'error_message' => Str::limit($this->safeOperationalError($exception), 1000, ''),
            ]);

            if ($exception instanceof AccessDeniedHttpException) {
                throw $exception;
            }

            throw new RuntimeException($this->safeOperationalError($exception), previous: $exception);
        }

        return [
            'message' => 'Webhook received.',
            'provider' => $provider->code,
            'event_id' => $eventId,
        ];
    }

    public function reprocessWebhookEvent(IntegrationProvider $provider, WebhookEvent $event): WebhookEvent
    {
        $event = DB::transaction(function () use ($event): WebhookEvent {
            $event = WebhookEvent::query()
                ->whereKey($event->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($event->processing_status === 'processed') {
                return $event;
            }
            $event->update([
                'processing_status' => 'retrying',
                'error_message' => null,
            ]);

            return $event->fresh();
        });

        if ($event->processing_status === 'processed') {
            return $event->fresh('provider');
        }

        $payload = (array) ($event->payload ?? []);

        if (
            $this->providerAccountStateService->isCustomerLifecycleTemplate($payload['template'] ?? null) &&
            ($payload['clientHashId'] ?? null) === '[REDACTED]'
        ) {
            $payload['clientHashId'] = (string) config('services.nium.client_id', '');
        }

        try {
            $retryRequest = Request::create('/internal/nium-webhook-retry', 'POST');
            $retryRequest->headers->set('x-request-id', (string) $event->event_id);
            $this->processPayload($provider, $payload, $this->resourcePayload($payload), $retryRequest);
            $event->update([
                'processing_status' => 'processed',
                'processed_at' => now(),
                'error_message' => null,
            ]);
        } catch (Throwable $exception) {
            $event->update([
                'processing_status' => 'failed',
                'error_message' => Str::limit($this->safeOperationalError($exception), 1000, ''),
            ]);

            throw new RuntimeException($this->safeOperationalError($exception), previous: $exception);
        }

        return $event->fresh('provider');
    }

    private function processPayload(
        IntegrationProvider $provider,
        array $payload,
        array $resource,
        ?Request $request = null,
    ): void {
        $template = strtoupper((string) ($payload['template'] ?? ''));

        if ($this->isVaAssigned($payload)) {
            $this->processVaAssigned($provider, $payload);

            return;
        }

        if ($this->providerAccountStateService->isCustomerLifecycleTemplate($template)) {
            $this->processCustomerLifecyclePayload($provider, $payload, $request);

            return;
        }

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

    private function processCustomerLifecyclePayload(
        IntegrationProvider $provider,
        array $payload,
        ?Request $request,
    ): void {
        $providerAccount = $this->findCustomerProviderAccount($provider, $payload);

        if ($providerAccount === null) {
            throw new RuntimeException('Nium customer webhook could not be mapped to an existing onboarding account.');
        }
        $source = 'nium_webhook_notification:'.strtolower((string) $payload['template']);
        $this->assertNotificationIdentifiersMatch($providerAccount, $payload, $source, $request);
        $providerAccount = $this->providerAccountStateService->applyRestrictiveNotification(
            $providerAccount,
            $payload,
            $source,
        );
        $this->rfiWorkflowService->ingestCustomerEvidence(
            $provider,
            $providerAccount,
            $payload,
            authenticatedRequestId: (string) $request?->header('x-request-id'),
        );

        try {
            $providerAccount = $this->customerOnboardingService->retrieveCustomer(
                $providerAccount->fresh(),
                verifiedCustomerHashId: filled($payload['customerHashId'] ?? null)
                    ? (string) $payload['customerHashId']
                    : null,
                requestId: (string) $request?->header('x-request-id'),
            );
            $this->providerAccountStateService->recordVerifiedNotificationDetails(
                $providerAccount,
                $payload,
                $source,
            );
            $this->rfiWorkflowService->reconcileCustomerEvidence($providerAccount->fresh());
        } catch (NiumProviderIdConflictException $exception) {
            throw new RuntimeException('Nium customer reconciliation found a verified identifier conflict.', previous: $exception);
        } catch (Throwable $exception) {
            $this->providerAccountStateService->markReconciliationFailure(
                $providerAccount,
                'authoritative_get_customer_failed',
                $source,
                (string) $request?->header('x-request-id'),
            );

            throw new RuntimeException('Nium customer reconciliation failed; access remains restricted.', previous: $exception);
        }
    }

    private function processVaAssigned(IntegrationProvider $provider, array $payload): void
    {
        $account = $this->findCustomerProviderAccount($provider, $payload);
        $paymentId = $this->value($payload, ['uniquePaymentId', 'paymentId', 'virtualAccountNumber']);
        $currency = strtoupper((string) $this->value($payload, ['currencyCode', 'currency']));

        if ($account === null || ! filled($paymentId) || strlen($currency) !== 3) {
            throw new RuntimeException('Nium VA Assigned webhook is missing a mapped customer, payment ID, or currency.');
        }

        NiumVirtualAccount::query()->updateOrCreate(
            [
                'user_provider_account_id' => $account->id,
                'provider_payment_id' => (string) $paymentId,
            ],
            [
                'virtual_account_reference' => (string) $paymentId,
                'currency' => $currency,
                'account_category' => $this->value($payload, ['accountCategory']),
                'account_type' => $this->value($payload, ['accountType']),
                'status' => 'assigned',
                'assigned_at' => $this->value($payload, ['assignedAt', 'dateTime', 'updatedAt']) ?? now(),
            ],
        );
    }

    private function isVaAssigned(array $payload): bool
    {
        return in_array(strtoupper((string) ($payload['template'] ?? $payload['eventType'] ?? '')), [
            'VA_ASSIGNED',
            'VIRTUAL_ACCOUNT_ASSIGNED',
        ], true);
    }

    private function verifyCustomerLifecycleEnvelope(array $payload, Request $request): void
    {
        $requestId = trim((string) $request->header('x-request-id'));

        if ($requestId === '') {
            throw new RuntimeException('Nium lifecycle webhook requires a non-empty x-request-id header.');
        }

        $configuredClientHashId = (string) config('services.nium.client_id', '');
        $incomingClientHashId = (string) ($payload['clientHashId'] ?? '');

        if ($configuredClientHashId === '' || $incomingClientHashId === '' || ! hash_equals($configuredClientHashId, $incomingClientHashId)) {
            throw new AccessDeniedHttpException('Nium webhook clientHashId does not match this integration.');
        }
    }

    private function assertNotificationIdentifiersMatch(
        UserProviderAccount $account,
        array $payload,
        string $source,
        ?Request $request,
    ): void {
        $incomingCustomer = trim((string) ($payload['customerHashId'] ?? ''));
        $incomingWallet = trim((string) (
            $payload['walletHashId']
            ?? Arr::get($payload, 'wallets.0.walletHashId')
            ?? Arr::get($payload, 'walletHashIds.0')
            ?? ''
        ));

        foreach ([
            ['external_customer_id', (string) $account->external_customer_id, $incomingCustomer],
            ['external_account_id', (string) $account->external_account_id, $incomingWallet],
        ] as [$field, $current, $incoming]) {
            if ($current !== '' && $incoming !== '' && ! hash_equals($current, $incoming)) {
                $this->providerAccountStateService->quarantineIdentifierConflict(
                    $account,
                    $field,
                    $current,
                    $incoming,
                    $source,
                    (string) $request?->header('x-request-id'),
                );

                throw new RuntimeException('Nium lifecycle webhook identifier conflict was quarantined.');
            }
        }
    }

    private function findCustomerProviderAccount(IntegrationProvider $provider, array $payload): ?UserProviderAccount
    {
        $customerHashId = $payload['customerHashId'] ?? null;
        $externalReference = $payload['externalId'] ?? null;

        if (! filled($customerHashId) && ! filled($externalReference)) {
            return null;
        }

        $byCustomer = filled($customerHashId)
            ? UserProviderAccount::query()
                ->where('provider_id', $provider->id)
                ->where('external_customer_id', (string) $customerHashId)
                ->latest('id')
                ->first()
            : null;
        $byExternalReference = filled($externalReference)
            ? UserProviderAccount::query()
                ->where('provider_id', $provider->id)
                ->where('external_reference', (string) $externalReference)
                ->latest('id')
                ->first()
            : null;

        if ($byCustomer !== null && $byExternalReference !== null && $byCustomer->id !== $byExternalReference->id) {
            throw new RuntimeException('Nium customer webhook identifiers map to different provider accounts.');
        }

        return $byCustomer ?? $byExternalReference;
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
        $staticHeaderName = (string) config('services.nium.webhook.static_header_name', 'x-partner-key');
        $staticHeaderValue = (string) config('services.nium.webhook.static_header_value', '');

        if (strtolower(trim($staticHeaderName)) !== 'x-partner-key' || $staticHeaderValue === '') {
            throw new RuntimeException('Nium webhook x-partner-key authentication is not configured.');
        }

        if (! $this->staticHeaderVerifier->isValid($request, $staticHeaderName, $staticHeaderValue)) {
            Log::warning('Rejected Nium webhook with invalid static authentication header.', [
                'ip_address' => $request->ip(),
            ]);

            throw new AccessDeniedHttpException('Invalid Nium webhook authentication.');
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

    private function eventId(array $payload, Request $request, bool $isCustomerLifecycle = false): string
    {
        if ($isCustomerLifecycle) {
            return trim((string) $request->header('x-request-id'));
        }

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
            'customerHashId',
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

        return in_array($sqlState, ['23000', '23505'], true)
            && (
                str_contains($constraint, 'webhook_events_provider_id_event_id_unique')
                || str_contains($constraint, 'webhook_events.provider_id, webhook_events.event_id')
            );
    }

    private function safeOperationalError(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof NiumProviderIdConflictException => 'verified_identifier_conflict',
            $exception instanceof AccessDeniedHttpException => 'webhook_authentication_failed',
            str_contains(strtolower($exception->getMessage()), 'reconciliation') => 'customer_reconciliation_failed',
            default => 'webhook_processing_failed',
        };
    }
}
