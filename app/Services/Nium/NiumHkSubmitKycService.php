<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\KycProfile;
use App\Models\KycRelatedPerson;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class NiumHkSubmitKycService
{
    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumHkSubmitKycPayloadFactory $payloadFactory,
    ) {}

    public function submit(WebhookEvent $event): string
    {
        $context = $this->context($event);
        if (! $this->claim($context)) {
            return 'already_processed';
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
                user: $context['account']->user,
                operation: 'submit_kyc',
                externalReference: $context['reference_id'],
            );
        } catch (ConnectionException|NiumEvidencePersistenceException) {
            $this->mark($context, 'unknown');

            return 'unknown';
        } catch (Throwable $exception) {
            $log = $this->attemptLog($context);
            $state = $log !== null && (int) $log->response_status >= 400 && (int) $log->response_status < 500
                ? 'rejected'
                : 'unknown';
            $this->mark($context, $state, $log?->response_status);

            return $state;
        }

        if (! $response->successful()) {
            $state = $response->status() >= 400 && $response->status() < 500 ? 'rejected' : 'unknown';
            $this->mark($context, $state, $response->status());

            return $state;
        }

        $body = $this->responseObject($response);
        $state = $this->validResponse($body, $context) ? 'accepted' : 'response_review';
        $this->mark($context, $state, $response->status(), $body['redirectUrl'] ?? null, $body['referenceId'] ?? null);

        return $state;
    }

    /** Submit every person entity created by the current HK Corporate Full payload. */
    public function submitAwaitingKyc(WebhookEvent $event): array
    {
        $account = UserProviderAccount::query()->where('provider_id', $event->provider_id)
            ->where('external_customer_id', (string) data_get($event->payload, 'customerHashId'))->firstOrFail();
        $results = [];
        foreach ((array) data_get($account->metadata, 'nium_entity_kyc_states', []) as $state) {
            if (($state['kyc_status'] ?? null) !== 'kyc_required'
                || ! in_array($state['entity_type'] ?? null, ['applicant', 'individual_stakeholder'], true)
                || ! filled($state['external_id']) || ! filled($state['provider_reference_id'])) {
                continue;
            }
            $synthetic = new WebhookEvent([
                'provider_id' => $event->provider_id, 'event_type' => 'CUSTOMER_ENTITY_KYC_STATUS',
                'processing_status' => 'processed', 'processed_at' => $event->processed_at,
                'external_resource_id' => $account->external_customer_id,
                'payload' => ['customerHashId' => $account->external_customer_id, 'externalId' => $state['external_id'],
                    'entityType' => $state['entity_type'], 'referenceId' => $state['provider_reference_id'], 'kycStatus' => 'kyc_required'],
            ]);
            $results[$state['provider_reference_id']] = $this->submit($synthetic);
        }
        return $results;
    }

    public function reconcileEntityWebhook(WebhookEvent $event): void
    {
        $payload = (array) $event->payload;
        $account = UserProviderAccount::query()->where('provider_id', $event->provider_id)
            ->where('external_customer_id', (string) ($payload['customerHashId'] ?? $event->external_resource_id))->first();
        if ($account === null) {
            throw new RuntimeException('Nium entity webhook customer mapping is unknown.');
        }
        $metadata = (array) $account->metadata;
        foreach ((array) ($metadata['nium_submit_kyc_attempts'] ?? []) as $key => $attempt) {
            if (($attempt['entity_type'] ?? null) !== ($payload['entityType'] ?? null)
                || ($attempt['external_id'] ?? null) !== ($payload['externalId'] ?? null)) {
                continue;
            }
            $attempt['entity_kyc_status'] = $payload['kycStatus'] ?? null;
            $attempt['entity_status_updated_at'] = now()->toISOString();
            if (filled($payload['referenceId'] ?? null)) {
                $attempt['provider_reference_id'] = (string) $payload['referenceId'];
            }
            $metadata['nium_submit_kyc_attempts'][$key] = $attempt;
            $account->forceFill(['metadata' => $metadata])->save();
            return;
        }
    }

    private function context(WebhookEvent $event): array
    {
        $event->loadMissing('provider');
        $payload = (array) $event->payload;
        $entityType = trim((string) ($payload['entityType'] ?? ''));
        $externalId = trim((string) ($payload['externalId'] ?? ''));
        $referenceId = trim((string) ($payload['referenceId'] ?? ''));
        $customerHashId = trim((string) ($payload['customerHashId'] ?? $event->external_resource_id ?? ''));

        if (strtolower((string) $event->provider?->code) !== 'nium'
            || $event->event_type !== 'CUSTOMER_ENTITY_KYC_STATUS'
            || $event->processing_status !== 'processed'
            || ($payload['kycStatus'] ?? null) !== 'kyc_required'
            || ! in_array($entityType, ['applicant', 'individual_stakeholder'], true)
            || $externalId === ''
            || $referenceId === ''
            || $customerHashId === '') {
            throw new RuntimeException('Authenticated Nium kyc_required entity evidence is invalid.');
        }

        $accounts = UserProviderAccount::query()
            ->with(['user.kycProfile.relatedPersons', 'provider'])
            ->where('provider_id', $event->provider_id)
            ->where('external_customer_id', $customerHashId)
            ->get();

        if ($accounts->count() !== 1) {
            throw new RuntimeException('Nium entity evidence does not resolve to exactly one provider account.');
        }

        $account = $accounts->sole();
        $profile = $account->user?->kycProfile;

        if (! $profile instanceof KycProfile
            || ! in_array(strtolower((string) $profile->status), ['approved', 'verified'], true)
            || $profile->applicant_type !== 'business'
            || strtoupper((string) Arr::get((array) $profile->metadata, 'nium_region')) !== 'HK'
            || strtolower((string) Arr::get((array) $profile->metadata, 'nium_kyc_type')) !== 'full'
            || ! filled($account->external_customer_id)
            || $account->customer_id_verified_at === null
            || $account->reconciliation_status !== 'reconciled'
            || $account->security_conflict_at !== null
            || filled($account->security_conflict_reason)
            || ! hash_equals((string) $account->external_customer_id, $customerHashId)
            || (int) $account->user_id !== (int) $profile->user_id
            || (int) $account->provider_id !== (int) $event->provider_id) {
            throw new RuntimeException('Nium HK Corporate Full provider account context is not eligible for Submit KYC.');
        }

        $person = $this->resolvePerson($profile, $externalId, $entityType);

        return [
            'account' => $account,
            'event' => $event,
            'entity_type' => $entityType,
            'external_id' => $externalId,
            'reference_id' => $referenceId,
            'payload' => $this->payloadFactory->build($person, $entityType, $referenceId),
        ];
    }

    private function resolvePerson(KycProfile $profile, string $externalId, string $entityType): KycRelatedPerson
    {
        if (preg_match('/^origin-wallet-(person|applicant|stakeholder)-(\d+)$/', $externalId, $matches) !== 1) {
            throw new RuntimeException('Nium entity externalId has an unsupported format.');
        }

        $convention = $matches[1];
        if (($convention === 'applicant' && $entityType !== 'applicant')
            || ($convention === 'stakeholder' && $entityType !== 'individual_stakeholder')) {
            throw new RuntimeException('Nium entity externalId role conflicts with entityType.');
        }

        $people = $profile->relatedPersons->where('id', (int) $matches[2])->values();
        if ($people->count() !== 1) {
            throw new RuntimeException('Nium entity externalId does not resolve to exactly one approved-profile person.');
        }

        $person = $people->sole();
        $applicant = $this->corporateApplicant($profile);
        $isApplicant = $person->is($applicant);
        $relationship = strtolower(str_replace(['-', ' '], '_', trim((string) $person->relationship_type)));
        $isStakeholder = ! in_array($relationship, ['authorized_representative', 'authorised_representative'], true)
            && (! $isApplicant || $relationship === 'beneficial_owner');

        if (($entityType === 'applicant' && ! $isApplicant)
            || ($entityType === 'individual_stakeholder' && ! $isStakeholder)) {
            throw new RuntimeException('Nium entity role does not match the approved-profile person relationship.');
        }

        return $person;
    }

    private function corporateApplicant(KycProfile $profile): KycRelatedPerson
    {
        $applicant = $profile->relatedPersons->first(fn (KycRelatedPerson $person): bool =>
            strtolower((string) $person->relationship_type) === 'applicant'
            && $person->ownership_percentage !== null
            && (float) $person->ownership_percentage > 0);

        $applicant ??= $profile->relatedPersons->first(fn (KycRelatedPerson $person): bool =>
            strtolower((string) $person->relationship_type) === 'beneficial_owner'
            && $person->ownership_percentage !== null
            && (float) $person->ownership_percentage > 0);

        return $applicant ?? throw new RuntimeException('Approved corporate applicant cannot be resolved.');
    }

    private function claim(array $context): bool
    {
        return DB::transaction(function () use ($context): bool {
            $account = UserProviderAccount::query()->whereKey($context['account']->id)->lockForUpdate()->firstOrFail();
            $key = $this->attemptKey($account->external_customer_id, $context['entity_type'], $context['external_id']);
            $metadata = (array) $account->metadata;
            $priorPost = ApiRequestLog::query()
                ->where('provider_id', $account->provider_id)
                ->where('user_id', $account->user_id)
                ->where('operation', 'submit_kyc')
                ->where('request_method', 'POST')
                ->where('external_reference', $context['reference_id'])
                ->exists();

            if ($priorPost || Arr::has($metadata, 'nium_submit_kyc_attempts.'.$key)) {
                return false;
            }

            data_set($metadata, 'nium_submit_kyc_attempts.'.$key, [
                'state' => 'submitting',
                'kyc_mode' => 'biometric_kyc',
                'entity_type' => $context['entity_type'], 'external_id' => $context['external_id'],
                'webhook_id' => $context['event']->id,
                'webhook_processed_at' => $context['event']->processed_at?->toISOString(),
                'updated_at' => now()->toISOString(),
            ]);
            $account->forceFill(['metadata' => $metadata])->save();
            return true;
        }, 3);
    }

    private function mark(array $context, string $state, ?int $httpStatus = null, mixed $redirectUrl = null, ?string $providerReference = null): void
    {
        DB::transaction(function () use ($context, $state, $httpStatus, $redirectUrl, $providerReference): void {
            $account = UserProviderAccount::query()->whereKey($context['account']->id)->lockForUpdate()->firstOrFail();
            $metadata = (array) $account->metadata;
            $key = $this->attemptKey($account->external_customer_id, $context['entity_type'], $context['external_id']);
            $log = $this->attemptLog($context);
            data_set($metadata, 'nium_submit_kyc_attempts.'.$key, array_filter([
                'state' => $state,
                'kyc_mode' => 'biometric_kyc',
                'entity_type' => $context['entity_type'],
                'external_id' => $context['external_id'],
                'provider_http_status' => $httpStatus,
                'provider_reference_id' => $providerReference,
                'redirect_url_fingerprint' => is_string($redirectUrl) && trim($redirectUrl) !== ''
                    ? substr(hash('sha256', $redirectUrl), 0, 16)
                    : null,
                'submit_kyc_log_id' => $log?->id,
                'submit_kyc_log_at' => $log?->created_at?->toISOString(),
                'webhook_id' => $context['event']->id,
                'webhook_processed_at' => $context['event']->processed_at?->toISOString(),
                'updated_at' => now()->toISOString(),
            ], static fn (mixed $value): bool => $value !== null));
            $account->forceFill(['metadata' => $metadata])->save();
        }, 3);
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
        $providerReference = trim((string) ($body['referenceId'] ?? ''));
        $externalReferenceRequest = str_starts_with($context['reference_id'], 'origin-wallet-');
        return ($body['entityType'] ?? null) === $context['entity_type']
            && $providerReference !== ''
            && ($externalReferenceRequest || $providerReference === $context['reference_id'])
            && (! isset($body['externalId']) || $body['externalId'] === $context['external_id'])
            && in_array($body['kycStatus'] ?? null, ['initiated', 'submitted'], true)
            && ($body['kycMode'] ?? null) === 'biometric_kyc'
            && is_string($body['redirectUrl'] ?? null)
            && trim($body['redirectUrl']) !== '';
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

    private function attemptKey(string $customerId, string $entityType, string $externalId): string
    {
        return 'entity_'.substr(hash('sha256', $customerId.'|'.$entityType.'|'.$externalId), 0, 24);
    }
}
