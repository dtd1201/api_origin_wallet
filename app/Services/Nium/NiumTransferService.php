<?php

namespace App\Services\Nium;

use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\UserProviderAccount;
use App\Services\Integrations\Contracts\PreparedTransferStatusProvider;
use App\Services\Transfers\TransferEligibilityService;
use App\Services\Wallet\LedgerService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NiumTransferService implements PreparedTransferStatusProvider
{
    public function __construct(
        private readonly NiumService $niumService,
        private readonly TransferEligibilityService $eligibilityService,
        private readonly NiumTransferPolicy $policy,
        private readonly NiumPurposeCodeService $purposeCodes,
        private readonly NiumPayoutFxLockService $payoutFxLockService,
        private readonly LedgerService $ledger,
    ) {}

    public function submitTransfer(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        $authoritativePurposeCodes = $this->purposeCodes->supported($transfer->user()->firstOrFail());

        // Provider I/O must remain outside the transfer row-lock transaction.
        $preparedFxQuote = $this->preparePayoutFxLockIfRequired($provider, $transfer, $authoritativePurposeCodes);

        [$transfer, $payload, $providerIdentifiers] = DB::transaction(function () use (
            $provider,
            $transfer,
            $authoritativePurposeCodes,
            $preparedFxQuote,
        ): array {
            $locked = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if (! in_array($locked->status, ['draft', 'approval_required', 'approved'], true)) {
                throw new RuntimeException('Transfer has already entered provider submission and cannot be submitted again.');
            }

            $locked->load(['provider', 'user.kycProfile', 'beneficiary', 'sourceBankAccount', 'fxQuote']);
            if ($locked->provider_id !== $provider->id) {
                throw new RuntimeException('Transfer provider does not match the Nium submission provider.');
            }
            $this->eligibilityService->ensureTransferCanBeSubmitted($locked);

            if ($preparedFxQuote !== null) {
                $this->attachPreparedPayoutFxLock($locked, $preparedFxQuote);
            }

            $providerIdentifiers = $this->providerAccountIdentifiers($locked);
            $this->ensureAuthoritativeQuote($locked);
            $this->policy->assertTransfer($locked, $authoritativePurposeCodes);
            $payload = $this->buildTransferPayload($locked, $authoritativePurposeCodes);

            if ($locked->provider_operation_key !== null) {
                throw new RuntimeException('This durable operation already claimed its only dispatch attempt.');
            }
            $operationKey = 'nium-'.Str::uuid()->toString();
            $locked->update([
                'provider_operation_key' => $operationKey,
                'raw_data' => array_merge($locked->raw_data ?? [], ['nium_execution' => [
                    'operation_key' => $operationKey, 'request_uuid' => substr($operationKey, 5),
                    'dispatch_claimed_at' => now()->toISOString(),
                ]]),
            ]);
            if ($this->ledger->reserveTransfer($locked) === null) {
                throw new RuntimeException('NIUM dispatch requires an enabled reservation ledger.');
            }
            if ($locked->target_currency === 'SGD') {
                // Reserve against the fresh approved snapshot before consuming its audit.
                $this->payoutFxLockService->consumeForSubmission($locked, $operationKey);
            }
            $locked->refresh();
            $locked->update(['status' => 'submitting']);

            return [
                $locked->fresh(['provider', 'user', 'beneficiary', 'sourceBankAccount', 'fxQuote']),
                $payload,
                $providerIdentifiers,
            ];
        });

        try {
            $response = $this->niumService->post(
                path: $this->niumService->path(
                    (string) config('services.nium.transfer_endpoint'),
                    [
                        'client' => $this->niumService->clientId(),
                        'customer' => $providerIdentifiers['customer'],
                        'wallet' => $providerIdentifiers['wallet'],
                    ],
                ),
                payload: $payload,
                user: $transfer->user,
                relatedTransferId: $transfer->id,
                operation: 'transfer_money',
                externalReference: $transfer->provider_operation_key,
            );
        } catch (Throwable) {
            // Once the durable dispatch claim commits, no exception proves that
            // the bank did not receive the POST. Keep funds until reconciliation.
            return $this->recordOutcome($transfer, 'submission_unknown', [
                'failure_code' => 'provider_submission_unknown',
                'failure_reason' => 'Provider submission outcome is unknown; do not retry the POST.',
            ]);
        }

        $responseData = $response->json();
        $responseData = is_array($responseData) ? $responseData : [];
        $reference = $responseData['system_reference_number'] ?? $responseData['systemReferenceNumber'] ?? null;
        $payment = $responseData['payment_id'] ?? $responseData['paymentId'] ?? null;
        $fields = ['raw_data' => $this->safeOperationalData($transfer, $responseData)];
        if (is_string($reference) && trim($reference) !== '') {
            $fields['external_transfer_id'] = $reference;
        }
        if (is_string($payment) && trim($payment) !== '') {
            $fields['external_payment_id'] = $payment;
        }
        if ((! $response->successful() && ! $response->clientError())
            || in_array($response->status(), [408, 409, 425, 429], true) || $response->serverError()
            || ($response->successful() && ! isset($fields['external_transfer_id']))) {
            return $this->recordOutcome($transfer, 'submission_unknown', array_merge($fields, [
                'failure_code' => 'provider_submission_unknown',
                'failure_reason' => 'Provider submission outcome is unknown; do not retry the POST.',
            ]));
        }
        if (! $response->successful()) {
            $message = is_string($responseData['message'] ?? null) ? $responseData['message'] : 'Nium transfer submission failed.';
            $this->recordOutcome($transfer, 'failed', array_merge($fields, [
                'failure_code' => (string) ($responseData['code'] ?? 'provider_error'),
                'failure_reason' => $message,
            ]));
            throw new RuntimeException($message);
        }

        return $this->recordOutcome($transfer, 'pending', array_merge($fields, [
            'submitted_at' => now(), 'provider_status_at' => now(),
        ]));
    }

    private function recordOutcome(Transfer $attempt, string $status, array $fields): Transfer
    {
        return DB::transaction(function () use ($attempt, $status, $fields): Transfer {
            $locked = Transfer::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($locked->provider_operation_key !== $attempt->provider_operation_key
                || ! in_array($locked->status, ['submitting', 'submission_unknown'], true)) {
                // A webhook/reconciliation may already have resolved the operation.
                return $locked->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
            }
            $raw = array_merge($locked->raw_data ?? [], $fields['raw_data'] ?? []);
            $raw['nium_execution'] = $locked->raw_data['nium_execution'] ?? [];
            if ($status === 'failed') {
                $raw['nium_execution']['terminal_status'] = 'failed';
            }
            $locked->update(array_merge($fields, ['status' => $status, 'raw_data' => $raw]));
            $this->ledger->applyTransferTerminalStatus($locked);

            return $locked->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
        });
    }

    public function syncTransferStatus(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        $prepared = $this->prepareTransferStatusSync($provider, $transfer);

        return DB::transaction(function () use ($provider, $transfer, $prepared): Transfer {
            $locked = Transfer::query()
                ->whereKey($transfer->id)
                ->lockForUpdate()
                ->firstOrFail();

            $updated = $this->applyPreparedTransferStatus($provider, $locked, $prepared);
            if (isset($updated->raw_data['nium_execution'])) {
                $this->ledger->applyTransferTerminalStatus($updated);
            }

            return $updated->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
        });
    }

    public function prepareTransferStatusSync(IntegrationProvider $provider, Transfer $transfer): array
    {
        $transfer->loadMissing(['provider', 'user', 'beneficiary', 'sourceBankAccount']);

        if (! filled($transfer->external_transfer_id)) {
            throw new RuntimeException('Nium transfer is missing the system reference number.');
        }

        $response = $this->niumService->get(
            path: $this->niumService->path(
                (string) config('services.nium.transfer_status_endpoint'),
                [
                    'client' => $this->niumService->clientId(),
                    'customer' => $this->niumService->customerId($transfer->user),
                    'wallet' => $this->niumService->walletId($transfer->user),
                    'transfer' => $transfer->external_transfer_id,
                ],
            ),
            user: $transfer->user,
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful()) {
            throw new RuntimeException($responseData['message'] ?? 'Nium transfer status sync failed.');
        }

        $statusPayload = $this->latestStatusPayload($responseData);

        return [
            'operation_key' => $transfer->provider_operation_key,
            'system_reference' => $transfer->external_transfer_id,
            'response_data' => $responseData,
            'status_payload' => $statusPayload,
            'status' => $this->normalizeTransferStatus(
                $statusPayload['status'] ?? $statusPayload['subStatus'] ?? null
            ),
            'status_at' => $this->statusTimestamp($statusPayload),
            'wallet_activity' => NiumPayoutDebitReconciliation::applies($transfer)
                ? app(NiumPayoutDebitReconciliation::class)->prepare($transfer) : null,
        ];
    }

    public function applyPreparedTransferStatus(
        IntegrationProvider $provider,
        Transfer $transfer,
        array $prepared,
    ): Transfer {
        if (array_key_exists('operation_key', $prepared)
            && ($prepared['operation_key'] !== $transfer->provider_operation_key
                || $prepared['system_reference'] !== $transfer->external_transfer_id)) {
            throw new RuntimeException('Reconciliation evidence belongs to another payment operation.');
        }
        $responseData = (array) ($prepared['response_data'] ?? []);
        $statusPayload = (array) ($prepared['status_payload'] ?? []);
        $status = (string) ($prepared['status'] ?? 'pending');
        $statusAt = ($prepared['status_at'] ?? null) instanceof Carbon
            ? $prepared['status_at']
            : null;

        $terminalStatuses = ['completed', 'failed', 'cancelled', 'rejected'];
        $currentIsTerminal = in_array($transfer->status, $terminalStatuses, true);
        $incomingIsTerminal = in_array($status, $terminalStatuses, true);

        $isOlder = $transfer->provider_status_at !== null
            && $statusAt !== null
            && $statusAt->lt($transfer->provider_status_at);

        if ($isOlder || ($currentIsTerminal && ! $incomingIsTerminal)) {
            if (NiumPayoutDebitReconciliation::applies($transfer)) {
                // Financial observations remain independent of delayed lifecycle timestamps.
                app(NiumPayoutDebitReconciliation::class)->apply($transfer, $prepared['wallet_activity'] ?? []);
            }

            return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
        }

        if ($currentIsTerminal && $incomingIsTerminal && $transfer->status !== $status) {
            throw new RuntimeException(
                'Nium transfer status conflicts with an existing terminal status.'
            );
        }

        if ($incomingIsTerminal && isset($transfer->raw_data['nium_execution'])) {
            $raw = $transfer->raw_data;
            $raw['nium_execution']['terminal_status'] = $status;
            $transfer->raw_data = $raw;
        }
        $transfer->update([
            'external_payment_id' => $statusPayload['paymentReferenceNumber']
                ?? $statusPayload['payment_id']
                ?? $transfer->external_payment_id,
            'status' => $status,
            'provider_status' => strtoupper(trim((string) (
                $statusPayload['status']
                ?? $statusPayload['subStatus']
                ?? ''
            ))),
            'provider_status_detail' => $statusPayload['statusDetails'] ?? null,
            'failure_code' => $status === 'failed' ? 'provider_error' : null,
            'failure_reason' => $status === 'failed'
                ? ($statusPayload['remarks'] ?? $responseData['message'] ?? $transfer->failure_reason)
                : null,
            'completed_at' => in_array($status, ['completed', 'failed', 'cancelled'], true)
                ? ($statusPayload['dateTime']
                    ?? $statusPayload['updatedAt']
                    ?? $statusPayload['lastUpdatedAt']
                    ?? $statusPayload['completedAt']
                    ?? now())
                : $transfer->completed_at,
            'provider_status_at' => $statusAt ?? $transfer->provider_status_at,
            'raw_data' => $this->safeOperationalData($transfer, $statusPayload),
        ]);

        if (NiumPayoutDebitReconciliation::applies($transfer)) {
            app(NiumPayoutDebitReconciliation::class)->apply($transfer, $prepared['wallet_activity'] ?? []);
        }

        return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
    }

    private function preparePayoutFxLockIfRequired(
        IntegrationProvider $provider,
        Transfer $transfer,
        array $authoritativePurposeCodes,
    ): ?FxQuote {
        $candidate = Transfer::query()
            ->with([
                'provider',
                'user.kycProfile',
                'beneficiary',
                'sourceBankAccount',
                'fxQuote',
            ])
            ->findOrFail($transfer->id);

        if (! in_array($candidate->status, ['draft', 'approval_required', 'approved'], true)) {
            throw new RuntimeException(
                'Transfer has already entered provider submission and cannot acquire a payout FX lock.'
            );
        }

        if ($candidate->provider_id !== $provider->id) {
            throw new RuntimeException(
                'Transfer provider does not match the Nium submission provider.'
            );
        }

        // This performs local submission/approval/balance validation only.
        // No Nium HTTP is performed here.
        $this->eligibilityService->ensureTransferCanBeSubmitted($candidate);
        $this->policy->assertBeforeFxLock($candidate, $authoritativePurposeCodes);

        $sourceCurrency = strtoupper(trim((string) $candidate->source_currency));
        $targetCurrency = strtoupper(trim((string) $candidate->target_currency));

        if ($sourceCurrency === $targetCurrency) {
            return null;
        }

        // Existing server-side payout FX lock will be validated later.
        if ($candidate->fx_quote_id !== null) {
            return null;
        }

        // Fail closed later in NiumTransferPolicy without making FX HTTP.
        if (! (bool) config('services.nium.payout_fx_enabled', false)) {
            return null;
        }

        if ($sourceCurrency !== NiumTransferPolicy::SOURCE_CURRENCY) {
            throw new RuntimeException(
                'Nium payout FX currently requires USD as the source currency.'
            );
        }

        if ($candidate->beneficiary === null
            || strtoupper(trim((string) $candidate->beneficiary->currency)) !== $targetCurrency) {
            throw new RuntimeException(
                'Nium payout FX beneficiary currency must match the transfer destination currency.'
            );
        }

        return $this->payoutFxLockService->createLock(
            $provider,
            $candidate->user,
            $sourceCurrency,
            $targetCurrency,
            (string) $candidate->source_amount,
        );
    }

    private function attachPreparedPayoutFxLock(
        Transfer $transfer,
        FxQuote $quote,
    ): void {
        if ($transfer->fx_quote_id !== null) {
            throw new RuntimeException(
                'Transfer already has an FX quote and cannot attach another payout FX lock.'
            );
        }

        if ($quote->expires_at === null || $quote->expires_at->lessThanOrEqualTo(now())) {
            throw new RuntimeException(
                'Prepared Nium payout FX lock has expired.'
            );
        }

        if (($quote->raw_data['provider_fx_type'] ?? null) !== 'payout_fx_lock'
            || ! NiumPayoutAuditId::isValid($quote->quote_ref)
            || ! is_numeric($quote->net_rate)
            || (float) $quote->net_rate <= 0
            || $quote->user_id !== $transfer->user_id
            || $quote->provider_id !== $transfer->provider_id
            || strtoupper((string) $quote->source_currency) !== strtoupper((string) $transfer->source_currency)
            || strtoupper((string) $quote->target_currency) !== strtoupper((string) $transfer->target_currency)
            || NiumPayoutFxLockContract::decimal($quote->source_amount) !== NiumPayoutFxLockContract::decimal($transfer->source_amount)) {
            throw new RuntimeException(
                'Prepared Nium payout FX lock does not match the authoritative transfer.'
            );
        }

        NiumPayoutAuditId::fromQuote($quote);

        $transfer->update([
            'fx_quote_id' => $quote->id,
            'fx_rate' => $quote->net_rate,
        ]);

        $transfer->unsetRelation('fxQuote');
        $transfer->load('fxQuote');
    }

    private function buildTransferPayload(Transfer $transfer, array $authoritativePurposeCodes): array
    {
        $payload = array_filter($this->policy->providerPayload($transfer, $authoritativePurposeCodes), static fn ($value) => $value !== null && $value !== '' && $value !== []);
        $this->validateTransferPayload($payload, $transfer);

        return $payload;
    }

    /** @return array{customer: string, wallet: string} */
    private function providerAccountIdentifiers(Transfer $transfer): array
    {
        $providerAccount = UserProviderAccount::query()
            ->where('user_id', $transfer->user_id)
            ->where('provider_id', $transfer->provider_id)
            ->lockForUpdate()
            ->first();

        if (! $providerAccount instanceof UserProviderAccount
            || $providerAccount->status !== 'active'
            || strtolower((string) $providerAccount->provider_status) !== 'clear'
            || $providerAccount->reconciliation_status !== 'reconciled'
            || $providerAccount->security_conflict_at !== null
            || ! filled($providerAccount->external_customer_id)
            || ! filled($providerAccount->external_account_id)
            || $providerAccount->customer_id_verified_at === null
            || $providerAccount->wallet_id_verified_at === null) {
            throw new RuntimeException('Nium provider account is not active, clear, reconciled, and identifier-verified.');
        }

        return [
            'customer' => (string) $providerAccount->external_customer_id,
            'wallet' => (string) $providerAccount->external_account_id,
        ];
    }

    private function validateTransferPayload(array $payload, Transfer $transfer): void
    {
        foreach (['purposeCode', 'sourceOfFunds'] as $field) {
            if (! filled($payload[$field] ?? null)) {
                throw new RuntimeException("Nium transfer requires {$field}.");
            }
        }

        $fields = $transfer->target_currency === 'SGD'
            ? ['sourceAmount', 'sourceCurrency', 'auditId']
            : ['sourceAmount', 'sourceCurrency', 'destinationCurrency', 'payoutMethod'];
        foreach ($fields as $field) {
            if (! filled(Arr::get($payload, "payout.{$field}"))) {
                throw new RuntimeException("Nium transfer payout requires {$field}.");
            }
        }

        if ($transfer->target_currency === 'SGD') {
            foreach (['destinationAmount', 'destinationCurrency', 'payoutMethod', 'swiftFeeType', 'tradeOrderID', 'quoteId'] as $field) {
                if (array_key_exists($field, $payload['payout'])) {
                    throw new RuntimeException('Nium SGD source-funded LOCAL payload contains an unreviewed field.');
                }
            }
        }

        if (strtoupper((string) Arr::get($payload, 'payout.payoutMethod')) === 'SWIFT'
            && ! filled(Arr::get($payload, 'payout.swiftFeeType'))) {
            throw new RuntimeException('Nium SWIFT transfer payout requires swiftFeeType.');
        }
    }

    private function latestStatusPayload(array $responseData): array
    {
        $items = array_is_list($responseData)
            ? $responseData
            : (Arr::get($responseData, 'audit')
            ?? Arr::get($responseData, 'data.audit')
            ?? Arr::get($responseData, 'history')
            ?? Arr::get($responseData, 'data')
            ?? []);

        if (is_array($items) && array_is_list($items) && $items !== []) {
            usort($items, fn (array $left, array $right): int => ($this->statusTimestamp($left)?->getTimestamp() ?? 0) <=> ($this->statusTimestamp($right)?->getTimestamp() ?? 0));

            return is_array(end($items)) ? end($items) : [];
        }

        return is_array($items) ? $items : [];
    }

    private function normalizeTransferStatus(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'PAID', 'SUCCESS', 'SUCCEEDED', 'COMPLETED' => 'completed',
            'FAILED', 'ERROR', 'REJECTED', 'RETURNED' => 'failed',
            'CANCELLED', 'VOIDED' => 'cancelled',
            'PENDING', 'PROCESSING', 'IN_PROGRESS', 'ACCEPTED' => 'pending',
            default => 'pending',
        };
    }

    private function statusTimestamp(array $payload): ?Carbon
    {
        $value = $payload['dateTime'] ?? $payload['updatedAt'] ?? $payload['lastUpdatedAt'] ?? $payload['completedAt'] ?? null;

        try {
            return filled($value) ? Carbon::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function safeOperationalData(Transfer $transfer, array $providerData): array
    {
        $operationalData = array_filter([
            'fx_quote_id' => $transfer->fx_quote_id,
            'quote_ref' => $transfer->fxQuote?->quote_ref ?? ($transfer->raw_data['quote_ref'] ?? null),
            'provider_operation_key' => $transfer->provider_operation_key,
            'provider_request_id' => $providerData['requestId'] ?? $providerData['request_id'] ?? null,
            'provider_error_code' => $providerData['code'] ?? $providerData['errorCode'] ?? null,
            'provider_status' => $providerData['status'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        return array_replace_recursive((array) ($transfer->raw_data ?? []), $operationalData);
    }

    private function ensureAuthoritativeQuote(Transfer $transfer): void
    {
        $quote = $transfer->fxQuote;

        if ($quote === null) {
            return;
        }

        if ($transfer->source_currency === $transfer->target_currency) {
            throw new RuntimeException('Nium exchange-rate lock is not applicable to a same-currency transfer.');
        }

        if ($quote->expires_at === null || $quote->expires_at->lessThanOrEqualTo(now())) {
            throw new RuntimeException('Nium exchange-rate lock has expired.');
        }

        if (($quote->raw_data['provider_fx_type'] ?? null) !== 'payout_fx_lock'
            || ! NiumPayoutAuditId::isValid($quote->quote_ref)
            || ! is_numeric($quote->net_rate)
            || (float) $quote->net_rate <= 0
            || $quote->user_id !== $transfer->user_id
            || $quote->provider_id !== $transfer->provider_id
            || strtoupper($quote->source_currency) !== strtoupper($transfer->source_currency)
            || strtoupper($quote->target_currency) !== strtoupper($transfer->target_currency)
            || NiumPayoutFxLockContract::decimal($quote->source_amount) !== NiumPayoutFxLockContract::decimal($transfer->source_amount)
            || NiumPayoutFxLockContract::decimal($quote->net_rate) !== NiumPayoutFxLockContract::decimal($transfer->fx_rate)) {
            throw new RuntimeException('Nium payout FX lock ownership, corridor, amount, or rate does not match the transfer.');
        }

        NiumPayoutAuditId::fromQuote($quote);
        NiumPayoutFxLockContract::assertMatchesTransfer(
            $quote,
            $transfer,
            $this->payoutFxLockService->context($transfer->provider, $transfer->user),
        );
    }
}
