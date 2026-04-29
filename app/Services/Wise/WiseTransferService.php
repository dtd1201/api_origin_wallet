<?php

namespace App\Services\Wise;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Services\Integrations\Contracts\TransferProvider;
use App\Services\Transfers\TransferEligibilityService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WiseTransferService implements TransferProvider
{
    use WiseDataFormatter;

    public function __construct(
        private readonly WiseService $wiseService,
        private readonly TransferEligibilityService $eligibilityService,
    ) {
    }

    public function submitTransfer(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        $this->eligibilityService->ensureTransferCanBeSubmitted(
            $transfer->loadMissing(['provider', 'user', 'beneficiary', 'sourceBankAccount'])
        );

        if (! filled($transfer->beneficiary?->external_beneficiary_id)) {
            throw new RuntimeException('Wise transfer requires a synced beneficiary.');
        }

        if (! filled(($transfer->raw_data ?? [])['quote_ref'] ?? null)) {
            throw new RuntimeException('Wise transfer requires a Wise quote id in raw_data.quote_ref.');
        }

        $profileId = $this->wiseService->profileId($transfer->user);
        $payload = $this->buildTransferPayload($transfer);
        $response = $this->wiseService->post(
            path: (string) config('services.wise.transfer_endpoint'),
            payload: $payload,
            user: $transfer->user,
            relatedTransferId: $transfer->id,
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful() || ! filled($responseData['id'] ?? null)) {
            $this->recordTransferFailure($transfer, $payload, $responseData, 'Wise transfer creation failed.');
            throw new RuntimeException($this->transferFailureMessage($responseData, 'Wise transfer creation failed.'));
        }

        $transfer = $this->persistTransferState($transfer, $payload, $responseData, 'submitted', true);

        if ($this->shouldSkipFunding($transfer)) {
            return $transfer;
        }

        $fundingPayload = $this->buildFundingPayload($transfer);
        $fundingResponse = $this->wiseService->post(
            path: $this->wiseService->path(
                (string) config('services.wise.transfer_fund_endpoint'),
                [
                    'profile' => $profileId,
                    'transfer' => $transfer->external_transfer_id,
                ],
            ),
            payload: $fundingPayload,
            user: $transfer->user,
            relatedTransferId: $transfer->id,
        );

        $fundingResponseData = $fundingResponse->json() ?? ['raw' => $fundingResponse->body()];
        $fundingStatus = strtoupper((string) ($fundingResponseData['status'] ?? ''));

        $transfer = DB::transaction(function () use ($transfer, $fundingPayload, $fundingResponseData): Transfer {
            $transfer->update([
                'raw_data' => array_merge($transfer->raw_data ?? [], [
                    'funding_request' => $fundingPayload,
                    'funding_response' => $fundingResponseData,
                ]),
            ]);

            return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
        });

        if (! $fundingResponse->successful() || $fundingStatus === 'REJECTED') {
            $transfer->update([
                'status' => 'pending',
                'failure_code' => (string) ($fundingResponseData['errorCode'] ?? 'funding_rejected'),
                'failure_reason' => $this->transferFailureMessage($fundingResponseData, 'Wise funding step was rejected.'),
            ]);

            throw new RuntimeException($this->transferFailureMessage($fundingResponseData, 'Wise funding step was rejected.'));
        }

        return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
    }

    public function syncTransferStatus(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        $transfer->loadMissing(['provider', 'user', 'beneficiary', 'sourceBankAccount']);

        if (! filled($transfer->external_transfer_id)) {
            throw new RuntimeException('Wise transfer is missing the external transfer id.');
        }

        $response = $this->wiseService->get(
            path: $this->wiseService->path(
                (string) config('services.wise.transfer_retrieve_endpoint'),
                ['transfer' => $transfer->external_transfer_id],
            ),
            user: $transfer->user,
            relatedTransferId: $transfer->id,
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful()) {
            throw new RuntimeException($this->transferFailureMessage($responseData, 'Wise transfer status sync failed.'));
        }

        return $this->persistTransferState($transfer, null, $responseData, null, false);
    }

    private function buildTransferPayload(Transfer $transfer): array
    {
        $rawData = (array) ($transfer->raw_data ?? []);
        $wise = (array) ($rawData['wise'] ?? []);
        $payload = array_filter([
            'sourceAccount' => $wise['sourceAccount'] ?? $wise['source_account'] ?? null,
            'targetAccount' => (int) $transfer->beneficiary->external_beneficiary_id,
            'quoteUuid' => $rawData['quote_ref'],
            'customerTransactionId' => $transfer->client_reference ?: $transfer->transfer_no,
            'details' => array_filter([
                'reference' => $this->transferReference((string) ($transfer->reference_text ?: $transfer->transfer_no)),
                'transferPurpose' => $transfer->purpose_code,
                'transferPurposeSubTransferPurpose' => $wise['transferPurposeSubTransferPurpose']
                    ?? $wise['transfer_purpose_sub_transfer_purpose']
                    ?? null,
            ], static fn ($value) => $value !== null && $value !== ''),
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);

        if (isset($wise['request']) && is_array($wise['request'])) {
            $payload = array_replace_recursive($payload, $wise['request']);
        }

        return $payload;
    }

    private function buildFundingPayload(Transfer $transfer): array
    {
        $wise = (array) (($transfer->raw_data ?? [])['wise'] ?? []);
        $payload = array_filter([
            'type' => $wise['fundingType'] ?? $wise['funding_type'] ?? 'BALANCE',
            'partnerReference' => $transfer->client_reference ?: $transfer->transfer_no,
        ], static fn ($value) => $value !== null && $value !== '');

        if (isset($wise['funding_request']) && is_array($wise['funding_request'])) {
            $payload = array_replace_recursive($payload, $wise['funding_request']);
        }

        return $payload;
    }

    private function shouldSkipFunding(Transfer $transfer): bool
    {
        $wise = (array) (($transfer->raw_data ?? [])['wise'] ?? []);

        return (bool) ($wise['skip_funding'] ?? false);
    }

    private function persistTransferState(
        Transfer $transfer,
        ?array $requestPayload,
        array $responseData,
        ?string $fallbackStatus,
        bool $markSubmitted,
    ): Transfer {
        $status = $this->normalizeTransferStatus($responseData['status'] ?? $fallbackStatus);

        return DB::transaction(function () use ($transfer, $requestPayload, $responseData, $status, $markSubmitted): Transfer {
            $transfer->update([
                'external_transfer_id' => $responseData['id'] ?? $transfer->external_transfer_id,
                'target_amount' => Arr::get($responseData, 'targetValue') ?? $transfer->target_amount,
                'fee_amount' => Arr::get($responseData, 'price.total.value.amount')
                    ?? Arr::get($responseData, 'fee')
                    ?? $transfer->fee_amount,
                'status' => $status,
                'failure_code' => $status === 'failed'
                    ? (string) (Arr::get($responseData, 'errorCode') ?? $transfer->failure_code ?? 'provider_error')
                    : null,
                'failure_reason' => $status === 'failed'
                    ? $this->transferFailureMessage($responseData, $transfer->failure_reason ?? 'Wise transfer failed.')
                    : null,
                'submitted_at' => $markSubmitted ? now() : $transfer->submitted_at,
                'completed_at' => in_array($status, ['completed', 'failed', 'cancelled'], true)
                    ? ($responseData['updated'] ?? $responseData['created'] ?? now())
                    : $transfer->completed_at,
                'raw_data' => array_merge($transfer->raw_data ?? [], [
                    $markSubmitted ? 'transfer_request' : 'transfer_status_request' => $requestPayload,
                    $markSubmitted ? 'transfer_response' : 'transfer_status_response' => $responseData,
                ]),
            ]);

            return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
        });
    }

    private function recordTransferFailure(Transfer $transfer, array $payload, array $responseData, string $fallbackMessage): void
    {
        $transfer->update([
            'status' => 'failed',
            'failure_code' => (string) (Arr::get($responseData, 'errorCode') ?? 'provider_error'),
            'failure_reason' => $this->transferFailureMessage($responseData, $fallbackMessage),
            'raw_data' => array_merge($transfer->raw_data ?? [], [
                'transfer_request' => $payload,
                'transfer_error' => $responseData,
            ]),
        ]);
    }
}
