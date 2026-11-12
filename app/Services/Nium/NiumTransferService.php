<?php

namespace App\Services\Nium;

use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\UserProviderAccount;
use App\Services\Integrations\Contracts\PreparedTransferStatusProvider;
use App\Services\Transfers\TransferEligibilityService;
use App\Services\Wallet\LedgerService;
use Illuminate\Http\Client\ConnectionException;
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
    ) {}

    public function submitTransfer(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        OwnerFirst::outside();
        try {
            [$transfer, $payload, $providerIdentifiers] = $this->prepareSubmission($provider, $transfer);
        } catch (Throwable $exception) {
            // No remittance claim from this invocation was committed and no POST
            // was reached. Cleanup still must fence a different request's claim.
            throw new NiumSubmissionPreflightException($exception);
        }

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
                beforeDispatch: NiumTransferReviewService::applies($transfer) ? function () use ($transfer): void {
                    try {
                        app(NiumTransferReviewService::class)->assertAccepted(
                            $transfer->fresh(['provider', 'user', 'beneficiary', 'fxQuote']),
                        );
                    } catch (RuntimeException $exception) {
                        throw new NiumPayoutPreDispatchException($exception->getMessage(), previous: $exception);
                    }
                } : null,
            );
        } catch (NiumRemittanceDispatchHeldException $exception) {
            $exception->noWireOperationKey = $transfer->provider_operation_key;
            // This transport rejection guarantees no remittance POST was sent.
            // Preserve the operation key, approval, FX and provider event evidence.
            $this->persistSubmissionOutcome($transfer, fn (Transfer $transfer): array => [
                'status' => 'failed',
                'failure_code' => 'remittance_dispatch_held',
                'failure_reason' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            throw $exception;
        } catch (NiumPayoutPreDispatchException $exception) {
            $exception->noWireOperationKey = $transfer->provider_operation_key;
            $this->persistSubmissionOutcome($transfer, fn (Transfer $transfer): array => [
                'status' => 'failed', 'failure_code' => 'payout_terms_expired_or_changed',
                'failure_reason' => 'No payout was sent. Obtain a new quote and customer authorization.',
                'completed_at' => now(),
            ]);

            throw $exception;
        } catch (ConnectionException|NiumEvidencePersistenceException) {
            $this->persistSubmissionOutcome($transfer, fn (Transfer $transfer): array => [
                'status' => 'submission_unknown',
                'failure_code' => 'provider_submission_unknown',
                'failure_reason' => 'Provider submission outcome is unknown; do not retry the POST.',
            ]);

            return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
        }

        $responseData = $response->json() ?? ['raw' => $response->body()];
        $systemReferenceNumber = $responseData['system_reference_number']
            ?? $responseData['systemReferenceNumber']
            ?? null;

        $paymentId = $responseData['payment_id']
            ?? $responseData['paymentId']
            ?? null;

        if (in_array($response->status(), [408, 429], true) || $response->serverError()) {
            $this->persistSubmissionOutcome($transfer, fn (Transfer $transfer): array => [
                'external_transfer_id' => filled($systemReferenceNumber)
                    ? $systemReferenceNumber
                    : $transfer->external_transfer_id,
                'external_payment_id' => filled($paymentId)
                    ? $paymentId
                    : $transfer->external_payment_id,
                'status' => 'submission_unknown',
                'failure_code' => 'provider_submission_unknown',
                'failure_reason' => 'Provider submission outcome is unknown; do not retry the POST.',
                'raw_data' => $this->safeOperationalData($transfer, $responseData),
            ]);

            return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
        }

        if (! $response->successful()) {
            $this->persistSubmissionOutcome($transfer, fn (Transfer $transfer): array => [
                'status' => 'failed',
                'failure_code' => (string) ($responseData['code'] ?? 'provider_error'),
                'failure_reason' => $responseData['message'] ?? 'Nium transfer submission failed.',
                'raw_data' => $this->safeOperationalData($transfer, $responseData),
            ]);

            throw new RuntimeException($responseData['message'] ?? 'Nium transfer submission failed.');
        }

        if (! filled($systemReferenceNumber)) {
            $this->persistSubmissionOutcome($transfer, fn (Transfer $transfer): array => [
                'external_payment_id' => filled($paymentId)
                    ? $paymentId
                    : $transfer->external_payment_id,
                'status' => 'submission_unknown',
                'failure_code' => 'provider_submission_unknown',
                'failure_reason' => 'Provider accepted the request without an authoritative transfer reference; do not retry the POST.',
                'raw_data' => $this->safeOperationalData($transfer, $responseData),
            ]);

            return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
        }

        return OwnerFirst::transfer($transfer, function () use ($transfer, $responseData, $systemReferenceNumber, $paymentId): Transfer {
            $locked = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);

            $this->assertSubmissionKey($locked, $transfer);
            if (! in_array($locked->status, ['submitting', 'submission_unknown'], true)) {
                return $locked->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
            }

            if (
                filled($locked->external_transfer_id)
                && $locked->status !== 'submitting'
            ) {
                return $locked->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
            }

            if (! $locked->update([
                'external_transfer_id' => $systemReferenceNumber,
                'external_payment_id' => filled($paymentId)
                    ? $paymentId
                    : $transfer->external_payment_id,
                'status' => 'pending',
                'submitted_at' => now(),
                'provider_status_at' => now(),
                'raw_data' => $this->safeOperationalData($locked, $responseData),
            ])) throw new RuntimeException('Submission result was not persisted.');

            return $locked->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
        });
    }

    private function assertSubmissionKey(Transfer $locked, Transfer $claim): void
    {
        if (! is_string($claim->provider_operation_key) || $claim->provider_operation_key === ''
            || $locked->provider_operation_key !== $claim->provider_operation_key) {
            throw new \App\Services\Wallet\Concurrency\OwnerBindingChanged('Submission result does not own the committed claim.');
        }
    }

    private function persistSubmissionOutcome(Transfer $claim, \Closure $fields): Transfer
    {
        return OwnerFirst::transfer($claim, function () use ($claim, $fields): Transfer {
            $locked = Transfer::query()->lockForUpdate()->findOrFail($claim->id);
            $this->assertSubmissionKey($locked, $claim);
            if (in_array($locked->status, ['submitting', 'submission_unknown'], true)
                && ! $locked->update($fields($locked))) {
                throw new RuntimeException('Submission outcome was not persisted.');
            }

            return $locked;
        });
    }

    /** Provider reads remain outside the locked claim transaction. */
    private function prepareSubmission(IntegrationProvider $provider, Transfer $transfer): array
    {
        OwnerFirst::outside();
        $authoritativePurposeCodes = $this->purposeCodes->supported($transfer->user()->firstOrFail());

        // Provider I/O must remain outside the transfer row-lock transaction.
        $preparedFxQuote = $this->preparePayoutFxLockIfRequired($provider, $transfer, $authoritativePurposeCodes);

        return OwnerFirst::transfer($transfer, function () use (
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
            if (NiumTransferReviewService::applies($locked)
                && (filled($locked->provider_operation_key) || filled($locked->external_transfer_id) || filled($locked->external_payment_id))) {
                throw new RuntimeException('This SGD payout has already entered dispatch. Reconcile its saved outcome; do not submit again.');
            }
            $this->eligibilityService->ensureTransferCanBeSubmitted($locked);

            $providerIdentifiers = $this->providerAccountIdentifiers($locked);
            IntegrationProvider::query()->whereKey($locked->provider_id)->lock('for key share')->firstOrFail();
            \App\Models\Balance::query()->where('user_id', $locked->user_id)->where('provider_id', $locked->provider_id)
                ->where('external_account_id', $providerIdentifiers['wallet'])->where('currency', strtoupper($locked->source_currency))
                ->lockForUpdate()->firstOrFail();
            if ($preparedFxQuote !== null) {
                $this->attachPreparedPayoutFxLock($locked, $preparedFxQuote);
            }

            $this->ensureAuthoritativeQuote($locked);
            $this->policy->assertTransfer($locked, $authoritativePurposeCodes);
            if (NiumTransferReviewService::applies($locked)) {
                app(NiumTransferReviewService::class)->assertReserved($locked);
            }
            app(LedgerService::class)->assertTransferReserved($locked, $providerIdentifiers['wallet']);
            $payload = $this->buildTransferPayload($locked, $authoritativePurposeCodes);

            if (! $locked->update([
                'provider_operation_key' => $locked->provider_operation_key ?: 'nium-'.Str::uuid()->toString(),
                'status' => 'submitting',
            ])) {
                throw new RuntimeException('Nium submission claim was not persisted; no payout was sent.');
            }

            return [
                $locked->fresh(['provider', 'user', 'beneficiary', 'sourceBankAccount', 'fxQuote']),
                $payload,
                $providerIdentifiers,
            ];
        });
    }

    public function syncTransferStatus(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        OwnerFirst::outside();
        $prepared = $this->prepareTransferStatusSync($provider, $transfer);

        return OwnerFirst::transfer($transfer, function () use ($provider, $transfer, $prepared): Transfer {
            $locked = Transfer::query()
                ->whereKey($transfer->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->applyPreparedTransferStatus($provider, $locked, $prepared);
        });
    }

    public function prepareTransferStatusSync(IntegrationProvider $provider, Transfer $transfer): array
    {
        OwnerFirst::outside();
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
            'response_data' => $responseData,
            'status_payload' => $statusPayload,
            'status' => $this->normalizeTransferStatus(
                $statusPayload['status'] ?? $statusPayload['subStatus'] ?? null
            ),
            'status_at' => $this->statusTimestamp($statusPayload),
        ];
    }

    public function applyPreparedTransferStatus(
        IntegrationProvider $provider,
        Transfer $transfer,
        array $prepared,
    ): Transfer {
        OwnerFirst::current([$transfer->user_id]);
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
            return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
        }

        if ($currentIsTerminal && $incomingIsTerminal && $transfer->status !== $status) {
            throw new RuntimeException(
                'Nium transfer status conflicts with an existing terminal status.'
            );
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

        if ($targetCurrency === 'SGD') {
            // Customer accepted this payout quote. Admin dispatch may validate it,
            // but must never silently acquire replacement terms.
            app(NiumTransferReviewService::class)->assertAccepted($candidate);

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
        $this->validateTransferPayload($payload);

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

    private function validateTransferPayload(array $payload): void
    {
        foreach (['purposeCode', 'sourceOfFunds'] as $field) {
            if (! filled($payload[$field] ?? null)) {
                throw new RuntimeException("Nium transfer requires {$field}.");
            }
        }

        foreach (['sourceAmount', 'sourceCurrency', 'destinationCurrency', 'payoutMethod'] as $field) {
            if (! filled(Arr::get($payload, "payout.{$field}"))) {
                throw new RuntimeException("Nium transfer payout requires {$field}.");
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
        } catch (\Throwable) {
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

        if (NiumTransferReviewService::applies($transfer)) {
            app(NiumTransferReviewService::class)->assertAccepted($transfer);

            return;
        }

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
