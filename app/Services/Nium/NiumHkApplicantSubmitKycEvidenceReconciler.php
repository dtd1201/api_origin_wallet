<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NiumHkApplicantSubmitKycEvidenceReconciler
{
    private const ACCOUNT_ID = 7;

    private const LOG_ID = 104;

    private const WEBHOOK_ID = 8;

    private const REFERENCE_ID = 'c620e0e9-ab0a-43bd-aa10-44f573db723a';

    private const EXTERNAL_ID = 'origin-wallet-person-13';

    public function reconcile(): array
    {
        return DB::transaction(function (): array {
            $provider = IntegrationProvider::query()->whereRaw('LOWER(code) = ?', ['nium'])->sole();
            $account = UserProviderAccount::query()
                ->whereKey(self::ACCOUNT_ID)
                ->where('provider_id', $provider->id)
                ->lockForUpdate()
                ->firstOrFail();
            $log = ApiRequestLog::query()->findOrFail(self::LOG_ID);
            $webhook = WebhookEvent::query()->findOrFail(self::WEBHOOK_ID);
            $key = 'ref_'.substr(hash('sha256', self::REFERENCE_ID), 0, 16);
            $attempt = Arr::get((array) $account->metadata, 'nium_submit_kyc_attempts.'.$key);

            if (! is_array($attempt) || ($attempt['state'] ?? null) !== 'response_review') {
                throw new RuntimeException('Applicant Submit KYC attempt is not eligible for offline reconciliation.');
            }

            if ((int) $log->provider_id !== (int) $provider->id
                || (int) $log->user_id !== (int) $account->user_id
                || $log->operation !== 'submit_kyc'
                || $log->external_reference !== self::REFERENCE_ID
                || $log->request_method !== 'POST'
                || (int) $log->response_status !== 200
                || $log->is_success !== true
                || $log->transport_outcome !== 'response_received') {
                throw new RuntimeException('Applicant Submit KYC API evidence does not match the locked execution.');
            }

            $payload = (array) $webhook->payload;
            if ((int) $webhook->provider_id !== (int) $provider->id
                || $webhook->processing_status !== 'processed'
                || $webhook->processed_at === null
                || $webhook->event_type !== 'CUSTOMER_ENTITY_KYC_STATUS'
                || $webhook->external_resource_id !== $account->external_customer_id
                || ($payload['externalId'] ?? null) !== self::EXTERNAL_ID
                || ($payload['referenceId'] ?? null) !== self::REFERENCE_ID
                || ($payload['entityType'] ?? null) !== 'applicant'
                || ($payload['kycMode'] ?? null) !== 'biometric_kyc'
                || ($payload['kycStatus'] ?? null) !== '${kycStatus}') {
                throw new RuntimeException('Applicant Submit KYC webhook evidence does not match the locked sandbox response.');
            }

            $metadata = (array) $account->metadata;
            data_set($metadata, 'nium_submit_kyc_attempts.'.$key, [
                'state' => 'provider_accepted_200_sandbox_review',
                'kyc_mode' => 'biometric_kyc',
                'provider_http_status' => 200,
                'submit_kyc_log_id' => self::LOG_ID,
                'submit_kyc_log_at' => ($log->request_finished_at ?? $log->created_at)?->toISOString(),
                'webhook_id' => self::WEBHOOK_ID,
                'webhook_processed_at' => $webhook->processed_at->toISOString(),
                'updated_at' => now()->toISOString(),
            ]);
            $account->forceFill(['metadata' => $metadata])->save();

            return [
                'terminal' => 'HOLD_PROVIDER_ACCEPTED_200_SANDBOX_REVIEW',
                'state' => 'provider_accepted_200_sandbox_review',
                'log_id' => self::LOG_ID,
                'webhook_id' => self::WEBHOOK_ID,
            ];
        });
    }
}
