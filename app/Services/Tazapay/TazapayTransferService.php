<?php

namespace App\Services\Tazapay;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Services\Integrations\Contracts\TransferProvider;
use App\Services\Transfers\TransferEligibilityService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TazapayTransferService implements TransferProvider
{
    public function __construct(
        private readonly TazapayService $tazapayService,
        private readonly TransferEligibilityService $eligibilityService,
    ) {
    }

    public function submitTransfer(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        $this->eligibilityService->ensureTransferCanBeSubmitted(
            $transfer->loadMissing(['provider', 'user', 'beneficiary', 'sourceBankAccount'])
        );

        $payload = $this->buildTransferPayload($transfer);
        $response = $this->tazapayService->post(
            path: (string) config('services.tazapay.payout_endpoint'),
            payload: $payload,
            user: $transfer->user,
            relatedTransferId: $transfer->id,
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];
        $payout = (array) ($responseData['data'] ?? $responseData);

        if (! $response->successful() || ! filled($payout['id'] ?? null)) {
            $transfer->update([
                'status' => 'failed',
                'failure_code' => (string) ($responseData['code'] ?? 'provider_error'),
                'failure_reason' => $responseData['message'] ?? 'Tazapay transfer submission failed.',
                'raw_data' => array_merge($transfer->raw_data ?? [], [
                    'transfer_request' => $payload,
                    'transfer_error' => $responseData,
                ]),
            ]);

            throw new RuntimeException($responseData['message'] ?? 'Tazapay transfer submission failed.');
        }

        return $this->persistTransferState($transfer, $payload, $responseData, true);
    }

    public function syncTransferStatus(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        $transfer->loadMissing(['provider', 'user', 'beneficiary', 'sourceBankAccount']);

        if (! filled($transfer->external_transfer_id)) {
            throw new RuntimeException('Tazapay transfer is missing the external payout id.');
        }

        $path = str_replace(
            '{transfer}',
            urlencode((string) $transfer->external_transfer_id),
            (string) config('services.tazapay.payout_retrieve_endpoint'),
        );

        $response = $this->tazapayService->get(
            path: $path,
            user: $transfer->user,
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful()) {
            throw new RuntimeException($responseData['message'] ?? 'Tazapay transfer status sync failed.');
        }

        return $this->persistTransferState($transfer, null, $responseData, false);
    }

    private function buildTransferPayload(Transfer $transfer): array
    {
        $rawData = (array) ($transfer->raw_data ?? []);
        $tazapay = (array) ($rawData['tazapay'] ?? []);
        $amount = $transfer->target_amount ?? $transfer->source_amount;

        if (! filled($transfer->beneficiary?->external_beneficiary_id)) {
            throw new RuntimeException('Tazapay transfer requires a synced beneficiary.');
        }

        return array_filter([
            'beneficiary' => $transfer->beneficiary->external_beneficiary_id,
            'beneficiary_details' => $tazapay['beneficiary_details'] ?? null,
            'amount' => (float) $amount,
            'currency' => $transfer->target_currency,
            'holding_currency' => $transfer->source_currency,
            'type' => $tazapay['type'] ?? $this->resolveTransferType($transfer),
            'charge_type' => $tazapay['charge_type'] ?? null,
            'purpose' => $transfer->purpose_code,
            'reference_id' => $transfer->client_reference ?: $transfer->transfer_no,
            'transaction_description' => $transfer->reference_text ?: $transfer->transfer_no,
            'statement_descriptor' => $tazapay['statement_descriptor'] ?? null,
            'documents' => $tazapay['documents'] ?? null,
            'local' => $tazapay['local'] ?? null,
            'quote' => $rawData['quote_ref'] ?? $tazapay['quote'] ?? null,
            'logistics_tracking_details' => $tazapay['logistics_tracking_details'] ?? null,
            'on_behalf_of' => $tazapay['on_behalf_of'] ?? null,
            'items' => $tazapay['items'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function persistTransferState(
        Transfer $transfer,
        ?array $requestPayload,
        array $responseData,
        bool $markSubmitted,
    ): Transfer {
        $payout = (array) ($responseData['data'] ?? $responseData);
        $status = $this->normalizeTransferStatus($payout['status'] ?? null);
        $holdingFx = (array) ($payout['holding_fx_transaction'] ?? []);
        $payoutFx = (array) ($payout['payout_fx_transaction'] ?? []);
        $tracking = (array) ($payout['tracking_details'] ?? []);

        return DB::transaction(function () use (
            $transfer,
            $requestPayload,
            $responseData,
            $payout,
            $status,
            $holdingFx,
            $payoutFx,
            $tracking,
            $markSubmitted,
        ): Transfer {
            $transfer->update([
                'external_transfer_id' => $payout['id'] ?? $transfer->external_transfer_id,
                'external_payment_id' => $tracking['tracking_number'] ?? $transfer->external_payment_id,
                'target_amount' => Arr::get($payoutFx, 'final.amount')
                    ?? $payout['amount']
                    ?? $transfer->target_amount,
                'fx_rate' => $holdingFx['exchange_rate']
                    ?? $payoutFx['exchange_rate']
                    ?? $transfer->fx_rate,
                'status' => $status,
                'failure_code' => $status === 'failed'
                    ? (string) Arr::get($payout, 'failure.code', $transfer->failure_code ?? 'provider_error')
                    : null,
                'failure_reason' => $status === 'failed'
                    ? (string) ($payout['status_description'] ?? Arr::get($payout, 'failure.message', $transfer->failure_reason))
                    : null,
                'submitted_at' => $markSubmitted ? now() : $transfer->submitted_at,
                'completed_at' => in_array($status, ['completed', 'failed', 'cancelled'], true)
                    ? ($payout['updated_at'] ?? $payout['created_at'] ?? now())
                    : $transfer->completed_at,
                'raw_data' => array_merge($transfer->raw_data ?? [], [
                    $markSubmitted ? 'transfer_request' : 'transfer_status_request' => $requestPayload,
                    $markSubmitted ? 'transfer_response' : 'transfer_status_response' => $responseData,
                ]),
            ]);

            return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
        });
    }

    private function resolveTransferType(Transfer $transfer): string
    {
        $rawData = (array) ($transfer->beneficiary?->raw_data ?? []);
        $bankTransferType = Arr::get($rawData, 'tazapay.bank.transfer_type');

        if (filled($bankTransferType)) {
            return strtolower((string) $bankTransferType);
        }

        return strtolower((string) $transfer->transfer_type) === 'swift'
            ? 'swift'
            : 'local';
    }

    private function normalizeTransferStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'succeeded', 'success', 'completed', 'paid' => 'completed',
            'reversed', 'returned', 'failed', 'rejected', 'error' => 'failed',
            'cancelled', 'canceled', 'voided' => 'cancelled',
            'pending', 'processing', 'initiated', 'queued' => 'pending',
            default => 'submitted',
        };
    }
}
