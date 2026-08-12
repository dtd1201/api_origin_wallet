<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycRelatedPerson;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class NiumHkStakeholderSubmitKycRetryOneShotRunner
{
    public const EXPECTED_HEAD = '8241704f5e65dc0f93b1225846ddb10985a46d00';

    private const ACCOUNT_ID = 7;
    private const PROTECTED_ACCOUNT_ID = 4;
    private const USER_ID = 9;
    private const PERSON_ID = 14;
    private const PROFILE_ID = 9;
    private const EXTERNAL_ID = 'origin-wallet-person-14';
    private const REFERENCE_ID = '7609d9d1-9d37-4e08-9197-602d792f7a2e';
    private const PREVIOUS_LOG_ID = 106;
    private const ERROR_FIELD_FINGERPRINT = 'b4753588f3f6ef2b';
    private const CLAIM_KEY = 'nium_stakeholder_submit_kyc_retry_generation_2';

    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumHkManualKycDocumentResolver $documentResolver,
        private readonly NiumHkSubmitKycPayloadFactory $payloadFactory,
    ) {}

    public function audit(bool $rfiAcknowledged = false): array
    {
        $context = $this->preflight($rfiAcknowledged);
        if ($this->fingerprint(UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID))
            !== $context['protected_fingerprint']) {
            throw new RuntimeException('Protected Account 4 changed during offline preflight.');
        }

        return [
            'terminal' => 'READY_FOR_SEPARATE_HUMAN_APPROVAL',
            'previous_log_id' => self::PREVIOUS_LOG_ID,
            'confirmed_entity_type' => $context['entity_type'],
            'confirmed_kyc_mode' => $context['kyc_mode'],
            'proof_of_address_status' => $context['proof_of_address_status'],
            'stakeholder_retry_post_count' => 0,
        ];
    }

    public function run(bool $separateHumanApproval = false, bool $rfiAcknowledged = false): array
    {
        if (! $separateHumanApproval) {
            throw new RuntimeException('Separate human approval is required outside the generation #2 runner.');
        }

        $context = $this->preflight($rfiAcknowledged);
        $protectedFingerprint = $context['protected_fingerprint'];
        $logMaxId = (int) (ApiRequestLog::query()->max('id') ?? 0);
        $this->claim($context);

        try {
            $response = $this->niumService->post(
                path: $this->niumService->path(
                    (string) config('services.nium.customer_submit_kyc_endpoint'),
                    ['client' => $this->niumService->clientId(), 'customer' => $context['account']->external_customer_id],
                ),
                payload: $context['payload'],
                user: $context['user'],
                operation: 'submit_kyc',
                externalReference: self::REFERENCE_ID,
            );
        } catch (ConnectionException|NiumEvidencePersistenceException) {
            $this->mark('unknown');

            return $this->finish('STOP_KYC_OUTCOME_UNKNOWN_NO_RETRY', $context, $logMaxId, $protectedFingerprint);
        } catch (Throwable) {
            $definite = $this->newLogs($context, $logMaxId)->contains(
                fn (ApiRequestLog $log): bool => (int) $log->response_status >= 400,
            );
            $this->mark($definite ? 'rejected' : 'unknown');

            return $this->finish(
                $definite ? 'STOP_KYC_REJECTED_NO_RETRY' : 'STOP_KYC_OUTCOME_UNKNOWN_NO_RETRY',
                $context,
                $logMaxId,
                $protectedFingerprint,
            );
        }

        if (! $response->successful()) {
            $this->mark('rejected');

            return $this->finish('STOP_KYC_REJECTED_NO_RETRY', $context, $logMaxId, $protectedFingerprint);
        }

        $valid = $this->validResponse($response, $context);
        $this->mark($valid ? 'initiated' : 'response_review');

        return $this->finish(
            $valid ? 'PASS_KYC_INITIATED' : 'HOLD_RESPONSE_REVIEW',
            $context,
            $logMaxId,
            $protectedFingerprint,
        );
    }

    private function preflight(bool $rfiAcknowledged = false): array
    {
        if (! $this->currentHeadIsCompatible()) {
            throw new RuntimeException('The deployed Git HEAD is not the locked generation #2 checkpoint.');
        }

        $entityType = 'INDIVIDUAL_STAKEHOLDER';
        $kycMode = 'MANUAL_KYC';

        $provider = IntegrationProvider::query()->whereRaw('LOWER(code) = ?', ['nium'])->sole();
        $account = UserProviderAccount::query()
            ->whereKey(self::ACCOUNT_ID)
            ->where('provider_id', $provider->id)
            ->where('user_id', self::USER_ID)
            ->firstOrFail();
        $user = User::query()->findOrFail(self::USER_ID);
        $person = KycRelatedPerson::query()
            ->whereKey(self::PERSON_ID)
            ->where('kyc_profile_id', self::PROFILE_ID)
            ->firstOrFail();
        $protectedAccount = UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID);

        if (! filled($account->external_customer_id)
            || ! filled($account->external_account_id)
            || $account->reconciliation_status !== 'reconciled') {
            throw new RuntimeException('Account 7 provider identifiers are not locked.');
        }
        $this->assertCustomerWebhook((int) $provider->id, (string) $account->external_customer_id);

        $metadata = (array) $account->metadata;
        $applicantKey = $this->attemptKey('c620e0e9-ab0a-43bd-aa10-44f573db723a');
        $stakeholderKey = $this->attemptKey(self::REFERENCE_ID);
        if (($metadata['nium_submit_kyc_attempts'][$applicantKey]['state'] ?? null) !== 'provider_accepted_200_sandbox_review') {
            throw new RuntimeException('Applicant accepted evidence is missing.');
        }
        if (($metadata['nium_submit_kyc_attempts'][$stakeholderKey]['state'] ?? null) !== 'rejected') {
            throw new RuntimeException('Stakeholder rejection evidence is missing.');
        }
        if (array_key_exists(self::CLAIM_KEY, $metadata)) {
            throw new RuntimeException('HOLD_GENERATION_2_ALREADY_CLAIMED');
        }
        if ($person->id !== self::PERSON_ID || 'origin-wallet-person-'.$person->id !== self::EXTERNAL_ID) {
            throw new RuntimeException('Stakeholder person binding is invalid.');
        }

        $documents = $this->documentResolver->resolve($person);
        if ($documents['identity'] === null) {
            throw new RuntimeException('HOLD_FACTUAL_IDENTITY_DOCUMENT_REQUIRED');
        }
        $proofOfAddressStatus = $documents['proof_of_address'] === null
            ? 'POA_MISSING_RFI_EXPECTED'
            : 'POA_FACTUAL_AVAILABLE';
        if ($documents['proof_of_address'] === null && ! $rfiAcknowledged) {
            throw new RuntimeException('HOLD_RFI_ACKNOWLEDGEMENT_REQUIRED');
        }
        $this->assertPreviousLog((int) $provider->id);
        $payload = $this->payloadFactory->buildManual(
            $person,
            self::REFERENCE_ID,
            $documents['identity'],
            $documents['proof_of_address'],
        );

        return [
            'account' => $account,
            'user' => $user,
            'protected_account' => $protectedAccount,
            'protected_fingerprint' => $this->fingerprint($protectedAccount),
            'payload' => $payload,
            'provider_id' => (int) $provider->id,
            'user_id' => self::USER_ID,
            'entity_type' => $entityType,
            'kyc_mode' => $kycMode,
            'proof_of_address_status' => $proofOfAddressStatus,
        ];
    }

    private function assertCustomerWebhook(int $providerId, string $customerId): void
    {
        $event = WebhookEvent::query()
            ->where('provider_id', $providerId)
            ->where('event_type', 'CUSTOMER_STATUS_WEBHOOK')
            ->where('external_resource_id', $customerId)
            ->where('processing_status', 'processed')
            ->whereNotNull('processed_at')
            ->orderByDesc('processed_at')
            ->orderByDesc('id')
            ->first();
        $payload = (array) ($event?->payload ?? []);
        if (($payload['status'] ?? null) !== 'pending' || ($payload['subStatus'] ?? null) !== 'awaiting_kyc') {
            throw new RuntimeException('Latest Nium customer webhook is not awaiting_kyc.');
        }
    }

    private function assertPreviousLog(int $providerId): void
    {
        $logs = ApiRequestLog::query()
            ->where('provider_id', $providerId)
            ->where('user_id', self::USER_ID)
            ->where('operation', 'submit_kyc')
            ->where('request_method', 'POST')
            ->where('external_reference', self::REFERENCE_ID)
            ->get();
        if ($logs->count() !== 1 || (int) $logs->sole()->id !== self::PREVIOUS_LOG_ID) {
            throw new RuntimeException('Locked Stakeholder log #106 is not the sole previous POST.');
        }

        $log = $logs->sole();
        $items = $log->response_body['error_items'] ?? [];
        $hasEntityType = is_array($items) && collect($items)->contains(fn (mixed $item): bool => is_array($item)
            && ($item['error_code'] ?? null) === 'invalid_input'
            && ($item['error_field'] ?? null) === 'entityType'
            && ($item['error_field_fingerprint'] ?? null) === self::ERROR_FIELD_FINGERPRINT);
        if ((int) $log->response_status !== 400
            || $log->is_success !== false
            || $log->transport_outcome !== 'response_received'
            || ($log->response_body['error_code'] ?? null) !== 'invalid_input'
            || ! $hasEntityType) {
            throw new RuntimeException('Locked Stakeholder rejection evidence is invalid.');
        }
    }

    private function claim(array $context): void
    {
        DB::transaction(function () use ($context): void {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->firstOrFail();
            $metadata = (array) $account->metadata;
            if (array_key_exists(self::CLAIM_KEY, $metadata)) {
                throw new RuntimeException('HOLD_GENERATION_2_ALREADY_CLAIMED');
            }
            $metadata[self::CLAIM_KEY] = [
                'state' => 'submitting',
                'previous_log_id' => self::PREVIOUS_LOG_ID,
                'previous_http_status' => 400,
                'previous_error_code' => 'invalid_input',
                'previous_error_field' => 'entityType',
                'previous_error_field_fingerprint' => self::ERROR_FIELD_FINGERPRINT,
                'confirmed_entity_type' => $context['entity_type'],
                'confirmed_kyc_mode' => $context['kyc_mode'],
                'updated_at' => now()->toISOString(),
            ];
            $account->forceFill(['metadata' => $metadata])->save();
        });
    }

    private function mark(string $state): void
    {
        DB::transaction(function () use ($state): void {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->firstOrFail();
            $metadata = (array) $account->metadata;
            $metadata[self::CLAIM_KEY]['state'] = $state;
            $metadata[self::CLAIM_KEY]['updated_at'] = now()->toISOString();
            $account->forceFill(['metadata' => $metadata])->save();
        });
    }

    private function finish(string $terminal, array $context, int $logMaxId, string $protectedFingerprint): array
    {
        $posts = $this->newLogs($context, $logMaxId)->count();
        if ($posts > 1
            || ($terminal === 'PASS_KYC_INITIATED' && $posts !== 1)
            || $this->fingerprint(UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID)) !== $protectedFingerprint) {
            throw new RuntimeException('Stakeholder generation #2 postcondition failed closed.');
        }

        return ['terminal' => $terminal, 'stakeholder_retry_post_count' => $posts];
    }

    private function newLogs(array $context, int $logMaxId)
    {
        return ApiRequestLog::query()
            ->where('id', '>', $logMaxId)
            ->where('provider_id', $context['provider_id'])
            ->where('user_id', $context['user_id'])
            ->where('operation', 'submit_kyc')
            ->where('request_method', 'POST')
            ->where('external_reference', self::REFERENCE_ID)
            ->get();
    }

    private function validResponse(Response $response, array $context): bool
    {
        try {
            $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        return is_array($body)
            && ($body['kycStatus'] ?? null) === 'initiated'
            && ($body['kycMode'] ?? null) === $context['kyc_mode']
            && ($body['entityType'] ?? null) === $context['entity_type']
            && ($body['referenceId'] ?? null) === self::REFERENCE_ID
            && is_string($body['redirectUrl'] ?? null)
            && trim($body['redirectUrl']) !== '';
    }

    private function attemptKey(string $referenceId): string
    {
        return 'ref_'.substr(hash('sha256', $referenceId), 0, 16);
    }

    private function fingerprint(UserProviderAccount $account): string
    {
        $attributes = $account->getRawOriginal();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    private function currentHeadIsCompatible(): bool
    {
        $repository = escapeshellarg(base_path());
        $expected = escapeshellarg(self::EXPECTED_HEAD);
        $head = trim((string) shell_exec("git -C {$repository} rev-parse HEAD 2>/dev/null"));
        if ($head === self::EXPECTED_HEAD) {
            return true;
        }

        exec("git -C {$repository} merge-base --is-ancestor {$expected} HEAD 2>/dev/null", $output, $exitCode);

        return $exitCode === 0;
    }
}
