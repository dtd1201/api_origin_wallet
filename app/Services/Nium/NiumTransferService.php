<?php

namespace App\Services\Nium;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\UserProviderAccount;
use App\Services\Integrations\Contracts\TransferProvider;
use App\Services\Transfers\TransferEligibilityService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class NiumTransferService implements TransferProvider
{
    public function __construct(
        private readonly NiumService $niumService,
        private readonly TransferEligibilityService $eligibilityService,
    ) {}

    public function submitTransfer(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        $this->eligibilityService->ensureTransferCanBeSubmitted(
            $transfer->loadMissing(['provider', 'user', 'beneficiary', 'sourceBankAccount'])
        );
        $this->ensureAuthoritativeQuote($transfer->loadMissing('fxQuote'));
        $payload = $this->buildTransferPayload($transfer);
        $providerIdentifiers = $this->providerAccountIdentifiers($transfer);

        $transfer = DB::transaction(function () use ($transfer): Transfer {
            $locked = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if (! in_array($locked->status, ['draft', 'approval_required', 'approved'], true)) {
                throw new RuntimeException('Transfer has already entered provider submission and cannot be submitted again.');
            }

            $locked->update([
                'provider_operation_key' => $locked->provider_operation_key ?: 'nium-'.Str::uuid()->toString(),
                'status' => 'submitting',
            ]);

            return $locked->fresh(['provider', 'user', 'beneficiary', 'sourceBankAccount', 'fxQuote']);
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
        } catch (ConnectionException|NiumEvidencePersistenceException) {
            $transfer->update([
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

        if (in_array($response->status(), [408, 429], true) || $response->serverError()) {
            $transfer->update([
                'status' => 'submission_unknown',
                'failure_code' => 'provider_submission_unknown',
                'failure_reason' => 'Provider submission outcome is unknown; do not retry the POST.',
                'raw_data' => $this->safeOperationalData($transfer, $responseData),
            ]);

            return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
        }

        if (! $response->successful()) {
            $transfer->update([
                'status' => 'failed',
                'failure_code' => (string) ($responseData['code'] ?? 'provider_error'),
                'failure_reason' => $responseData['message'] ?? 'Nium transfer submission failed.',
                'raw_data' => $this->safeOperationalData($transfer, $responseData),
            ]);

            throw new RuntimeException($responseData['message'] ?? 'Nium transfer submission failed.');
        }

        if (! filled($systemReferenceNumber)) {
            $transfer->update([
                'status' => 'submission_unknown',
                'failure_code' => 'provider_submission_unknown',
                'failure_reason' => 'Provider accepted the request without an authoritative transfer reference; do not retry the POST.',
                'raw_data' => $this->safeOperationalData($transfer, $responseData),
            ]);

            return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
        }

        return DB::transaction(function () use ($transfer, $responseData, $systemReferenceNumber): Transfer {
            $locked = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if (
                filled($locked->external_transfer_id)
                && $locked->status !== 'submitting'
            ) {
                return $locked->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
            }

            $locked->update([
                'external_transfer_id' => $systemReferenceNumber,
                'external_payment_id' => $responseData['payment_id'] ?? $responseData['paymentId'] ?? $transfer->external_payment_id,
                'status' => 'pending',
                'submitted_at' => now(),
                'provider_status_at' => now(),
                'raw_data' => $this->safeOperationalData($locked, $responseData),
            ]);

            return $locked->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
        });
    }

    public function syncTransferStatus(IntegrationProvider $provider, Transfer $transfer): Transfer
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
        $status = $this->normalizeTransferStatus(
            $statusPayload['status'] ?? $statusPayload['subStatus'] ?? null
        );

        $statusAt = $this->statusTimestamp($statusPayload);
        $isOlder = $transfer->provider_status_at !== null && $statusAt !== null && $statusAt->lt($transfer->provider_status_at);
        $isTerminal = in_array($transfer->status, ['completed', 'failed', 'cancelled'], true);

        if ($isOlder || ($isTerminal && ! in_array($status, ['completed', 'failed', 'cancelled'], true))) {
            return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
        }

        $transfer->update([
            'external_payment_id' => $statusPayload['paymentReferenceNumber'] ?? $statusPayload['payment_id'] ?? $transfer->external_payment_id,
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
                ? ($statusPayload['dateTime'] ?? $statusPayload['updatedAt'] ?? $statusPayload['lastUpdatedAt'] ?? $statusPayload['completedAt'] ?? now())
                : $transfer->completed_at,
            'provider_status_at' => $statusAt ?? $transfer->provider_status_at,
            'raw_data' => $this->safeOperationalData($transfer, $statusPayload),
        ]);

        return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
    }

    private function buildTransferPayload(Transfer $transfer): array
    {
        $rawData = (array) ($transfer->raw_data ?? []);
        $nium = (array) ($rawData['nium'] ?? []);
        $beneficiaryNium = (array) (($transfer->beneficiary?->raw_data ?? [])['nium'] ?? []);
        $payoutMethod = strtoupper(trim((string) (
            Arr::get($nium, 'payout.payoutMethod')
            ?? $nium['payoutMethod']
            ?? $nium['payout_method']
            ?? $beneficiaryNium['payoutMethod']
            ?? $beneficiaryNium['payout_method']
            ?? ''
        )));

        if (! filled($transfer->beneficiary?->external_beneficiary_id)) {
            throw new RuntimeException('Nium transfer requires a synced beneficiary.');
        }

        $payload = [
            'beneficiary' => [
                'id' => $transfer->beneficiary->external_beneficiary_id,
            ],
            'payout' => array_filter([
                'sourceAmount' => (float) $transfer->source_amount,
                'sourceCurrency' => $transfer->source_currency,
                'destinationAmount' => $transfer->target_amount !== null ? (float) $transfer->target_amount : null,
                'destinationCurrency' => $transfer->target_currency,
                'payoutMethod' => $payoutMethod,
                'auditId' => $transfer->fxQuote?->quote_ref !== null ? (int) $transfer->fxQuote->quote_ref : null,
                'scheduledPayoutDate' => $nium['payout']['scheduledPayoutDate'] ?? null,
                'serviceTime' => $nium['payout']['serviceTime'] ?? null,
                'tradeOrderID' => $nium['payout']['tradeOrderID'] ?? $transfer->reference_text,
                'swiftFeeType' => $nium['payout']['swiftFeeType'] ?? null,
                'preScreening' => $nium['payout']['preScreening'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''),
            'purposeCode' => $transfer->purpose_code,
            'sourceOfFunds' => $nium['sourceOfFunds'] ?? $nium['source_of_funds'] ?? null,
            'exemptionCode' => $nium['exemptionCode'] ?? $nium['exemption_code'] ?? null,
            'customerComments' => $transfer->reference_text,
            'ownPayment' => $nium['ownPayment'] ?? null,
            'authenticationCode' => $nium['authenticationCode'] ?? null,
            'deviceDetails' => $nium['deviceDetails'] ?? null,
        ];

        if (isset($nium['request']) && is_array($nium['request'])) {
            $payload = array_replace_recursive($payload, $nium['request']);
        }

        if (strtoupper(trim((string) $transfer->source_currency)) === strtoupper(trim((string) $transfer->target_currency))) {
            unset($payload['payout']['destinationAmount']);
        }

        $payload = array_filter($payload, static fn ($value) => $value !== null && $value !== '' && $value !== []);
        $this->validateTransferPayload($payload);

        return $payload;
    }

    /** @return array{customer: string, wallet: string} */
    private function providerAccountIdentifiers(Transfer $transfer): array
    {
        $providerAccount = UserProviderAccount::query()
            ->where('user_id', $transfer->user_id)
            ->where('provider_id', $transfer->provider_id)
            ->first();

        if (! $providerAccount instanceof UserProviderAccount
            || ! filled($providerAccount->external_customer_id)
            || ! filled($providerAccount->external_account_id)) {
            throw new RuntimeException('Nium transfer requires customer and wallet identifiers from the provider account.');
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

        if ($quote === null) {
            return;
        }

        if ($transfer->source_currency === $transfer->target_currency) {
            throw new RuntimeException('Nium exchange-rate lock is not applicable to a same-currency transfer.');
        }

        if ($quote->expires_at === null || $quote->expires_at->isPast()) {
            throw new RuntimeException('Nium exchange-rate lock has expired.');
        }

        if (($quote->raw_data['provider_fx_type'] ?? null) !== 'lock_and_hold'
            || ! is_numeric($quote->quote_ref)
            || $quote->user_id !== $transfer->user_id
            || $quote->provider_id !== $transfer->provider_id
            || strtoupper($quote->source_currency) !== strtoupper($transfer->source_currency)
            || strtoupper($quote->target_currency) !== strtoupper($transfer->target_currency)
            || number_format((float) $quote->source_amount, 8, '.', '') !== number_format((float) $transfer->source_amount, 8, '.', '')) {
            throw new RuntimeException('Nium FX quote ownership, corridor, or amount does not match the transfer.');
        }
    }
}
