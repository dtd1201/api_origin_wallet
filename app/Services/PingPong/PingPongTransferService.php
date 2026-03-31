<?php

namespace App\Services\PingPong;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Services\Integrations\Contracts\TransferProvider;
use App\Services\Transfers\TransferEligibilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PingPongTransferService implements TransferProvider
{
    public function __construct(
        private readonly PingPongService $pingPongService,
        private readonly TransferEligibilityService $eligibilityService,
    ) {
    }

    public function submitTransfer(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        $this->eligibilityService->ensureTransferCanBeSubmitted(
            $transfer->loadMissing(['provider', 'user', 'beneficiary', 'sourceBankAccount'])
        );

        $payload = $this->buildPaymentPayload($transfer);
        $response = $this->pingPongService->createPayment(
            payload: $payload,
            user: $transfer->user,
        );
        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful() || strtoupper((string) ($responseData['code'] ?? '')) !== 'SUCCESS') {
            $transfer->update([
                'status' => 'failed',
                'failure_code' => (string) ($responseData['code'] ?? 'provider_error'),
                'failure_reason' => $responseData['message'] ?? 'PingPong transfer submission failed.',
                'raw_data' => array_merge($transfer->raw_data ?? [], [
                    'payment_request' => $payload,
                    'payment_error' => $responseData,
                ]),
            ]);

            throw new RuntimeException('PingPong transfer submission failed.');
        }

        $paymentData = (array) ($responseData['data'] ?? []);

        return DB::transaction(function () use ($transfer, $payload, $responseData, $paymentData): Transfer {
            $transfer->update([
                'external_transfer_id' => $paymentData['order_id'] ?? $transfer->external_transfer_id,
                'external_payment_id' => $paymentData['order_id'] ?? $transfer->external_payment_id,
                'status' => $this->normalizeTransferStatus($paymentData['payout_status'] ?? null),
                'failure_code' => null,
                'failure_reason' => null,
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

        $query = array_filter([
            'order_id' => $transfer->external_payment_id ?: $transfer->external_transfer_id,
            'partner_order_id' => $transfer->transfer_no,
        ], static fn ($value) => $value !== null && $value !== '');

        if ($query === []) {
            throw new RuntimeException('PingPong transfer is missing identifiers required for status sync.');
        }

        $response = $this->pingPongService->queryPayment(
            query: $query,
            user: $transfer->user,
        );
        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful() || strtoupper((string) ($responseData['code'] ?? '')) !== 'SUCCESS') {
            throw new RuntimeException($responseData['message'] ?? 'PingPong transfer status sync failed.');
        }

        $paymentData = (array) ($responseData['data'] ?? []);
        $status = $this->normalizeTransferStatus($paymentData['status'] ?? null);

        $transfer->update([
            'external_transfer_id' => $paymentData['order_id'] ?? $transfer->external_transfer_id,
            'external_payment_id' => $paymentData['order_id'] ?? $transfer->external_payment_id,
            'status' => $status,
            'failure_code' => $status === 'failed' ? 'provider_error' : null,
            'failure_reason' => $status === 'failed' ? ($responseData['message'] ?? $transfer->failure_reason) : null,
            'completed_at' => in_array($status, ['completed', 'failed'], true)
                ? $this->timestampFromMilliseconds($paymentData['finished'] ?? null) ?? now()
                : $transfer->completed_at,
            'raw_data' => array_merge($transfer->raw_data ?? [], [
                'payment_status_response' => $responseData,
            ]),
        ]);

        return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
    }

    private function buildPaymentPayload(Transfer $transfer): array
    {
        $rawData = (array) ($transfer->raw_data ?? []);
        $pingPong = (array) ($rawData['pingpong'] ?? []);
        $isCrossCurrency = $transfer->source_currency !== $transfer->target_currency;

        $payload = array_filter([
            'biz_id' => $transfer->sourceBankAccount?->external_account_id,
            'payout_type' => $pingPong['payout_type'] ?? 'PAYOUT',
            'purpose_code' => $transfer->purpose_code,
            'origin_currency' => $transfer->source_currency,
            'target_currency' => $transfer->target_currency,
            'rate_id' => $isCrossCurrency ? ($rawData['rate_id'] ?? $rawData['quote_ref'] ?? $pingPong['rate_id'] ?? null) : null,
            'partner_order_id' => $transfer->transfer_no,
            'partner_user_id' => (string) $transfer->user_id,
            'reference' => $transfer->reference_text,
            'to_account_id' => $transfer->beneficiary?->external_beneficiary_id,
            'use_pobo' => $pingPong['use_pobo'] ?? null,
            'pobo_id' => $pingPong['pobo_id'] ?? null,
            'payment_method' => $pingPong['payment_method'] ?? null,
            'clearing_network' => $pingPong['clearing_network'] ?? null,
            'fee_bear' => $pingPong['fee_bear'] ?? null,
            'middle_bank_code' => $pingPong['middle_bank_code'] ?? null,
            'document' => $pingPong['document'] ?? null,
            'order_note' => $pingPong['order_note'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        if ($isCrossCurrency && $transfer->target_amount !== null) {
            $payload['target_amount'] = (float) $transfer->target_amount;
        } else {
            $payload['origin_amount'] = (float) $transfer->source_amount;
        }

        return $payload;
    }

    private function normalizeTransferStatus(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'SUCCESS' => 'completed',
            'FAIL' => 'failed',
            'PENDING' => 'pending',
            default => 'submitted',
        };
    }

    private function timestampFromMilliseconds(mixed $value): ?Carbon
    {
        if (! is_numeric($value)) {
            return null;
        }

        return Carbon::createFromTimestampMs((int) $value);
    }
}
