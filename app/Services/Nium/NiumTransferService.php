<?php

namespace App\Services\Nium;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Services\Integrations\Contracts\TransferProvider;
use App\Services\Transfers\TransferEligibilityService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NiumTransferService implements TransferProvider
{
    public function __construct(
        private readonly NiumService $niumService,
        private readonly TransferEligibilityService $eligibilityService,
    ) {
    }

    public function submitTransfer(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        $this->eligibilityService->ensureTransferCanBeSubmitted(
            $transfer->loadMissing(['provider', 'user', 'beneficiary', 'sourceBankAccount'])
        );

        $payload = $this->buildTransferPayload($transfer);
        $response = $this->niumService->post(
            path: $this->niumService->path(
                (string) config('services.nium.transfer_endpoint'),
                [
                    'client' => $this->niumService->clientId(),
                    'customer' => $this->niumService->customerId($transfer->user),
                    'wallet' => $this->niumService->walletId($transfer->user),
                ],
            ),
            payload: $payload,
            user: $transfer->user,
            relatedTransferId: $transfer->id,
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful() || ! filled($responseData['system_reference_number'] ?? $responseData['systemReferenceNumber'] ?? null)) {
            $transfer->update([
                'status' => 'failed',
                'failure_code' => (string) ($responseData['code'] ?? 'provider_error'),
                'failure_reason' => $responseData['message'] ?? 'Nium transfer submission failed.',
                'raw_data' => array_merge($transfer->raw_data ?? [], [
                    'payment_request' => $payload,
                    'payment_error' => $responseData,
                ]),
            ]);

            throw new RuntimeException($responseData['message'] ?? 'Nium transfer submission failed.');
        }

        return DB::transaction(function () use ($transfer, $payload, $responseData): Transfer {
            $transfer->update([
                'external_transfer_id' => $responseData['system_reference_number'] ?? $responseData['systemReferenceNumber'] ?? $transfer->external_transfer_id,
                'external_payment_id' => $responseData['payment_id'] ?? $responseData['paymentId'] ?? $transfer->external_payment_id,
                'status' => 'pending',
                'submitted_at' => now(),
                'raw_data' => array_merge($transfer->raw_data ?? [], [
                    'payment_request' => $payload,
                    'payment_response' => $responseData,
                ]),
            ]);

            return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
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

        $transfer->update([
            'external_payment_id' => $statusPayload['paymentReferenceNumber'] ?? $statusPayload['payment_id'] ?? $transfer->external_payment_id,
            'status' => $status,
            'failure_code' => $status === 'failed' ? 'provider_error' : null,
            'failure_reason' => $status === 'failed'
                ? ($statusPayload['remarks'] ?? $responseData['message'] ?? $transfer->failure_reason)
                : null,
            'completed_at' => in_array($status, ['completed', 'failed', 'cancelled'], true)
                ? ($statusPayload['dateTime'] ?? $statusPayload['updatedAt'] ?? now())
                : $transfer->completed_at,
            'raw_data' => array_merge($transfer->raw_data ?? [], [
                'payment_status_response' => $responseData,
            ]),
        ]);

        return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
    }

    private function buildTransferPayload(Transfer $transfer): array
    {
        $rawData = (array) ($transfer->raw_data ?? []);
        $nium = (array) ($rawData['nium'] ?? []);

        if (! filled($transfer->beneficiary?->external_beneficiary_id)) {
            throw new RuntimeException('Nium transfer requires a synced beneficiary.');
        }

        $payload = [
            'beneficiary' => [
                'id' => $transfer->beneficiary->external_beneficiary_id,
            ],
            'payout' => array_filter([
                'source_amount' => (float) $transfer->source_amount,
                'source_currency' => $transfer->source_currency,
                'destination_amount' => $transfer->target_amount !== null ? (float) $transfer->target_amount : null,
                'scheduledPayoutDate' => $nium['payout']['scheduledPayoutDate'] ?? null,
                'serviceTime' => $nium['payout']['serviceTime'] ?? null,
                'tradeOrderID' => $rawData['quote_ref'] ?? ($nium['payout']['tradeOrderID'] ?? null),
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

        return array_filter($payload, static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function latestStatusPayload(array $responseData): array
    {
        $items = Arr::get($responseData, 'audit')
            ?? Arr::get($responseData, 'data.audit')
            ?? Arr::get($responseData, 'history')
            ?? Arr::get($responseData, 'data')
            ?? [];

        if (is_array($items) && array_is_list($items) && $items !== []) {
            $last = end($items);

            return is_array($last) ? $last : [];
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
            default => 'submitted',
        };
    }
}
