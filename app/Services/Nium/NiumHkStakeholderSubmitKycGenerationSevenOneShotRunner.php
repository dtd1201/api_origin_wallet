<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\KycRelatedPerson;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class NiumHkStakeholderSubmitKycGenerationSevenOneShotRunner
{
    private const ACCOUNT_ID = 7;
    private const PROTECTED_ACCOUNT_ID = 4;
    private const USER_ID = 9;
    private const PERSON_ID = 14;
    private const PROFILE_ID = 9;
    private const REFERENCE_ID = '7609d9d1-9d37-4e08-9197-602d792f7a2e';
    private const CLAIM_KEY = 'nium_stakeholder_submit_kyc_retry_generation_7';
    private const G6_CLAIM_KEY = 'nium_stakeholder_submit_kyc_retry_generation_6';
    private const SESSION_CLAIM_KEY = 'nium_kyc_prebuilt_form_session';
    private const HISTORICAL_LOG_IDS = [106, 113, 114, 115, 116];
    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumHkManualKycDocumentResolver $documentResolver,
        private readonly NiumHkSubmitKycPayloadFactory $payloadFactory,
    ) {}

    public function audit(): array
    {
        $context = $this->preflight(false);
        $this->assertProtectedAccount($context['protected_fingerprint']);
        $session = $context['session'];

        return [
            'terminal' => $session['conflict'] ? 'HOLD_PREBUILT_SESSION_CONFLICT' : 'READY_FOR_CODE_HUMAN_REVIEW',
            'checkpoint' => 'OFFLINE_G7_CURRENT_DOCS_CANDIDATE',
            'historical_latest_submit_log' => 116,
            'historical_submit_count' => 5,
            'g6_claim_state' => $context['g6_claim_state'],
            'g6_post_count' => 0,
            'g7_claim_state' => $context['g7_claim_state'],
            'g7_post_count' => 0,
            'entity_reference_id_match' => $context['payload']['entityReferenceId'] === self::REFERENCE_ID,
            'entity_type' => $context['payload']['entityType'],
            'kyc_mode' => $context['payload']['kycMode'],
            'region' => $context['payload']['region'],
            'is_resident_present' => array_key_exists('isResident', $context['payload']),
            'identity_document_id' => 27,
            'poa_document_id' => 28,
            'passport_required_fields_present' => $this->passportFieldsPresent($context['payload']),
            ...$session,
            'account_4_immutable' => true,
            'http_count' => 0,
            'db_external_write_count' => 0,
            'provider_contract_proven' => false,
            'provider_risk' => 'kycMode case and pre-built session coexistence remain unconfirmed',
        ];
    }

    public function run(
        bool $separateHumanApproval = false,
        bool $expiredPrebuiltSessionHumanOverride = false,
    ): array
    {
        if (! $separateHumanApproval) {
            throw new RuntimeException('Separate human approval is required outside the generation #7 runner.');
        }
        $preliminary = $this->preflight(true, $expiredPrebuiltSessionHumanOverride);
        $logMaxId = (int) (ApiRequestLog::query()->max('id') ?? 0);
        $applicantPosts = $this->applicantPostCount($preliminary['provider_id']);
        $context = $this->claim($preliminary);

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
            $this->mark('unknown', null, 'ambiguous');
            return $this->finish('STOP_KYC_OUTCOME_UNKNOWN_NO_RETRY', $context, $logMaxId, $applicantPosts);
        } catch (Throwable) {
            $this->mark('unknown', null, 'ambiguous');
            return $this->finish('STOP_KYC_OUTCOME_UNKNOWN_NO_RETRY', $context, $logMaxId, $applicantPosts);
        }

        if (! $response->successful()) {
            $serverError = $response->serverError();
            $this->mark($serverError ? 'unknown' : 'rejected', $response->status(), 'response_received');
            return $this->finish(
                $serverError ? 'STOP_KYC_OUTCOME_UNKNOWN_NO_RETRY' : 'STOP_KYC_REJECTED_NO_RETRY',
                $context,
                $logMaxId,
                $applicantPosts,
            );
        }

        $valid = $this->validResponse($response);
        $this->mark($valid ? 'initiated' : 'response_review', $response->status(), 'response_received');

        return $this->finish($valid ? 'PASS_KYC_INITIATED' : 'HOLD_RESPONSE_REVIEW', $context, $logMaxId, $applicantPosts);
    }

    private function preflight(bool $forExecution, bool $expiredSessionOverride = false): array
    {
        $provider = IntegrationProvider::query()->whereRaw('LOWER(code) = ?', ['nium'])->sole();
        $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)
            ->where('provider_id', $provider->id)->where('user_id', self::USER_ID)->firstOrFail();
        $user = User::query()->findOrFail(self::USER_ID);
        $person = KycRelatedPerson::query()->whereKey(self::PERSON_ID)
            ->where('kyc_profile_id', self::PROFILE_ID)->firstOrFail();
        $protected = UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID);
        if (! filled($account->external_customer_id) || ! filled($account->external_account_id)
            || $account->reconciliation_status !== 'reconciled') {
            throw new RuntimeException('Account 7 provider identifiers are not locked.');
        }

        $metadata = (array) $account->metadata;
        $this->assertHistoricalClaims($metadata);
        if (array_key_exists(self::CLAIM_KEY, $metadata)) {
            throw new RuntimeException('HOLD_GENERATION_7_ALREADY_CLAIMED');
        }
        $this->assertHistory((int) $provider->id);
        $this->assertTenantStakeholderEvidence((int) $provider->id);
        $documents = $this->documentResolver->resolve($person);
        if ((int) ($documents['identity']?->id ?? 0) !== 27 || (int) ($documents['proof_of_address']?->id ?? 0) !== 28) {
            throw new RuntimeException('Factual documents 27 and 28 must be approved and NIUM AVAILABLE.');
        }
        $payload = $this->payloadFactory->buildManualGenerationSeven(
            $person, self::REFERENCE_ID, $documents['identity'], $documents['proof_of_address'],
        );
        $this->assertDistinctContract($payload);
        $session = $this->sessionEvidence($metadata, (int) $provider->id);
        if ($forExecution && $session['conflict']) {
            if (! $expiredSessionOverride) {
                throw new RuntimeException('HOLD_PREBUILT_SESSION_CONFLICT');
            }
            $this->assertExpiredSessionOverride($metadata, (int) $provider->id, (string) $account->external_customer_id);
        }

        return [
            'account' => $account, 'user' => $user, 'provider_id' => (int) $provider->id, 'payload' => $payload,
            'protected_fingerprint' => $this->fingerprint($protected), 'session' => $session,
            'account_execution_fingerprint' => $this->accountExecutionFingerprint($account),
            'payload_fingerprint' => $this->payloadFingerprint($payload),
            'g6_claim_state' => $metadata[self::G6_CLAIM_KEY]['state'] ?? null,
            'g7_claim_state' => $metadata[self::CLAIM_KEY]['state'] ?? null,
        ];
    }

    private function assertHistory(int $providerId): void
    {
        $applicant = ApiRequestLog::query()->findOrFail(104);
        if ((int) $applicant->provider_id !== $providerId || (int) $applicant->user_id !== self::USER_ID
            || $applicant->operation !== 'submit_kyc' || $applicant->request_method !== 'POST'
            || (int) $applicant->response_status !== 200 || ! $applicant->is_success
            || $applicant->transport_outcome !== 'response_received') {
            throw new RuntimeException('Applicant evidence #104 is invalid.');
        }
        $ids = ApiRequestLog::query()->where('provider_id', $providerId)->where('user_id', self::USER_ID)
            ->where('external_reference', self::REFERENCE_ID)->where('operation', 'submit_kyc')
            ->where('request_method', 'POST')->orderBy('id')->pluck('id')->all();
        if ($ids !== [106, 113, 114, 115, 116]) {
            throw new RuntimeException('Historical stakeholder Submit KYC evidence is not locked.');
        }
        foreach ($ids as $id) {
            $log = ApiRequestLog::query()->findOrFail($id);
            if ((int) $log->response_status !== 400 || $log->is_success
                || $log->transport_outcome !== 'response_received') {
                throw new RuntimeException("Historical stakeholder Submit KYC evidence #{$id} is invalid.");
            }
        }
    }

    private function assertTenantStakeholderEvidence(int $providerId): void
    {
        $event = WebhookEvent::query()->findOrFail(6);
        $payload = (array) $event->payload;
        if ((int) $event->provider_id !== $providerId || $event->event_type !== 'CUSTOMER_ENTITY_KYC_STATUS'
            || ($payload['referenceId'] ?? null) !== self::REFERENCE_ID
            || ($payload['externalId'] ?? null) !== 'origin-wallet-person-14'
            || ($payload['entityType'] ?? null) !== 'individual_stakeholder'
            || ($payload['kycStatus'] ?? null) !== 'kyc_required') {
            throw new RuntimeException('Tenant stakeholder webhook evidence #6 is invalid.');
        }
    }

    private function sessionEvidence(array $metadata, int $providerId): array
    {
        $claim = $metadata[self::SESSION_CLAIM_KEY] ?? null;
        $log = ApiRequestLog::query()->find(117);
        $present = $log !== null && (int) $log->provider_id === $providerId
            && $log->operation === 'kyc_form_session_create' && $log->request_method === 'POST';
        $body = (array) ($log?->response_body ?? []);
        $expiry = is_array($claim) ? ($claim['expiry_at'] ?? null) : null;

        return [
            'prebuilt_session_117_present' => $present,
            'prebuilt_session_claim_present' => is_array($claim),
            'prebuilt_session_generation' => 'prebuilt_form_session',
            'prebuilt_session_state' => is_array($claim) ? ($claim['state'] ?? null) : null,
            'session_id_present' => filled($body['sessionId'] ?? null) || filled($claim['session_id_fingerprint'] ?? null),
            'session_created_at' => is_array($claim) ? ($claim['created_at'] ?? null) : null,
            'session_expiry_at' => $expiry,
            'session_expired_locally_proven' => false,
            'provider_completion_known' => false,
            'direct_submit_coexistence_provider_confirmed' => false,
            'prebuilt_session_conflict_state' => $present || is_array($claim) ? 'UNRESOLVED_PROVIDER_COEXISTENCE' : 'NO_LOCAL_SESSION_EVIDENCE',
            'conflict' => $present || is_array($claim),
        ];
    }

    private function assertDistinctContract(array $payload): void
    {
        $tuple = [$payload['entityType'], $payload['kycMode'], array_key_exists('isResident', $payload)];
        if ($tuple !== ['INDIVIDUAL_STAKEHOLDER', 'manual_kyc', false]
            || $tuple === ['INDIVIDUAL_STAKEHOLDER', 'MANUAL_KYC', false]
            || $tuple === ['individual_stakeholder', 'manual_kyc', false]
            || $tuple === ['individual_stakeholder', 'manual_kyc', true]) {
            throw new RuntimeException('Generation #7 contract is not historically distinct.');
        }
    }

    private function claim(array $preliminary): array
    {
        return DB::transaction(function () use ($preliminary): array {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->firstOrFail();
            $provider = IntegrationProvider::query()->whereRaw('LOWER(code) = ?', ['nium'])->sole();
            if ((int) $account->provider_id !== (int) $provider->id || (int) $account->user_id !== self::USER_ID
                || ! filled($account->external_customer_id) || ! filled($account->external_account_id)
                || $account->reconciliation_status !== 'reconciled'
                || $this->accountExecutionFingerprint($account) !== $preliminary['account_execution_fingerprint']) {
                throw new RuntimeException('Account 7 changed after preliminary G7 preflight.');
            }
            $metadata = (array) $account->metadata;
            $this->assertHistoricalClaims($metadata);
            if (array_key_exists(self::CLAIM_KEY, $metadata)) {
                throw new RuntimeException('Generation #7 claim precondition failed.');
            }

            $person = KycRelatedPerson::query()->whereKey(self::PERSON_ID)
                ->where('kyc_profile_id', self::PROFILE_ID)->lockForUpdate()->firstOrFail();
            $documents = KycDocument::query()->whereIn('id', [27, 28])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $identity = $documents->get(27);
            $proofOfAddress = $documents->get(28);
            $this->assertLockedDocument(
                $identity,
                $person,
                ['passport', 'passport_front', 'identity_document', 'identity_document_front', 'national_id_front'],
            );
            $this->assertLockedDocument(
                $proofOfAddress,
                $person,
                ['proof_of_address', 'utility_bill', 'bank_statement'],
            );

            $this->assertLockedHistory((int) $provider->id);
            $this->assertLockedTenantStakeholderEvidence((int) $provider->id);
            $payload = $this->payloadFactory->buildManualGenerationSeven(
                $person, self::REFERENCE_ID, $identity, $proofOfAddress,
            );
            $this->assertDistinctContract($payload);
            $payloadFingerprint = $this->payloadFingerprint($payload);
            if (! hash_equals($preliminary['payload_fingerprint'], $payloadFingerprint)) {
                throw new RuntimeException('G7 payload changed after preliminary preflight.');
            }
            $this->assertExpiredSessionOverride(
                $metadata,
                (int) $provider->id,
                (string) $account->external_customer_id,
            );
            $metadata[self::CLAIM_KEY] = [
                'state' => 'submitting', 'generation' => 7, 'contract_fingerprint' => $payloadFingerprint,
                'prebuilt_session_log_id' => 117,
                'expired_prebuilt_session_override' => 'human_verified_provider_ui_expired',
                'updated_at' => now()->toISOString(),
            ];
            $account->forceFill(['metadata' => $metadata])->save();

            return [
                'account' => $account, 'user' => User::query()->findOrFail(self::USER_ID),
                'provider_id' => (int) $provider->id, 'payload' => $payload,
                'payload_fingerprint' => $payloadFingerprint,
                'protected_fingerprint' => $preliminary['protected_fingerprint'],
            ];
        });
    }

    private function assertLockedDocument(
        mixed $document,
        KycRelatedPerson $person,
        array $purposes,
    ): void {
        if (! $document instanceof KycDocument) {
            throw new RuntimeException('Locked G7 documents 27 and 28 are required.');
        }

        $metadata = (array) $document->metadata;
        $purpose = strtolower(trim((string) ($metadata['document_purpose'] ?? $document->type)));
        $factual = ($metadata['factual'] ?? null) === true || ($metadata['factual_evidence'] ?? null) === true;
        $synthetic = ($metadata['synthetic'] ?? null) === true
            || ($metadata['synthetic_only'] ?? null) === true
            || ($metadata['synthetic_test'] ?? null) === true;
        $supersededAt = $metadata['superseded_at'] ?? null;
        $superseded = $document->status === 'superseded'
            || ($metadata['superseded'] ?? null) === true
            || (is_string($supersededAt) ? trim($supersededAt) !== '' : $supersededAt !== null);

        if ((int) $document->kyc_profile_id !== self::PROFILE_ID
            || (int) $document->kyc_related_person_id !== (int) $person->id
            || $document->status !== 'approved'
            || ! $factual
            || $synthetic
            || ($metadata['historical_only'] ?? null) === true
            || $superseded
            || ! in_array($purpose, $purposes, true)
            || ! is_string($metadata['nium_file_id'] ?? null)
            || ! Str::isUuid($metadata['nium_file_id'])
            || strtoupper(trim((string) ($metadata['nium_file_state'] ?? ''))) !== 'AVAILABLE') {
            throw new RuntimeException('Locked G7 documents 27 and 28 must be factual, approved, and NIUM AVAILABLE.');
        }
    }

    private function assertLockedHistory(int $providerId): void
    {
        $applicant = ApiRequestLog::query()->whereKey(104)->lockForUpdate()->firstOrFail();
        if ((int) $applicant->provider_id !== $providerId || (int) $applicant->user_id !== self::USER_ID
            || $applicant->operation !== 'submit_kyc' || $applicant->request_method !== 'POST'
            || (int) $applicant->response_status !== 200 || ! $applicant->is_success
            || $applicant->transport_outcome !== 'response_received') {
            throw new RuntimeException('Applicant evidence #104 is invalid.');
        }

        $logs = ApiRequestLog::query()->where('provider_id', $providerId)->where('user_id', self::USER_ID)
            ->where('external_reference', self::REFERENCE_ID)->where('operation', 'submit_kyc')
            ->where('request_method', 'POST')->orderBy('id')->lockForUpdate()->get();
        if ($logs->pluck('id')->all() !== self::HISTORICAL_LOG_IDS) {
            throw new RuntimeException('Historical stakeholder Submit KYC evidence is not locked.');
        }
        foreach ($logs as $log) {
            if ((int) $log->response_status !== 400 || $log->is_success
                || $log->transport_outcome !== 'response_received') {
                throw new RuntimeException("Historical stakeholder Submit KYC evidence #{$log->id} is invalid.");
            }
        }
    }

    private function assertLockedTenantStakeholderEvidence(int $providerId): void
    {
        $event = WebhookEvent::query()->whereKey(6)->lockForUpdate()->firstOrFail();
        $payload = (array) $event->payload;
        if ((int) $event->provider_id !== $providerId || $event->event_type !== 'CUSTOMER_ENTITY_KYC_STATUS'
            || ($payload['referenceId'] ?? null) !== self::REFERENCE_ID
            || ($payload['externalId'] ?? null) !== 'origin-wallet-person-14'
            || ($payload['entityType'] ?? null) !== 'individual_stakeholder'
            || ($payload['kycStatus'] ?? null) !== 'kyc_required') {
            throw new RuntimeException('Tenant stakeholder webhook evidence #6 is invalid.');
        }
    }

    private function assertHistoricalClaims(array $metadata): void
    {
        $attemptKey = 'ref_'.substr(hash('sha256', self::REFERENCE_ID), 0, 16);
        $attempt = $metadata['nium_submit_kyc_attempts'][$attemptKey] ?? null;
        if (! is_array($attempt)
            || ($attempt['state'] ?? null) !== 'rejected'
            || (array_key_exists('submit_kyc_log_id', $attempt) && ($attempt['submit_kyc_log_id'] ?? null) !== 106)) {
            throw new RuntimeException('Historical stakeholder generation #1 claim is not reconciled to log #106.');
        }

        foreach ([
            2 => [106, 'nium_stakeholder_submit_kyc_retry_generation_2'],
            3 => [113, 'nium_stakeholder_submit_kyc_retry_generation_3'],
            4 => [114, 'nium_stakeholder_submit_kyc_retry_generation_4'],
            5 => [115, 'nium_stakeholder_submit_kyc_retry_generation_5'],
        ] as $generation => [$previousLogId, $key]) {
            $claim = $metadata[$key] ?? null;
            if (! is_array($claim)
                || ($claim['state'] ?? null) !== 'rejected'
                || ($claim['previous_log_id'] ?? null) !== $previousLogId
                || ($claim['previous_http_status'] ?? null) !== 400) {
                throw new RuntimeException("Historical stakeholder generation #{$generation} claim is ambiguous or inconsistent.");
            }
        }

        if (array_key_exists(self::G6_CLAIM_KEY, $metadata)) {
            throw new RuntimeException('Generation #6 must remain proven never executed.');
        }
    }

    private function assertExpiredSessionOverride(array $metadata, int $providerId, string $customerId): void
    {
        $claim = $metadata[self::SESSION_CLAIM_KEY] ?? null;
        $log = ApiRequestLog::query()->find(117);
        if ($log === null || (int) $log->provider_id !== $providerId
            || $log->operation !== 'kyc_form_session_create' || $log->request_method !== 'POST'
            || (int) $log->response_status !== 200 || ! $log->is_success
            || $log->transport_outcome !== 'response_received' || ! is_array($claim)) {
            throw new RuntimeException('HOLD_PREBUILT_SESSION_OVERRIDE_EVIDENCE_INVALID');
        }

        $expiry = $claim['expiry_at'] ?? null;
        try {
            $expired = is_string($expiry) && CarbonImmutable::parse($expiry)->isPast();
        } catch (Throwable) {
            $expired = false;
        }
        if (! $expired) {
            throw new RuntimeException('HOLD_PREBUILT_SESSION_OVERRIDE_EXPIRY_NOT_PROVEN');
        }

        $statusEvents = WebhookEvent::query()->where('provider_id', $providerId)
            ->where('event_type', 'CUSTOMER_STATUS_WEBHOOK')->where('external_resource_id', $customerId)
            ->where('processing_status', 'processed')->whereNotNull('processed_at')
            ->orderByDesc('processed_at')->orderByDesc('id')->get();
        foreach ($statusEvents as $statusEvent) {
            $historicalStatus = (array) $statusEvent->payload;
            $subStatus = $historicalStatus['subStatus'] ?? null;
            if (($historicalStatus['status'] ?? null) === 'clear'
                && ($subStatus === null || (is_string($subStatus) && trim($subStatus) === ''))) {
                throw new RuntimeException('HOLD_KYC_COMPLETION_EVIDENCE_PRESENT');
            }
        }

        $status = (array) ($statusEvents->first()?->payload ?? []);
        if (($status['status'] ?? null) !== 'pending' || ($status['subStatus'] ?? null) !== 'awaiting_kyc') {
            throw new RuntimeException('HOLD_LOCAL_KYC_COMPLETION_OR_STATUS_UNPROVEN');
        }
    }

    private function mark(string $state, ?int $status, string $transport): void
    {
        DB::transaction(function () use ($state, $status, $transport): void {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->firstOrFail();
            $metadata = (array) $account->metadata;
            $metadata[self::CLAIM_KEY] = array_filter([
                ...($metadata[self::CLAIM_KEY] ?? []), 'state' => $state, 'provider_http_status' => $status,
                'transport_outcome' => $transport, 'updated_at' => now()->toISOString(),
            ], static fn (mixed $value): bool => $value !== null);
            $account->forceFill(['metadata' => $metadata])->save();
        });
    }

    private function finish(string $terminal, array $context, int $logMaxId, int $applicantPosts): array
    {
        $posts = ApiRequestLog::query()->where('id', '>', $logMaxId)->where('provider_id', $context['provider_id'])
            ->where('user_id', self::USER_ID)->where('operation', 'submit_kyc')->where('request_method', 'POST')
            ->where('external_reference', self::REFERENCE_ID)->count();
        $this->assertProtectedAccount($context['protected_fingerprint']);
        if ($posts > 1 || $this->applicantPostCount($context['provider_id']) !== $applicantPosts) {
            throw new RuntimeException('HOLD_APPLICANT_IMMUTABILITY_FAILURE');
        }

        return ['terminal' => $terminal, 'g7_post_count' => $posts, 'applicant_submit_kyc_post_count_change' => 0];
    }

    private function applicantPostCount(int $providerId): int
    {
        return ApiRequestLog::query()->where('provider_id', $providerId)->where('user_id', self::USER_ID)
            ->where('operation', 'submit_kyc')->where('request_method', 'POST')
            ->where('external_reference', '!=', self::REFERENCE_ID)->count();
    }

    private function validResponse(Response $response): bool
    {
        try {
            $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        return is_array($body) && strtolower((string) ($body['kycStatus'] ?? '')) === 'initiated'
            && strtolower((string) ($body['kycMode'] ?? '')) === 'manual_kyc'
            && strtolower((string) ($body['entityType'] ?? '')) === 'individual_stakeholder'
            && ($body['referenceId'] ?? null) === self::REFERENCE_ID;
    }

    private function passportFieldsPresent(array $payload): bool
    {
        $document = $payload['proofOfIdentityDocument'][0] ?? [];
        return is_array($document) && array_diff(
            ['fileIds', 'identificationNumber', 'expiryDate', 'issuanceCountry', 'type'], array_keys($document),
        ) === [];
    }

    private function assertProtectedAccount(string $fingerprint): void
    {
        if ($this->fingerprint(UserProviderAccount::query()->findOrFail(self::PROTECTED_ACCOUNT_ID)) !== $fingerprint) {
            throw new RuntimeException('Protected Account 4 changed.');
        }
    }

    private function fingerprint(UserProviderAccount $account): string
    {
        $attributes = $account->getRawOriginal();
        ksort($attributes);
        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    private function accountExecutionFingerprint(UserProviderAccount $account): string
    {
        return hash('sha256', json_encode([
            'id' => (int) $account->id,
            'provider_id' => (int) $account->provider_id,
            'user_id' => (int) $account->user_id,
            'external_customer_id' => (string) $account->external_customer_id,
            'external_account_id' => (string) $account->external_account_id,
            'reconciliation_status' => $account->reconciliation_status,
        ], JSON_THROW_ON_ERROR));
    }

    private function payloadFingerprint(array $payload): string
    {
        return substr(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 0, 16);
    }
}
