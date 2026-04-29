<?php

namespace App\Services\Unlimit;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Services\Integrations\Contracts\TransferProvider;
use App\Services\Transfers\TransferEligibilityService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UnlimitTransferService implements TransferProvider
{
    public function __construct(
        private readonly UnlimitService $unlimitService,
        private readonly UnlimitPayoutPayloadFactory $payloadFactory,
        private readonly TransferEligibilityService $eligibilityService,
    ) {
    }

    public function submitTransfer(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        $this->eligibilityService->ensureTransferCanBeSubmitted(
            $transfer->loadMissing(['provider', 'user', 'beneficiary', 'sourceBankAccount'])
        );

        $payload = $this->payloadFactory->payoutPayload($transfer);
        $this->assertPayoutPayloadIsReady($payload);

        $response = $this->unlimitService->post(
            path: (string) config('services.unlimit.payout_endpoint'),
            payload: $payload,
            user: $transfer->user,
            relatedTransferId: $transfer->id,
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];
        $payoutId = Arr::get($responseData, 'payout_data.id');

        if (! $response->successful() || ! filled($payoutId)) {
            $transfer->update([
                'status' => 'failed',
                'failure_code' => (string) ($responseData['name'] ?? 'provider_error'),
                'failure_reason' => $responseData['message'] ?? 'Unlimit payout submission failed.',
                'raw_data' => array_merge($transfer->raw_data ?? [], [
                    'payout_request' => $payload,
                    'payout_error' => $responseData,
                ]),
            ]);

            throw new RuntimeException($responseData['message'] ?? 'Unlimit payout submission failed.');
        }

        return $this->persistTransferState($transfer, $payload, $responseData, true);
    }

    public function syncTransferStatus(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        $transfer->loadMissing(['provider', 'user', 'beneficiary', 'sourceBankAccount']);

        if (! filled($transfer->external_transfer_id)) {
            throw new RuntimeException('Unlimit transfer is missing the payout id.');
        }

        $response = $this->unlimitService->get(
            path: $this->unlimitService->path(
                (string) config('services.unlimit.payout_retrieve_endpoint'),
                ['payout' => $transfer->external_transfer_id],
            ),
            user: $transfer->user,
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful()) {
            throw new RuntimeException($responseData['message'] ?? 'Unlimit payout status sync failed.');
        }

        return $this->persistTransferState($transfer, null, $responseData, false);
    }

    private function assertPayoutPayloadIsReady(array $payload): void
    {
        if (! filled($payload['payment_method'] ?? null)) {
            throw new RuntimeException('Unlimit transfer requires raw_data.unlimit.payment_method.');
        }

        if (empty($payload['card_account']) && empty($payload['ewallet_account']) && empty($payload['payment_data'])) {
            throw new RuntimeException('Unlimit transfer requires card_account, ewallet_account, or payment_data payout details.');
        }
    }

    private function persistTransferState(
        Transfer $transfer,
        ?array $requestPayload,
        array $responseData,
        bool $markSubmitted,
    ): Transfer {
        $payoutData = (array) Arr::get($responseData, 'payout_data', []);
        $paymentData = (array) Arr::get($responseData, 'payment_data', []);
        $status = $this->normalizeTransferStatus($payoutData['status'] ?? null);

        return DB::transaction(function () use (
            $transfer,
            $requestPayload,
            $responseData,
            $payoutData,
            $paymentData,
            $status,
            $markSubmitted,
        ): Transfer {
            $transfer->update([
                'external_transfer_id' => $payoutData['id'] ?? $transfer->external_transfer_id,
                'external_payment_id' => $paymentData['id']
                    ?? $payoutData['rrn']
                    ?? $payoutData['arn']
                    ?? $transfer->external_payment_id,
                'target_amount' => $payoutData['amount'] ?? $transfer->target_amount,
                'status' => $status,
                'failure_code' => $status === 'failed'
                    ? (string) ($payoutData['decline_code'] ?? $responseData['name'] ?? 'provider_error')
                    : null,
                'failure_reason' => $status === 'failed'
                    ? ($payoutData['extended_decline_reason'] ?? $payoutData['decline_reason'] ?? $responseData['message'] ?? $transfer->failure_reason)
                    : null,
                'submitted_at' => $markSubmitted ? now() : $transfer->submitted_at,
                'completed_at' => in_array($status, ['completed', 'failed', 'cancelled'], true)
                    ? ($payoutData['created'] ?? now())
                    : $transfer->completed_at,
                'raw_data' => array_merge($transfer->raw_data ?? [], [
                    $markSubmitted ? 'payout_request' : 'payout_status_request' => $requestPayload,
                    $markSubmitted ? 'payout_response' : 'payout_status_response' => $responseData,
                ]),
            ]);

            return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
        });
    }

    private function normalizeTransferStatus(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'COMPLETED' => 'completed',
            'DECLINED', 'TERMINATED', 'CHARGED_BACK', 'CHARGEBACK_RESOLVED', 'REFUNDED' => 'failed',
            'CANCELLED', 'VOIDED' => 'cancelled',
            'NEW', 'IN_PROGRESS', 'AUTHORIZED' => 'pending',
            default => 'submitted',
        };
    }
}
