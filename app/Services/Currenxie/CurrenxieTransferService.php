<?php

namespace App\Services\Currenxie;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Services\Integrations\Contracts\TransferProvider;
use App\Services\Integrations\ProviderHttpClient;
use App\Services\Transfers\TransferEligibilityService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CurrenxieTransferService implements TransferProvider
{
    public function __construct(
        private readonly TransferEligibilityService $eligibilityService,
    ) {
    }

    public function submitTransfer(IntegrationProvider $provider, Transfer $transfer): Transfer
    {
        $this->eligibilityService->ensureTransferCanBeSubmitted(
            $transfer->loadMissing(['provider', 'user', 'beneficiary'])
        );

        $client = new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'currenxie',
            headers: [
                'X-API-KEY' => (string) config('services.currenxie.api_key'),
                'X-API-SECRET' => (string) config('services.currenxie.api_secret'),
            ],
        );

        $payload = [
            'transfer_no' => $transfer->transfer_no,
            'user_reference' => (string) $transfer->user_id,
            'beneficiary_reference' => $transfer->beneficiary?->external_beneficiary_id,
            'source_currency' => $transfer->source_currency,
            'target_currency' => $transfer->target_currency,
            'source_amount' => $transfer->source_amount,
            'target_amount' => $transfer->target_amount,
            'fx_rate' => $transfer->fx_rate,
            'fee_amount' => $transfer->fee_amount,
            'purpose_code' => $transfer->purpose_code,
            'reference_text' => $transfer->reference_text,
            'client_reference' => $transfer->client_reference,
        ];

        $response = $client->post(
            path: (string) config('services.currenxie.transfer_endpoint'),
            payload: $payload,
            user: $transfer->user,
            relatedTransferId: $transfer->id,
        );

        $responseData = $response->json() ?? [];

        if (! $response->successful()) {
            $transfer->update([
                'status' => 'failed',
                'failure_code' => (string) ($responseData['code'] ?? 'provider_error'),
                'failure_reason' => $responseData['message'] ?? 'Currenxie transfer submission failed.',
                'raw_data' => $responseData,
            ]);

            throw new RuntimeException('Currenxie transfer submission failed.');
        }

        return DB::transaction(function () use ($transfer, $responseData): Transfer {
            $transfer->update([
                'external_transfer_id' => $responseData['id'] ?? $responseData['transfer_id'] ?? null,
                'external_payment_id' => $responseData['payment_id'] ?? null,
                'status' => $responseData['status'] ?? 'submitted',
                'submitted_at' => now(),
                'raw_data' => $responseData,
            ]);

            return $transfer->fresh(['beneficiary', 'sourceBankAccount', 'transactions']);
        });
    }
}
