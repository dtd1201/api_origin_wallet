<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\KycDocument;
use App\Models\KycProfile;
use App\Models\KycProfileCorrection;
use App\Models\KycRelatedPerson;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class NiumHkManualSubmitKycService
{
    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumHkSubmitKycPayloadFactory $payloadFactory,
        private readonly NiumSubmitKycBiometricUrlResolver $biometricUrlResolver,
        private readonly NiumCustomerDocumentPreparationService $documentPreparation,
    ) {}

    public function submit(User $user, ?string $idempotencyKey = null): array
    {
        $context = $this->context($user, $idempotencyKey);
        $existingAttempt = $this->claim($context);

        if ($existingAttempt !== null) {
            if (($context['kyc_mode'] ?? null) === 'manual_kyc'
                && ($existingAttempt['state'] ?? null) === 'accepted') {
                $this->recordComplianceReviewPending($context, $existingAttempt);
            }

            return $existingAttempt;
        }

        if (isset($context['recovered_applicant'])) {
            $applicant = $context['recovered_applicant'];

            return $this->finish(
                $context,
                'accepted',
                providerReference: $applicant['referenceId'] ?? null,
                kycStatus: $applicant['kycStatus'] ?? null,
                kycMode: $applicant['kycMode'] ?? null,
                entityType: 'applicant',
                externalId: $applicant['externalId'] ?? null,
                biometricUrl: $applicant['biometricUrl'],
            );
        }

        try {
            $response = $this->niumService->post(
                path: $this->niumService->path(
                    (string) config('services.nium.customer_submit_kyc_endpoint'),
                    [
                        'client' => $this->niumService->clientId(),
                        'customer' => $context['account']->external_customer_id,
                    ],
                ),
                payload: $context['payload'],
                user: $user,
                operation: 'submit_kyc',
                externalReference: $context['reference_id'],
            );
        } catch (ConnectionException|NiumEvidencePersistenceException) {
            return $this->finish($context, 'unknown');
        } catch (Throwable) {
            $log = $this->attemptLog($context);
            $state = $log !== null && (int) $log->response_status >= 400 && (int) $log->response_status < 500
                ? 'rejected'
                : 'unknown';

            return $this->finish($context, $state, $log?->response_status);
        }

        if (! $response->successful()) {
            return $this->finish(
                $context,
                $response->status() >= 400 && $response->status() < 500 ? 'rejected' : 'unknown',
                $response->status(),
            );
        }

        $body = $this->responseObject($response);
        $biometricUrl = $body['biometricUrl'] ?? $body['redirectUrl'] ?? null;
        $biometricUrl = is_string($biometricUrl) && trim($biometricUrl) !== '' ? $biometricUrl : null;
        if ($biometricUrl === null && ($context['kyc_mode'] ?? 'biometric_kyc') === 'biometric_kyc') {
            $biometricUrl = $this->biometricUrlResolver->resolve(
                $context['account'],
                $context['external_id'],
                $context['entity_reference_id'],
            );
        }
        $state = $this->validResponse($body, $context) ? 'accepted' : 'response_review';

        $attempt = $this->finish(
            $context,
            $state,
            $response->status(),
            $body['referenceId'] ?? null,
            $body['kycStatus'] ?? null,
            $body['kycMode'] ?? null,
            $body['entityType'] ?? null,
            $body['externalId'] ?? null,
            $biometricUrl,
        );
        if (($context['kyc_mode'] ?? null) === 'manual_kyc' && $state === 'accepted') {
            $this->recordComplianceReviewPending($context, $attempt);
        }

        return $attempt;
    }

    private function context(User $user, ?string $idempotencyKey): array
    {
        $accounts = UserProviderAccount::query()
            ->with(['provider', 'user.kycProfile.relatedPersons'])
            ->where('user_id', $user->id)
            ->whereHas('provider', fn ($query) => $query->whereRaw('LOWER(code) = ?', ['nium']))
            ->whereNotNull('external_customer_id')
            ->get();

        if ($accounts->count() !== 1) {
            throw new RuntimeException('The customer does not have exactly one existing Nium customer account.');
        }

        $account = $accounts->sole();
        $profile = $account->user?->kycProfile;
        if (! $profile instanceof KycProfile
            || ! in_array(strtolower((string) $profile->status), ['approved', 'verified'], true)
            || $profile->applicant_type !== 'business'
            || strtoupper((string) Arr::get((array) $profile->metadata, 'nium_region')) !== 'HK'
            || strtolower((string) Arr::get((array) $profile->metadata, 'nium_kyc_type')) !== 'full'
            || $account->customer_id_verified_at === null
            || $account->reconciliation_status !== 'reconciled'
            || $account->security_conflict_at !== null
            || filled($account->security_conflict_reason)) {
            throw new RuntimeException('The existing Nium customer is not eligible for manual HK Submit KYC.');
        }

        $correction = $profile->corrections()->latest('id')->first();
        if ($correction !== null && in_array($correction->status, [
            'manual_kyc_evidence_approved', 'manual_kyc_compliance_review',
        ], true)) {
            return $this->manualComplianceContext($account, $profile, $correction, $idempotencyKey);
        }
        if ($correction !== null && $correction->status === 'submitted' && $this->hasUnappliedChanges($correction)) {
            throw new RuntimeException(
                'The submitted applicant correction is not applied to the verified Nium customer; review and synchronize it before manual Submit KYC.',
            );
        }
        if ($correction !== null && in_array($correction->status, [
            'approved', 'syncing', 'verification_pending', 'sync_failed', 'unknown',
        ], true)) {
            throw new RuntimeException('The approved applicant correction must be verified as synced before manual Submit KYC.');
        }

        $person = $this->corporateApplicant($profile);
        $entityStates = collect((array) Arr::get((array) $account->metadata, 'nium_entity_kyc_states', []));
        $entities = $entityStates
            ->filter(fn (mixed $entity): bool => is_array($entity)
                && ($entity['entity_type'] ?? null) === 'applicant'
                && in_array($entity['kyc_status'] ?? null, ['kyc_required', 'retry'], true)
                && ($entity['kyc_mode'] ?? null) === 'biometric_kyc'
                && filled($entity['external_id'] ?? null)
                && filled($entity['provider_reference_id'] ?? null))
            ->values();

        if ($entities->count() > 1) {
            throw new RuntimeException('No unique eligible Nium applicant entity is available for manual Submit KYC.');
        }

        if ($entities->isEmpty()) {
            $retryContext = $this->retryEvidenceContext($account, $profile, $person, $idempotencyKey);
            if ($retryContext !== null) {
                return $retryContext;
            }

            return $this->recoveredContext($account, $person, $entityStates, $idempotencyKey);
        }

        $entity = $entities->sole();
        $externalId = trim((string) $entity['external_id']);
        $entityReferenceId = trim((string) $entity['provider_reference_id']);
        if (preg_match('/^origin-wallet-(?:person|applicant)-(\d+)$/', $externalId, $matches) !== 1) {
            throw new RuntimeException('No verified Nium applicant entity is available for manual Submit KYC.');
        }

        $people = $profile->relatedPersons->where('id', (int) $matches[1])->values();
        if ($people->count() !== 1) {
            throw new RuntimeException('The Nium applicant entity does not match the approved KYC profile.');
        }

        $resolvedPerson = $people->sole();
        if (! $resolvedPerson->is($person)) {
            throw new RuntimeException('The selected Nium entity is not the approved corporate applicant.');
        }

        $referenceId = $this->manualReferenceId($user, $idempotencyKey);

        return [
            'account' => $account,
            'entity_type' => 'applicant',
            'external_id' => $externalId,
            'reference_id' => $referenceId,
            'entity_reference_id' => $entityReferenceId,
            'payload' => $this->payloadFactory->build($resolvedPerson, 'applicant', $entityReferenceId),
            'kyc_mode' => 'biometric_kyc',
        ];
    }

    private function recoveredContext(
        UserProviderAccount $account,
        KycRelatedPerson $person,
        Collection $entityStates,
        ?string $idempotencyKey,
    ): array {
        $localApplicants = $entityStates
            ->filter(fn (mixed $entity): bool => is_array($entity)
                && ($entity['entity_type'] ?? null) === 'applicant'
                && filled($entity['external_id'] ?? null))
            ->values();
        if ($localApplicants->count() > 1) {
            throw new RuntimeException('No unique eligible Nium applicant entity is available for manual Submit KYC.');
        }
        $localApplicant = $localApplicants->count() === 1 ? $localApplicants->sole() : null;
        $externalId = is_array($localApplicant)
            ? trim((string) $localApplicant['external_id'])
            : 'origin-wallet-applicant-'.$person->id;
        $providerReferenceId = is_array($localApplicant)
            && filled($localApplicant['provider_reference_id'] ?? null)
                ? trim((string) $localApplicant['provider_reference_id'])
                : null;
        $applicant = $this->biometricUrlResolver->resolveApplicant(
            $account,
            $externalId,
            $providerReferenceId,
        );
        $biometricUrl = $applicant['biometricUrl'] ?? null;
        $kycStatus = strtolower((string) ($applicant['kycStatus'] ?? ''));

        if (! is_array($applicant)
            || ! in_array($kycStatus, ['kyc_required', 'retry', 'initiated'], true)
            || strtolower((string) ($applicant['entityType'] ?? '')) !== 'applicant'
            || strtolower((string) ($applicant['kycMode'] ?? '')) !== 'biometric_kyc'
            || ($kycStatus === 'initiated' && (! is_string($biometricUrl) || trim($biometricUrl) === ''))) {
            throw new RuntimeException('No unique eligible Nium applicant entity is available for manual Submit KYC.');
        }

        $remoteReferenceId = trim((string) ($applicant['referenceId'] ?? ''));
        if ($remoteReferenceId === '') {
            throw new RuntimeException('No unique eligible Nium applicant entity is available for manual Submit KYC.');
        }

        $context = [
            'account' => $account,
            'entity_type' => 'applicant',
            'external_id' => $externalId,
            'reference_id' => $this->manualReferenceId($account->user, $idempotencyKey),
            'entity_reference_id' => $remoteReferenceId,
            'kyc_mode' => 'biometric_kyc',
        ];

        if ($kycStatus === 'initiated') {
            $context['recovered_applicant'] = $applicant;

            return $context;
        }

        $context['payload'] = $this->payloadFactory->build($person, 'applicant', $remoteReferenceId);

        return $context;
    }

    private function retryEvidenceContext(
        UserProviderAccount $account,
        KycProfile $profile,
        KycRelatedPerson $person,
        ?string $idempotencyKey,
    ): ?array {
        $event = WebhookEvent::query()
            ->where('provider_id', $account->provider_id)
            ->where('event_type', 'CUSTOMER_ENTITY_KYC_STATUS')
            ->where('external_resource_id', $account->external_customer_id)
            ->where('processing_status', 'processed')
            ->whereNotNull('processed_at')
            ->orderByDesc('processed_at')
            ->orderByDesc('id')
            ->first();
        $payload = (array) ($event?->payload ?? []);

        if (($payload['kycStatus'] ?? null) !== 'retry') {
            return null;
        }

        $externalId = trim((string) ($payload['externalId'] ?? ''));
        $providerReferenceId = trim((string) ($payload['referenceId'] ?? ''));
        if (($payload['entityType'] ?? null) !== 'applicant'
            || ($payload['kycMode'] ?? null) !== 'biometric_kyc'
            || $externalId === ''
            || $providerReferenceId === '') {
            throw new RuntimeException('No unique eligible Nium applicant entity is available for manual Submit KYC.');
        }

        $resolvedPerson = $this->personForExternalId($profile, $externalId);
        if (! $resolvedPerson->is($person)) {
            throw new RuntimeException('No unique eligible Nium applicant entity is available for manual Submit KYC.');
        }

        $matchingAttempts = collect((array) Arr::get((array) $account->metadata, 'nium_submit_kyc_attempts', []))
            ->filter(fn (mixed $attempt): bool => is_array($attempt)
                && ($attempt['state'] ?? null) === 'accepted'
                && ($attempt['entity_type'] ?? null) === 'applicant'
                && ($attempt['kyc_mode'] ?? null) === 'biometric_kyc'
                && ($attempt['entity_kyc_status'] ?? null) === 'retry'
                && hash_equals($externalId, trim((string) ($attempt['external_id'] ?? '')))
                && hash_equals($providerReferenceId, trim((string) ($attempt['provider_reference_id'] ?? ''))))
            ->values();

        if ($matchingAttempts->isEmpty()) {
            throw new RuntimeException('No unique eligible Nium applicant entity is available for manual Submit KYC.');
        }

        return [
            'account' => $account,
            'entity_type' => 'applicant',
            'external_id' => $externalId,
            'reference_id' => $this->manualReferenceId($account->user, $idempotencyKey),
            'entity_reference_id' => $providerReferenceId,
            'payload' => $this->payloadFactory->build($resolvedPerson, 'applicant', $providerReferenceId),
            'kyc_mode' => 'biometric_kyc',
        ];
    }

    private function manualComplianceContext(
        UserProviderAccount $account,
        KycProfile $profile,
        KycProfileCorrection $correction,
        ?string $idempotencyKey,
    ): array {
        $person = $this->corporateApplicant($profile);
        $customerHashId = trim((string) $correction->customer_hash_id);
        $externalId = trim((string) $correction->applicant_external_id);
        $referenceId = trim((string) $correction->applicant_reference_id);
        if ((int) $correction->user_provider_account_id !== (int) $account->id
            || (int) $correction->applicant_related_person_id !== (int) $person->id
            || $customerHashId === '' || ! hash_equals($customerHashId, (string) $account->external_customer_id)
            || ! hash_equals((string) $correction->customer_hash_fingerprint, hash('sha256', $customerHashId))
            || $externalId === '' || ! hash_equals((string) $correction->applicant_external_fingerprint, hash('sha256', $externalId))
            || $referenceId === '' || ! hash_equals((string) $correction->applicant_reference_fingerprint, hash('sha256', $referenceId))) {
            throw new RuntimeException('The manual KYC correction no longer matches the verified Nium customer and applicant bindings.');
        }

        $states = collect((array) Arr::get((array) $account->metadata, 'nium_entity_kyc_states', []))
            ->filter(fn (mixed $state): bool => is_array($state)
                && ($state['entity_type'] ?? null) === 'applicant'
                && hash_equals($externalId, trim((string) ($state['external_id'] ?? '')))
                && hash_equals($referenceId, trim((string) ($state['provider_reference_id'] ?? '')))
                && in_array($state['kyc_status'] ?? null, ['kyc_required', 'retry'], true));
        if ($states->count() > 1
            || ($states->isEmpty() && ! $this->hasVerifiedManualCorrectionRetryEvidence(
                $account,
                $customerHashId,
                $externalId,
                $referenceId,
            ))) {
            throw new RuntimeException('No unique eligible Nium applicant entity matches the reviewed manual KYC correction.');
        }

        $document = $person->documents()->get()
            ->first(fn (KycDocument $candidate): bool => (int) data_get($candidate->metadata, 'source_correction_id') === (int) $correction->id
                && data_get($candidate->metadata, 'source') === 'admin_reviewed_correction'
                && $candidate->status === 'manual_kyc_evidence_approved'
            );
        if (! $document instanceof KycDocument) {
            throw new RuntimeException('The reviewed correction passport is not available as a canonical KYC document.');
        }
        if ($this->documentPreparation->prepareManualCorrectionDocument($document, $account->user) !== 'AVAILABLE') {
            throw new RuntimeException('The reviewed passport is not yet AVAILABLE in the Nium File API.');
        }

        return [
            'account' => $account,
            'entity_type' => 'applicant',
            'external_id' => $externalId,
            'reference_id' => 'origin-wallet-manual-correction-'.substr(hash('sha256', $account->user_id.'|'.$correction->id.'|'.$correction->correction_version), 0, 32),
            'entity_reference_id' => $referenceId,
            'payload' => $this->payloadFactory->buildApplicantManual($document->fresh(), $referenceId),
            'kyc_mode' => 'manual_kyc',
            'correction_id' => $correction->id,
            'correction_version' => $correction->correction_version,
        ];
    }

    private function hasVerifiedManualCorrectionRetryEvidence(
        UserProviderAccount $account,
        string $customerHashId,
        string $externalId,
        string $referenceId,
    ): bool {
        $events = WebhookEvent::query()
            ->where('provider_id', $account->provider_id)
            ->where('event_type', 'CUSTOMER_ENTITY_KYC_STATUS')
            ->where('external_resource_id', $customerHashId)
            ->where('processing_status', 'processed')
            ->whereNotNull('processed_at')
            ->orderBy('processed_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (WebhookEvent $event): bool => ($event->payload['customerHashId'] ?? null) === $customerHashId
                && ($event->payload['entityType'] ?? null) === 'applicant')
            ->values();

        $retryApplicants = $events
            ->filter(fn (WebhookEvent $event): bool => ($event->payload['kycStatus'] ?? null) === 'retry'
                && ($event->payload['kycMode'] ?? null) === 'biometric_kyc')
            ->map(fn (WebhookEvent $event): string => trim((string) ($event->payload['externalId'] ?? ''))
                .'|'.trim((string) ($event->payload['referenceId'] ?? '')))
            ->filter(fn (string $identity): bool => $identity !== '|')
            ->unique()
            ->values();
        if ($retryApplicants->count() !== 1
            || ! hash_equals($externalId.'|'.$referenceId, $retryApplicants->sole())) {
            return false;
        }

        $applicantEvents = $events->filter(fn (WebhookEvent $event): bool =>
            hash_equals($externalId, trim((string) ($event->payload['externalId'] ?? '')))
            && hash_equals($referenceId, trim((string) ($event->payload['referenceId'] ?? '')))
        )->values();
        if ($applicantEvents->isEmpty()
            || ($applicantEvents->last()->payload['kycStatus'] ?? null) !== 'retry'
            || ($applicantEvents->last()->payload['kycMode'] ?? null) !== 'biometric_kyc') {
            return false;
        }
        $retryEvents = $applicantEvents->filter(fn (WebhookEvent $event): bool =>
            ($event->payload['kycStatus'] ?? null) === 'retry'
            && ($event->payload['kycMode'] ?? null) === 'biometric_kyc'
        )->values();
        if ($retryEvents->count() !== 1) {
            return false;
        }
        $retryEvent = $retryEvents->sole();

        $attempts = collect((array) Arr::get((array) $account->metadata, 'nium_submit_kyc_attempts', []))
            ->filter(fn (mixed $attempt): bool => is_array($attempt)
                && ($attempt['state'] ?? null) === 'accepted'
                && (int) ($attempt['provider_http_status'] ?? 0) === 200
                && ($attempt['entity_type'] ?? null) === 'applicant'
                && ($attempt['kyc_mode'] ?? null) === 'biometric_kyc'
                && ($attempt['entity_kyc_status'] ?? null) === 'retry'
                && hash_equals($externalId, trim((string) ($attempt['external_id'] ?? '')))
                && hash_equals($referenceId, trim((string) ($attempt['provider_reference_id'] ?? ''))))
            ->values();
        if ($attempts->count() !== 1) {
            return false;
        }

        $attempt = $attempts->sole();
        if (! is_string($attempt['webhook_processed_at'] ?? null)
            || ! is_string($attempt['entity_status_updated_at'] ?? null)) {
            return false;
        }

        try {
            $attemptWebhookProcessedAt = CarbonImmutable::parse($attempt['webhook_processed_at']);
            $entityStatusUpdatedAt = CarbonImmutable::parse($attempt['entity_status_updated_at']);
        } catch (Throwable) {
            return false;
        }

        if ($entityStatusUpdatedAt->lessThan($attemptWebhookProcessedAt)
            || $retryEvent->processed_at->lessThan($entityStatusUpdatedAt)
            || $retryEvent->processed_at->diffInMinutes($entityStatusUpdatedAt) > 5) {
            return false;
        }

        $precedingEvents = $applicantEvents->filter(fn (WebhookEvent $event): bool =>
            $event->id !== $retryEvent->id
            && $event->processed_at->lessThan($retryEvent->processed_at)
        );
        if ($precedingEvents->contains(fn (WebhookEvent $event): bool =>
            $event->processed_at->lessThan($attemptWebhookProcessedAt)
                ? false
                : $event->processed_at->greaterThan($entityStatusUpdatedAt)
        )) {
            return false;
        }

        if (array_key_exists('webhook_id', $attempt)) {
            $originWebhookId = filter_var($attempt['webhook_id'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($originWebhookId === false) {
                return false;
            }

            $originEvent = $applicantEvents->firstWhere('id', $originWebhookId);
            if (! $originEvent instanceof WebhookEvent
                || ! $attemptWebhookProcessedAt->equalTo($originEvent->processed_at)
                || ($originEvent->payload['kycMode'] ?? null) !== 'biometric_kyc'
                || ! in_array($originEvent->payload['kycStatus'] ?? null, ['kyc_required', 'submitted', 'retry'], true)) {
                return false;
            }
        }

        return true;
    }

    private function personForExternalId(KycProfile $profile, string $externalId): KycRelatedPerson
    {
        if (preg_match('/^origin-wallet-(?:person|applicant)-(\d+)$/', $externalId, $matches) !== 1) {
            throw new RuntimeException('No verified Nium applicant entity is available for manual Submit KYC.');
        }

        $people = $profile->relatedPersons->where('id', (int) $matches[1])->values();

        return $people->count() === 1
            ? $people->sole()
            : throw new RuntimeException('The Nium applicant entity does not match the approved KYC profile.');
    }

    private function corporateApplicant(KycProfile $profile): KycRelatedPerson
    {
        $applicant = $profile->relatedPersons->first(fn (KycRelatedPerson $person): bool => strtolower((string) $person->relationship_type) === 'applicant'
            && $person->ownership_percentage !== null
            && (float) $person->ownership_percentage > 0);
        $applicant ??= $profile->relatedPersons->first(fn (KycRelatedPerson $person): bool => strtolower((string) $person->relationship_type) === 'beneficial_owner'
            && $person->ownership_percentage !== null
            && (float) $person->ownership_percentage > 0);

        return $applicant ?? throw new RuntimeException('The approved corporate applicant cannot be resolved.');
    }

    private function hasUnappliedChanges(KycProfileCorrection $correction): bool
    {
        if (collect((array) $correction->proposed_documents)->contains(
            fn (mixed $document): bool => is_array($document) && filled($document['file_path'] ?? null),
        )) {
            return true;
        }

        $before = (array) $correction->before_snapshot;
        $proposed = (array) $correction->proposed_values;

        foreach (['legal_name', 'first_name', 'last_name', 'date_of_birth'] as $field) {
            if (array_key_exists($field, $proposed) && ($proposed[$field] ?? null) !== ($before[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function claim(array $context): ?array
    {
        return DB::transaction(function () use ($context): ?array {
            $account = UserProviderAccount::query()->whereKey($context['account']->id)->lockForUpdate()->firstOrFail();
            $metadata = (array) $account->metadata;
            $key = $this->attemptKey($context['reference_id']);
            $existing = data_get($metadata, 'nium_submit_kyc_attempts.'.$key);

            if (is_array($existing)) {
                if (($existing['entity_type'] ?? null) !== $context['entity_type']
                    || ($existing['external_id'] ?? null) !== $context['external_id']) {
                    throw new RuntimeException('The Submit KYC idempotency key is already bound to another entity.');
                }

                return $existing;
            }

            data_set($metadata, 'nium_submit_kyc_attempts.'.$key, [
                'state' => 'submitting',
                'kyc_mode' => $context['kyc_mode'] ?? 'biometric_kyc',
                'entity_type' => $context['entity_type'],
                'external_id' => $context['external_id'],
                'manual_reference_id' => $context['reference_id'],
                'manual_admin_action' => true,
                'kyc_profile_correction_id' => $context['correction_id'] ?? null,
                'updated_at' => now()->toISOString(),
            ]);
            $account->forceFill(['metadata' => $metadata])->save();

            return null;
        }, 3);
    }

    private function finish(
        array $context,
        string $state,
        ?int $httpStatus = null,
        mixed $providerReference = null,
        mixed $kycStatus = null,
        mixed $kycMode = null,
        mixed $entityType = null,
        mixed $externalId = null,
        ?string $biometricUrl = null,
    ): array {
        $attempt = DB::transaction(function () use ($context, $state, $httpStatus, $providerReference, $kycStatus, $kycMode, $entityType, $externalId, $biometricUrl): array {
            $account = UserProviderAccount::query()->whereKey($context['account']->id)->lockForUpdate()->firstOrFail();
            $metadata = (array) $account->metadata;
            $log = $this->attemptLog($context);
            $trustedResponse = $state === 'accepted';
            $attempt = array_filter([
                'state' => $state,
                'kyc_status' => is_string($kycStatus) ? strtolower($kycStatus) : null,
                'kyc_mode' => $trustedResponse && is_string($kycMode)
                    ? strtolower($kycMode)
                    : ($context['kyc_mode'] ?? 'biometric_kyc'),
                'entity_type' => $trustedResponse && is_string($entityType)
                    ? strtolower($entityType)
                    : $context['entity_type'],
                'external_id' => $trustedResponse && is_string($externalId)
                    ? $externalId
                    : $context['external_id'],
                'manual_reference_id' => $context['reference_id'],
                'provider_http_status' => $httpStatus,
                'provider_reference_id' => $trustedResponse && is_string($providerReference) ? $providerReference : null,
                'biometric_url' => $biometricUrl,
                'biometric_url_fingerprint' => $biometricUrl !== null
                    ? substr(hash('sha256', $biometricUrl), 0, 16)
                    : null,
                'submit_kyc_log_id' => $log?->id,
                'submit_kyc_log_at' => $log?->created_at?->toISOString(),
                'manual_admin_action' => true,
                'kyc_profile_correction_id' => $context['correction_id'] ?? null,
                'updated_at' => now()->toISOString(),
            ], static fn (mixed $value): bool => $value !== null);
            data_set($metadata, 'nium_submit_kyc_attempts.'.$this->attemptKey($context['reference_id']), $attempt);
            $account->forceFill(['metadata' => $metadata])->save();

            return $attempt;
        }, 3);

        return $attempt;
    }

    private function attemptLog(array $context): ?ApiRequestLog
    {
        return ApiRequestLog::query()
            ->where('provider_id', $context['account']->provider_id)
            ->where('user_id', $context['account']->user_id)
            ->where('operation', 'submit_kyc')
            ->where('request_method', 'POST')
            ->where('external_reference', $context['reference_id'])
            ->latest('id')
            ->first();
    }

    private function validResponse(array $body, array $context): bool
    {
        return strtolower((string) ($body['entityType'] ?? '')) === $context['entity_type']
            && filled($body['referenceId'] ?? null)
            && (! isset($body['externalId']) || $body['externalId'] === $context['external_id'])
            && in_array(strtolower((string) ($body['kycStatus'] ?? '')), ['initiated', 'submitted'], true)
            && strtolower((string) ($body['kycMode'] ?? '')) === ($context['kyc_mode'] ?? 'biometric_kyc');
    }

    private function recordComplianceReviewPending(array $context, array $attempt): void
    {
        DB::transaction(function () use ($context, $attempt): void {
            $correction = KycProfileCorrection::query()->whereKey($context['correction_id'])
                ->lockForUpdate()->firstOrFail();
            if ((int) $correction->correction_version !== (int) $context['correction_version']) {
                throw new RuntimeException('The manual KYC correction version changed before provider submission was recorded.');
            }
            if (! in_array($correction->status, ['manual_kyc_evidence_approved', 'manual_kyc_compliance_review'], true)) {
                throw new RuntimeException('The manual KYC correction is no longer eligible for Compliance review.');
            }
            if ($correction->status === 'manual_kyc_compliance_review') {
                return;
            }

            $correction->update(['status' => 'manual_kyc_compliance_review']);
            AuditLog::query()->create([
                'user_id' => null,
                'action' => 'kyc.manual_submission_pending_nium_compliance_review',
                'entity_type' => 'kyc_profile_correction',
                'entity_id' => (string) $context['correction_id'],
                'old_data' => ['status' => 'manual_kyc_evidence_approved'],
                'new_data' => [
                    'status' => 'manual_kyc_compliance_review',
                    'provider_account_id' => $context['account']->id,
                    'attempt_reference_fingerprint' => substr(hash('sha256', (string) $context['reference_id']), 0, 16),
                    'provider_http_status' => $attempt['provider_http_status'] ?? null,
                    'operator_notification_required' => true,
                ],
            ]);
        }, 3);
    }

    private function responseObject(Response $response): array
    {
        try {
            $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($body) && ! array_is_list($body) ? $body : [];
    }

    private function attemptKey(string $referenceId): string
    {
        return 'manual_'.substr(hash('sha256', $referenceId), 0, 24);
    }

    private function manualReferenceId(User $user, ?string $idempotencyKey): string
    {
        $idempotencyKey = trim((string) $idempotencyKey);

        if ($idempotencyKey === '') {
            return 'origin-wallet-manual-'.Str::uuid();
        }

        return 'origin-wallet-manual-'.substr(hash('sha256', $user->id.'|'.$idempotencyKey), 0, 32);
    }
}
