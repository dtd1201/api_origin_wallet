<?php

namespace App\Services\Nium;

use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Jobs\Nium\SubmitNiumHkEntityKycJob;
use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountDetail;
use App\Models\NiumVirtualAccountOperation;
use App\Models\NiumVirtualAccountRequest;
use App\Models\Transfer;
use App\Models\User;
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
        private readonly NiumCorporateRfiService $corporateRfiService,
        private readonly NiumVirtualAccountDetailService $virtualAccountDetailService,
    ) {}

    public function handleWebhook(IntegrationProvider $provider, Request $request): array
    {
        OwnerFirst::outside();
        $this->verifyWebhookIfConfigured($request);

        $payload = $request->json()->all();
        $template = strtoupper((string) ($payload['template'] ?? ''));
        $isCustomerLifecycle = $this->providerAccountStateService->isCustomerLifecycleTemplate($template);

        if ($isCustomerLifecycle) {
            $this->verifyCustomerLifecycleEnvelope($payload, $request);
        }

        if (NiumVirtualAccountWebhookContract::isAssignmentEvent($payload)) {
            NiumVirtualAccountWebhookContract::validateDeliveryIdentity($payload);
        }
        $eventId = $this->eventId($payload, $request, $isCustomerLifecycle);
        $eventType = $isCustomerLifecycle
            ? $template
            : $this->eventType($payload);
        $resource = $this->resourcePayload($payload);
        $vaEvidence = NiumVirtualAccountWebhookContract::isAssignmentEvent($payload)
            ? NiumVirtualAccountWebhookContract::capture($provider, $request, $eventId, $eventType) : null;

        $existingEvent = WebhookEvent::query()
            ->where('provider_id', $provider->id)
            ->where('event_id', $eventId)
            ->first();

        if ($existingEvent !== null) {
            return $this->duplicateReceipt($provider, $existingEvent, $vaEvidence);
        }

        try {
            $event = DB::transaction(function () use ($eventId, $eventType, $payload, $provider, $resource, $vaEvidence): WebhookEvent {
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
                    ...($vaEvidence !== null ? [] : ['nium_processing_claim' => ['client_identity_sha256' => [
                        'payload' => is_string($payload['clientHashId'] ?? null) ? hash('sha256', $payload['clientHashId']) : null,
                        'resource' => is_string($resource['clientHashId'] ?? null) ? hash('sha256', $resource['clientHashId']) : null,
                    ]]]),
                    ...($vaEvidence === null ? [] : ['nium_va_evidence' => $vaEvidence]),
                ]);
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicateWebhookEventException($exception)) {
                $winner = WebhookEvent::query()->where('provider_id', $provider->id)->where('event_id', $eventId)->firstOrFail();

                return $this->duplicateReceipt($provider, $winner, $vaEvidence);
            }

            throw $exception;
        }

        if ($event->nium_va_evidence !== null) {
            $event = $this->recoverVaReceipt($provider, $event);

            return [
                'message' => 'Webhook received.', 'provider' => $provider->code,
                'event_id' => $eventId, 'processing_status' => $event->processing_status,
            ];
        }

        $processed = false;
        $event = $this->processClaim($event,
            function (WebhookEvent $claimed) use ($provider, $payload, $resource, $request, &$processed): void {
                $this->processPayload($provider, $payload, $resource, $request, $claimed);
                $processed = true;
            });
        if ($processed && $event->processing_status === 'processed') $this->dispatchEntityKyc($event);

        return [
            'message' => 'Webhook received.',
            'provider' => $provider->code,
            'event_id' => $eventId,
        ];
    }

    public function reprocessWebhookEvent(IntegrationProvider $provider, WebhookEvent $event): WebhookEvent
    {
        OwnerFirst::outside();
        if ($event->nium_va_evidence !== null) {
            return $this->recoverVaReceipt($provider, $event);
        }
        if ((string) $event->provider_id !== (string) $provider->id || $provider->code !== 'nium') {
            throw new RuntimeException('Nium receipt provider does not match recovery context.');
        }
        $processed = false;
        $event = $this->processClaim($event,
            function (WebhookEvent $claimed) use ($provider, &$processed): void {
                $payload = (array) ($claimed->payload ?? []);
                $resource = $this->resourcePayload($payload);
                [$payload, $resource] = $this->replayClientScope($claimed, $payload, $resource);
                $retryRequest = Request::create('/internal/nium-webhook-retry', 'POST');
                $retryRequest->headers->set('x-request-id', (string) $claimed->event_id);
                $this->processPayload($provider, $payload, $resource, $retryRequest, $claimed);
                $processed = true;
            });
        if ($processed && $event->processing_status === 'processed') $this->dispatchEntityKyc($event);
        return $event->fresh('provider');
    }

    private function processClaim(WebhookEvent $event, callable $work): WebhookEvent
    {
        try {
            return app(NiumWebhookProcessingClaim::class)->process($event, $work);
        } catch (Throwable $exception) {
            if ($exception instanceof AccessDeniedHttpException) throw $exception;
            throw new RuntimeException($this->safeOperationalError($exception), previous: $exception);
        }
    }

    private function replayClientScope(WebhookEvent $event, array $payload, array $resource): array
    {
        $client = (string) config('services.nium.client_id', '');
        $hashes = $event->nium_processing_claim['client_identity_sha256'] ?? [];
        $parts = ['payload' => $payload, 'resource' => $resource];
        foreach ($parts as $name => &$part) {
            if (($part['clientHashId'] ?? null) !== '[REDACTED]') continue;
            // Historical lifecycle envelopes already required a matching client before receipt.
            $verifiedLifecycle = ! isset($hashes[$name])
                && $this->providerAccountStateService->isCustomerLifecycleTemplate($payload['template'] ?? null);
            if ($client === '' || (! $verifiedLifecycle
                && (! is_string($hashes[$name] ?? null) || ! hash_equals($hashes[$name], hash('sha256', $client))))) {
                throw new RuntimeException('Nium webhook replay client scope cannot be verified.');
            }
            $part['clientHashId'] = $client;
        }
        unset($part);
        return [$parts['payload'], $parts['resource']];
    }

    private function duplicateReceipt(IntegrationProvider $provider, WebhookEvent $event, ?array $evidence): array
    {
        if ($evidence !== null && $event->nium_va_evidence !== null) {
            if ($event->nium_va_evidence['body_sha256'] !== $evidence['body_sha256']) {
                throw new RuntimeException('VA webhook delivery identity conflicts with its immutable receipt.');
            }
            $event = $this->recoverVaReceipt($provider, $event);
        }

        // Existing HTTP acknowledgement contract; status describes internal processing separately.
        return [
            'message' => 'Webhook already received.', 'provider' => $provider->code,
            'event_id' => $event->event_id, 'duplicate' => true,
            ...($event->nium_va_evidence === null ? [] : ['processing_status' => $event->processing_status]),
        ];
    }

    private function recoverVaReceipt(IntegrationProvider $provider, WebhookEvent $event): WebhookEvent
    {
        if ((int) $event->provider_id !== (int) $provider->id) {
            throw new RuntimeException('VA receipt provider does not match recovery context.');
        }

        return app(NiumVirtualAccountWebhookRecovery::class)->process($event, function (WebhookEvent $claimed) use ($provider): void {
            $payload = NiumVirtualAccountWebhookContract::payload($claimed);
            $this->processPayload($provider, $payload, $this->resourcePayload($payload), event: $claimed);
        });
    }

    private function processPayload(
        IntegrationProvider $provider,
        array $payload,
        array $resource,
        ?Request $request = null,
        ?WebhookEvent $event = null,
    ): void {
        $template = strtoupper((string) ($payload['template'] ?? ''));

        if ($template === 'CARD_WALLET_FUNDING_WEBHOOK') {
            $this->processFundingWebhook($provider, $payload);

            return;
        }

        if (NiumVirtualAccountWebhookContract::isAssignmentEvent($payload)) {
            $this->processVaAssignmentEvent($provider, $payload, $event);

            return;
        }

        if ($this->providerAccountStateService->isCustomerLifecycleTemplate($template)) {
            $this->processCustomerLifecyclePayload($provider, $payload, $request);

            return;
        }

        if ((config('nium_dynamic_payouts.reconciliation_enabled', false) === true
                || config('nium_dynamic_confirmation.automatic_dispatch_enabled', false) === true)
            && app(\App\Services\Nium\DynamicConfirmation\DynamicPayoutWebhook::class)->observe($provider, $payload, $resource)) {
            return;
        }

        $transfer = $this->findTransfer($provider, $payload, $resource);

        if ($transfer !== null) {
            $providerStatus = strtoupper(trim((string) (
                $this->value($resource, ['status', 'subStatus', 'paymentStatus'])
                    ?? $this->value($payload, ['status', 'eventStatus'])
            )));
            if ($providerStatus === '') {
                $providerStatus = match ($template) {
                    'REMIT_TRANSACTION_PAID_WEBHOOK' => 'PAID',
                    'REMIT_TRANSACTION_RETURNED_WEBHOOK' => 'RETURNED',
                    'REMIT_TRANSACTION_REJECTED_WEBHOOK' => 'REJECTED',
                    default => '',
                };

                if (
                    $providerStatus === ''
                    && $template === 'REMIT_TRANSACTION_COMPLETED_WEBHOOK'
                    && strtoupper(trim((string) ($payload['reason'] ?? ''))) === 'SUCCESS'
                ) {
                    $providerStatus = 'COMPLETED';
                }
            }
            $status = $this->normalizeTransferStatus($providerStatus);
            $statusAt = $this->transferStatusTimestamp($resource, $payload);

            OwnerFirst::transfer($transfer, function () use ($provider, $payload, $providerStatus, $resource, $status, $statusAt, $transfer): void {
                $locked = Transfer::query()
                    ->whereKey($transfer->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((string) $locked->user_id !== (string) $transfer->user_id
                    || (string) $locked->provider_id !== (string) $provider->id) {
                    throw new RuntimeException('Nium webhook transfer ownership changed.');
                }
                foreach (['customerHashId' => 'external_customer_id', 'walletHashId' => 'external_account_id'] as $field => $column) {
                    $identity = $resource[$field] ?? $payload[$field] ?? null;
                    if ($identity !== null && ! UserProviderAccount::query()->where('provider_id', $provider->id)
                        ->where('user_id', $locked->user_id)->where($column, $identity)->exists()) {
                        throw new RuntimeException('Nium webhook transfer account binding changed.');
                    }
                }

                $terminalStatuses = ['completed', 'failed', 'cancelled', 'rejected'];
                $currentIsTerminal = in_array($locked->status, $terminalStatuses, true);
                $incomingIsTerminal = in_array($status, $terminalStatuses, true);

                $currentStatusAt = $locked->provider_status_at !== null
                    ? \Illuminate\Support\Carbon::parse($locked->provider_status_at)
                    : null;

                if (
                    $currentStatusAt !== null
                    && $statusAt !== null
                    && $statusAt->lt($currentStatusAt)
                ) {
                    return;
                }

                // Once Origin has applied a terminal financial outcome, a later
                // non-terminal webhook must never regress the transfer.
                if ($currentIsTerminal && ! $incomingIsTerminal) {
                    return;
                }

                // A conflicting terminal outcome cannot be applied automatically:
                // the previous terminal outcome may already have settled or
                // released funds and therefore requires reconciliation.
                if ($currentIsTerminal && $incomingIsTerminal && $locked->status !== $status) {
                    throw new RuntimeException(
                        'Nium transfer webhook conflicts with an existing terminal status.'
                    );
                }

                $locked->update([
                    'external_transfer_id' => $this->value($resource, [
                        'systemReferenceNumber',
                        'system_reference_number',
                        'remittanceId',
                        'remittance_id',
                        'id',
                    ]) ?? $locked->external_transfer_id,
                    'external_payment_id' => $this->value($resource, [
                        'paymentId',
                        'payment_id',
                        'paymentReferenceNumber',
                        'payment_reference_number',
                    ]) ?? $locked->external_payment_id,
                    'status' => $status,
                    'provider_status' => $providerStatus,
                    'failure_code' => $status === 'failed'
                        ? (string) ($this->value($resource, ['code', 'failureCode', 'errorCode']) ?? 'provider_error')
                        : null,
                    'failure_reason' => $status === 'failed'
                        ? (string) ($this->value($resource, [
                            'message',
                            'remarks',
                            'failureReason',
                            'errorMessage',
                            'reasonDescription',
                            'reason',
                        ]) ?? 'Nium transfer failed.')
                        : null,
                    'completed_at' => in_array($status, ['completed', 'failed', 'cancelled'], true)
                        ? ($this->value($resource, ['dateTime', 'updatedAt', 'completedAt']) ?? now())
                        : $locked->completed_at,
                    'provider_status_at' => $statusAt ?? $locked->provider_status_at,
                    'raw_data' => array_merge($locked->raw_data ?? [], [
                        'last_webhook_payload' => $this->sensitiveDataSanitizer->sanitize($payload),
                    ]),
                ]);

                $updated = $locked->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);

                $this->syncTransaction($provider, $updated, $payload, $resource);
                $this->ledgerService->applyTransferTerminalStatus($updated);
            });
        }

    }

    private function processFundingWebhook(IntegrationProvider $provider, array $payload): void
    {
        $walletHashId = trim((string) ($payload['walletHashId'] ?? ''));
        $currency = strtoupper(trim((string) ($payload['transactionCurrency'] ?? '')));
        $walletBalance = $payload['walletBalance'] ?? null;

        if ($walletHashId === '' || strlen($currency) !== 3 || ! is_numeric($walletBalance)) {
            throw new RuntimeException('Nium funding webhook is missing a wallet ID, currency, or wallet balance.');
        }

        $account = UserProviderAccount::query()
            ->where('provider_id', $provider->id)
            ->where('external_account_id', $walletHashId)
            ->latest('id')
            ->first();

        if ($account === null) {
            throw new RuntimeException('Nium funding webhook could not map the wallet to a provider account.');
        }

        OwnerFirst::account($account, function () use ($account, $currency, $provider, $walletBalance, $walletHashId, $payload): void {
            $account = UserProviderAccount::query()->lockForUpdate()->findOrFail($account->id);
            if ((string) $account->provider_id !== (string) $provider->id || $account->external_account_id !== $walletHashId
                || (isset($payload['customerHashId']) && $account->external_customer_id !== $payload['customerHashId'])) {
                throw new \App\Services\Wallet\Concurrency\OwnerBindingChanged('Funding webhook account binding changed.');
            }
            IntegrationProvider::query()->whereKey($provider->id)->lock('for key share')->firstOrFail();
            $attributes = [
                'provider_id' => $provider->id,
                'external_account_id' => $walletHashId,
                'currency' => $currency,
            ];
            $balance = Balance::query()
                ->where($attributes)
                ->lockForUpdate()
                ->first();

            if ($balance !== null) {
                if ((string) $balance->user_id !== (string) $account->user_id) {
                    throw new RuntimeException('Nium funding webhook wallet ownership conflict.');
                }
                if (NiumBalanceOwnership::preservesBooks($balance)) {
                    // A wallet total cannot identify new funding separately from
                    // an in-flight payout, fee or return. Local holds and historical
                    // Dynamic books use the same ownership policy as balance sync.
                    $raw = $balance->raw_data ?? [];
                    $raw['nium_funding_balance_observation'] = [
                        'wallet_balance' => $walletBalance, 'observed_at' => now()->toISOString(),
                    ];
                    unset($raw['dynamic_authenticated_availability']);
                    if (! $balance->update(['raw_data' => $raw])) {
                        throw new RuntimeException('Nium funding observation was not persisted.');
                    }
                    return;
                }
                $balance->update([
                    'user_id' => $account->user_id,
                    'available_balance' => $walletBalance,
                    'ledger_balance' => bcadd((string) $walletBalance, (string) $balance->reserved_balance, 8),
                    'as_of' => now(),
                ]);

                return;
            }

            Balance::query()->create([
                ...$attributes,
                'user_id' => $account->user_id,
                'available_balance' => $walletBalance,
                'ledger_balance' => bcadd((string) $walletBalance, '0', 8),
                'reserved_balance' => 0,
                'as_of' => now(),
            ]);
        });
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
        if (strtoupper((string) $payload['template']) === 'CUSTOMER_ENTITY_KYC_STATUS') {
            app(NiumHkSubmitKycService::class)->reconcileEntityWebhook(
                new WebhookEvent(['provider_id' => $provider->id, 'external_resource_id' => $payload['customerHashId'] ?? null, 'payload' => $payload]),
            );
        }
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
            $providerAccount = $providerAccount->fresh();
            $rfiEvidence = $this->rfiWorkflowService->requiresAuthoritativeRfiFetch($providerAccount)
                ? $this->corporateRfiService->fetch($providerAccount)
                : null;
            $this->rfiWorkflowService->reconcileCustomerEvidence($providerAccount, $rfiEvidence);
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

    private function dispatchEntityKyc(WebhookEvent $event): void
    {
        $payload = (array) $event->payload;

        if ($event->event_type === 'CUSTOMER_STATUS_WEBHOOK'
            && ($payload['status'] ?? null) === 'pending'
            && ($payload['subStatus'] ?? null) === 'awaiting_kyc') {
            // Retrieval above may have persisted nested provider entity evidence; the job
            // submits only those explicitly marked kyc_required with a provider reference.
            SubmitNiumHkEntityKycJob::dispatch($event->id)->afterCommit();
        }
        if ($event->event_type === 'CUSTOMER_ENTITY_KYC_STATUS'
            && ($payload['kycStatus'] ?? null) === 'kyc_required') {
            SubmitNiumHkEntityKycJob::dispatch($event->id)->afterCommit();
        }
    }

    private function processVaAssignmentEvent(IntegrationProvider $provider, array $payload, ?WebhookEvent $event): void
    {
        // Protect enrolled histories and wallet aliases even when a notification has a wrong
        // target. A mismatching notification must never fall through to the legacy VA writer.
        $account = $this->findCustomerProviderAccount($provider, $payload);
        $requests = NiumVirtualAccountRequest::query()->whereIn('operation_id',
            NiumVirtualAccountOperation::query()->whereIn('user_provider_account_id',
                UserProviderAccount::query()->where('provider_id', $provider->id)->select('id'))->select('id'))
            ->get()->filter(function (NiumVirtualAccountRequest $request) use ($payload, $account): bool {
                $snapshot = $request->identity()->snapshot();

                return ($account !== null && $snapshot['provider_account_id'] === (int) $account->id)
                    || ($payload['customerHashId'] ?? null) === $snapshot['customer_id']
                    || ($payload['walletHashId'] ?? null) === $snapshot['wallet_id'];
            });
        if ($requests->isEmpty() && NiumVirtualAccountWebhookContract::isAssigned($payload)) {
            $this->processVaAssigned($provider, $payload, $event);

            return;
        }
        if ($event === null || $event->nium_va_evidence === null) {
            // Historical sanitized payloads are not authenticated exact-target evidence.
            $this->observeVaEvent($event, 'missing_authenticated_va_evidence');

            return;
        }
        if (NiumVirtualAccountWebhookContract::type($payload) === NiumVirtualAccountWebhookContract::FAILED) {
            // The documented failure contract has neither a Payment ID nor an assignment key.
            // Delivery UUID, timing, bank/currency and undocumented tags do not prove rejection.
            $this->observeVaEvent($event, 'uncorrelated_assignment_failure');

            return;
        }
        $paymentId = $payload['uniquePaymentId'] ?? null;
        if (! NiumPaymentIdValidator::isValid($paymentId)) {
            $this->observeVaEvent($event, 'invalid_assignment_payment_id');

            return;
        }
        $reconciliation = app(NiumVirtualAccountReconciliationService::class);
        $candidates = $requests->filter(fn (NiumVirtualAccountRequest $request): bool =>
            NiumVirtualAccountWebhookContract::permitsAssignment($event, $request->identity(), $paymentId)
            && $reconciliation->hasCorrelatedPostPaymentId($request, $paymentId));
        if ($candidates->count() !== 1) {
            $this->observeVaEvent($event, $candidates->count() > 1 ? 'ambiguous_assignment_causality' : 'insufficient_assignment_causality_or_dimensions');

            return;
        }
        $operation = $reconciliation->reconcileWebhook($candidates->sole(), $event);
        if ($operation->status !== NiumVirtualAccountOperation::ASSIGNED || $operation->provider_payment_id !== $paymentId) {
            $this->observeVaEvent($event, 'assignment_remains_unresolved');
        }
    }

    private function observeVaEvent(?WebhookEvent $event, string $reason): void
    {
        if ($event?->nium_va_evidence !== null || $event?->nium_processing_claim !== null) {
            // The recovery owner publishes status using its fenced claim after processing.
            $event->fill(['processing_status' => 'observed', 'error_message' => $reason]);
        } else {
            $event?->update(['processing_status' => 'observed', 'error_message' => $reason]);
        }
        Log::notice('Nium VA webhook retained as observed evidence.', ['webhook_event_id' => $event?->id, 'reason' => $reason]);
    }

    private function processVaAssigned(IntegrationProvider $provider, array $payload, ?WebhookEvent $event = null): void
    {
        if ($event === null || $event->nium_va_evidence === null) {
            $this->observeVaEvent($event, 'legacy_assignment_missing_authenticated_evidence');

            return;
        }
        // A receipt can corroborate an original POST; it cannot choose an operation
        // by today's account mapping or by matching pending dimensions.
        $payload = NiumVirtualAccountWebhookContract::payload($event);
        $paymentId = $payload['uniquePaymentId'] ?? null;
        if (! NiumPaymentIdValidator::isValid($paymentId)) {
            throw new RuntimeException('Nium VA Assigned webhook is missing a valid payment ID.');
        }
        $proof = app(NiumLegacyVirtualAccountEvidence::class);
        $candidates = NiumVirtualAccountOperation::query()
            ->whereNotIn('id', NiumVirtualAccountRequest::query()->select('operation_id'))
            ->whereHas('providerAccount', fn ($q) => $q->where('provider_id', $provider->id))
            ->where('status', '<>', NiumVirtualAccountOperation::FAILED)
            ->whereNotNull('submitted_at')
            ->get()->filter(function (NiumVirtualAccountOperation $operation) use ($proof, $event, $paymentId): bool {
                try {
                    $account = $operation->providerAccount()->firstOrFail();
                    $identity = $proof->target($operation, $account);
                    if (! NiumVirtualAccountWebhookContract::permitsAssignment($event, $identity, $paymentId)) {
                        return false;
                    }
                    try {
                        return $proof->assignedPaymentId($operation, $account) === $paymentId;
                    } catch (Throwable) {
                        app(NiumVirtualAccountReconciliationService::class)->legacyReview(
                            $operation, 'legacy_webhook_evidence_unproven', $event,
                        );

                        return false;
                    }
                } catch (Throwable) {
                    return false;
                }
            });
        if ($candidates->count() !== 1) {
            $this->observeVaEvent($event, 'legacy_assignment_evidence_missing_conflicting_or_ambiguous');

            return;
        }
        $operation = app(NiumVirtualAccountReconciliationService::class)->assign(
            $candidates->sole(), $paymentId, 'verified_legacy_webhook_and_post', $event,
        );
        if ($operation->status !== NiumVirtualAccountOperation::ASSIGNED || $operation->provider_payment_id !== $paymentId) {
            $this->observeVaEvent($event, 'legacy_assignment_requires_manual_review');

            return;
        }
        try {
            $this->virtualAccountDetailService->sync($operation->virtualAccount()->firstOrFail());
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function isVaAssigned(array $payload): bool
    {
        return NiumVirtualAccountWebhookContract::isAssigned($payload);
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
            'systemReferenceNumber',
            'system_reference_number',
        ]);
        $externalTransactionId ??= $this->value($payload, [
            'transactionId',
            'transaction_id',
            'paymentReferenceNumber',
            'payment_reference_number',
            'paymentId',
            'payment_id',
            'systemReferenceNumber',
            'system_reference_number',
            'remittanceId',
            'remittance_id',
        ]);

        if (! filled($externalTransactionId)) {
            return;
        }

        app(NiumTransactionWriter::class)->save($provider, (int) $transfer->user_id, (string) $externalTransactionId,
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
        $references = [];
        foreach ([$resource, $payload] as $data) {
            foreach (['systemReferenceNumber', 'system_reference_number', 'remittanceId', 'remittance_id',
                'paymentId', 'payment_id', 'paymentReferenceNumber', 'payment_reference_number',
                'clientReference', 'client_reference', 'customerComments'] as $field) {
                if (is_string($data[$field] ?? null) && $data[$field] !== '') $references[] = $data[$field];
            }
        }
        if (is_string($resource['id'] ?? null)) $references[] = $resource['id'];
        if ($references === []) return null;
        $scope = [];
        foreach (['customerHashId' => 'external_customer_id', 'walletHashId' => 'external_account_id'] as $field => $column) {
            if (isset($payload[$field], $resource[$field]) && $payload[$field] !== $resource[$field]) {
                throw new RuntimeException('Nium webhook account scope conflict.');
            }
            $value = $resource[$field] ?? $payload[$field] ?? null;
            if ($value !== null) {
                if (! is_string($value) || trim($value) === '') throw new RuntimeException('Nium webhook account scope invalid.');
                $scope[$column] = $value;
            }
        }
        if ($scope !== []) {
            $accounts = UserProviderAccount::query()->where('provider_id', $provider->id)->where($scope)->limit(2)->get();
            if ($accounts->count() !== 1) throw new RuntimeException('Nium webhook account scope is missing or ambiguous.');
            $owner = (int) $accounts->sole()->user_id;
        } else {
            // Legacy envelopes may omit account scope. Only an unambiguous provider-wide
            // discovery can identify an owner; the authoritative lookup remains owner-scoped.
            $candidates = Transfer::query()->where('provider_id', $provider->id)
                ->where(function ($q) use ($references): void {
                    foreach (['external_transfer_id', 'external_payment_id', 'transfer_no', 'client_reference', 'provider_operation_key'] as $field) {
                        $q->orWhereIn($field, $references);
                    }
                })->limit(2)->get();
            if ($candidates->isEmpty()) return null;
            if ($candidates->count() !== 1) throw new RuntimeException('Nium webhook transfer reference is ambiguous.');
            $owner = (int) $candidates->sole()->user_id;
        }
        return app(NiumTransactionWriter::class)->findTransfer($provider, $owner, $references);
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
        foreach (['template', 'eventType'] as $key) {
            if (filled($payload[$key] ?? null)) {
                return (string) $payload[$key];
            }
        }

        return 'nium.webhook';
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

    private function transferStatusTimestamp(
        array $resource,
        array $payload,
    ): ?\Illuminate\Support\Carbon {
        $value = $this->value($resource, [
            'dateTime',
            'updatedAt',
            'lastUpdatedAt',
            'completedAt',
            'createdAt',
        ]) ?? $this->value($payload, [
            'eventTime',
            'event_time',
            'timestamp',
            'createdAt',
        ]);

        if (! filled($value)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeTransferStatus(mixed $status): string
    {
        return match (strtoupper((string) $status)) {
            'PAID', 'SUCCESS', 'SUCCEEDED', 'COMPLETED' => 'completed',
            'FAILED', 'ERROR', 'REJECTED', 'RETURNED' => 'failed',
            'CANCELLED', 'CANCELED', 'VOIDED' => 'cancelled',
            'PENDING', 'PROCESSING', 'PG_PROCESSING', 'COMPLIANCE_COMPLETED', 'IN_PROGRESS', 'ACCEPTED' => 'pending',
            default => 'pending',
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
