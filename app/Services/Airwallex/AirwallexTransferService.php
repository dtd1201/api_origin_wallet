<?php

namespace App\Services\Airwallex;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Services\Integrations\Contracts\TransferProvider;
use App\Services\Transfers\TransferEligibilityService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AirwallexTransferService implements TransferProvider
{
    public function __construct(
        private readonly AirwallexService $airwallexService,
        private readonly TransferEligibilityService $eligibilityService,
    ) {
    }

    public function submitTransfer(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        $this->eligibilityService->ensureTransferCanBeSubmitted(
            $transfer->loadMissing(['provider', 'user', 'beneficiary', 'sourceBankAccount'])
        );

        $payload = $this->buildTransferPayload($transfer);
        $response = $this->airwallexService->post(
            path: (string) config('services.airwallex.transfer_endpoint'),
            payload: $payload,
            user: $transfer->user,
            relatedTransferId: $transfer->id,
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful()) {
            $transfer->update([
                'status' => 'failed',
                'failure_code' => (string) ($responseData['code'] ?? 'provider_error'),
                'failure_reason' => $responseData['message'] ?? 'Airwallex transfer submission failed.',
                'raw_data' => array_merge($transfer->raw_data ?? [], [
                    'transfer_request' => $payload,
                    'transfer_error' => $responseData,
                ]),
            ]);

            throw new RuntimeException($responseData['message'] ?? 'Airwallex transfer submission failed.');
        }

        return $this->persistTransferState($transfer, $payload, $responseData, true);
    }

    public function syncTransferStatus(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        $transfer->loadMissing(['provider', 'user', 'beneficiary', 'sourceBankAccount']);

        $externalTransferId = $transfer->external_transfer_id ?: $transfer->external_payment_id;

        if (! filled($externalTransferId)) {
            throw new RuntimeException('Airwallex transfer is missing identifiers required for status sync.');
        }

        $endpoint = str_replace(
            '{transfer}',
            urlencode((string) $externalTransferId),
            (string) config('services.airwallex.transfer_retrieve_endpoint')
        );

        $response = $this->airwallexService->get(
            path: $endpoint,
            user: $transfer->user,
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful()) {
            throw new RuntimeException($responseData['message'] ?? 'Airwallex transfer status sync failed.');
        }

        return $this->persistTransferState($transfer, null, $responseData, false);
    }

    private function buildTransferPayload(Transfer $transfer): array
    {
        $rawData = (array) ($transfer->raw_data ?? []);
        $payload = array_filter([
            'beneficiary_id' => $transfer->beneficiary?->external_beneficiary_id,
            'transfer_currency' => $transfer->target_currency,
            'transfer_method' => strtoupper((string) ($rawData['airwallex']['transfer_method'] ?? $rawData['transfer_method'] ?? 'LOCAL')),
            'reason' => $transfer->purpose_code,
            'reference' => $transfer->reference_text ?: $transfer->transfer_no,
            'request_id' => $transfer->transfer_no ?: (string) Str::uuid(),
            'source_currency' => $transfer->source_currency,
            'quote_id' => $rawData['quote_ref'] ?? null,
            'transfer_date' => $rawData['airwallex']['transfer_date'] ?? null,
            'lock_rate_on_create' => $transfer->source_currency !== $transfer->target_currency
                ? (bool) ($rawData['airwallex']['lock_rate_on_create'] ?? true)
                : null,
            'fee_paid_by' => strtoupper((string) ($rawData['airwallex']['fee_paid_by'] ?? '')),
        ], static fn ($value) => $value !== null && $value !== '');

        if (! filled($payload['beneficiary_id'])) {
            throw new RuntimeException('Airwallex transfer requires a synced beneficiary.');
        }

        if ($transfer->source_currency !== $transfer->target_currency) {
            if ($transfer->target_amount !== null) {
                $payload['transfer_amount'] = (string) $transfer->target_amount;
            } else {
                $payload['source_amount'] = (string) $transfer->source_amount;
            }
        } else {
            $payload['transfer_amount'] = (string) $transfer->source_amount;
        }

        return $payload;
    }

    private function persistTransferState(
        Transfer $transfer,
        ?array $requestPayload,
        array $responseData,
        bool $markSubmitted,
    ): Transfer {
        return DB::transaction(function () use ($transfer, $requestPayload, $responseData, $markSubmitted): Transfer {
            $status = $this->normalizeTransferStatus($responseData['status'] ?? null);

            $transfer->update([
                'external_transfer_id' => $responseData['id'] ?? $responseData['transfer_id'] ?? $transfer->external_transfer_id,
                'external_payment_id' => $responseData['payment_id'] ?? $transfer->external_payment_id,
                'target_amount' => $responseData['transfer_amount']
                    ?? $responseData['amount_beneficiary_receives']
                    ?? $transfer->target_amount,
                'fx_rate' => $responseData['client_rate']
                    ?? Arr::get($responseData, 'conversion.client_rate')
                    ?? $transfer->fx_rate,
                'fee_amount' => $responseData['fee_amount']
                    ?? Arr::get($responseData, 'fees.total')
                    ?? $transfer->fee_amount,
                'fee_currency' => $responseData['fee_currency'] ?? $transfer->fee_currency,
                'status' => $status,
                'failure_code' => $status === 'failed' ? (string) ($responseData['code'] ?? 'provider_error') : null,
                'failure_reason' => $status === 'failed' ? ($responseData['message'] ?? $transfer->failure_reason) : null,
                'submitted_at' => $markSubmitted ? now() : $transfer->submitted_at,
                'completed_at' => in_array($status, ['completed', 'failed', 'cancelled'], true)
                    ? ($responseData['dispatch_date'] ?? $responseData['completed_at'] ?? now())
                    : $transfer->completed_at,
                'raw_data' => array_merge($transfer->raw_data ?? [], [
                    $markSubmitted ? 'transfer_request' : 'transfer_status_request' => $requestPayload,
                    $markSubmitted ? 'transfer_response' : 'transfer_status_response' => $responseData,
                ]),
            ]);

            return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
        });
    }

    private function normalizeTransferStatus(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'PAID', 'SENT', 'SUCCEEDED', 'SUCCESS', 'COMPLETED' => 'completed',
            'FAILED', 'FAIL', 'REJECTED', 'RETURNED' => 'failed',
            'CANCELLED', 'VOIDED' => 'cancelled',
            'IN_APPROVAL', 'AWAITING_FUNDING', 'PROCESSING', 'PENDING', 'SCHEDULED' => 'pending',
            default => 'submitted',
        };
    }
}
