<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\KycProfile;
use App\Models\KycRelatedPerson;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
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
    ) {}

    public function submit(User $user, ?string $idempotencyKey = null): array
    {
        $context = $this->context($user, $idempotencyKey);
        $existingAttempt = $this->claim($context);

        if ($existingAttempt !== null) {
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
        if ($biometricUrl === null) {
            $biometricUrl = $this->biometricUrlResolver->resolve(
                $context['account'],
                $context['external_id'],
                $context['entity_reference_id'],
            );
        }
        $state = $this->validResponse($body, $context) ? 'accepted' : 'response_review';

        return $this->finish(
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
        ];
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
                'kyc_mode' => 'biometric_kyc',
                'entity_type' => $context['entity_type'],
                'external_id' => $context['external_id'],
                'manual_reference_id' => $context['reference_id'],
                'manual_admin_action' => true,
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
            $attempt = array_filter([
                'state' => $state,
                'kyc_status' => is_string($kycStatus) ? strtolower($kycStatus) : null,
                'kyc_mode' => is_string($kycMode) ? strtolower($kycMode) : 'biometric_kyc',
                'entity_type' => is_string($entityType) ? strtolower($entityType) : $context['entity_type'],
                'external_id' => is_string($externalId) ? $externalId : $context['external_id'],
                'manual_reference_id' => $context['reference_id'],
                'provider_http_status' => $httpStatus,
                'provider_reference_id' => is_string($providerReference) ? $providerReference : null,
                'biometric_url' => $biometricUrl,
                'biometric_url_fingerprint' => $biometricUrl !== null
                    ? substr(hash('sha256', $biometricUrl), 0, 16)
                    : null,
                'submit_kyc_log_id' => $log?->id,
                'submit_kyc_log_at' => $log?->created_at?->toISOString(),
                'manual_admin_action' => true,
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
            && strtolower((string) ($body['kycMode'] ?? '')) === 'biometric_kyc';
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
