<?php

namespace App\Services\Kyc;

use App\Models\AuditLog;
use App\Models\KycCorrectionSyncAttempt;
use App\Models\KycProfileCorrection;
use App\Models\KycRelatedPerson;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumEvidencePersistenceException;
use App\Services\Nium\NiumHkCorporateCustomerUpdatePayloadFactory;
use App\Services\Nium\NiumService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class AdminKycCorrectionSyncService
{
    public function __construct(
        private readonly NiumService $nium,
        private readonly NiumHkCorporateCustomerUpdatePayloadFactory $payloadFactory,
        private readonly KycCorrectionSyncPolicy $syncPolicy,
        private readonly KycCorrectionApprovedSource $approvedSource,
    ) {}

    public function capability(KycProfileCorrection $correction): array
    {
        if ($correction->status !== 'approved') {
            return ['can_sync' => false, 'reason' => 'Only an approved correction can be synchronized.'];
        }
        $policy = $this->syncPolicy->evaluate($correction->providerAccount);
        if (! $policy['allowed']) {
            return ['can_sync' => false, 'reason' => $policy['reason']];
        }

        return ['can_sync' => true, 'reason' => null];
    }

    public function sync(User $customer, KycProfileCorrection $correction, User $operator): KycProfileCorrection
    {
        $claim = DB::transaction(function () use ($customer, $correction, $operator): array {
            $locked = KycProfileCorrection::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();
            $account = UserProviderAccount::query()->whereKey($locked->user_provider_account_id)->lockForUpdate()->firstOrFail();
            $applicant = KycRelatedPerson::query()->whereKey($locked->applicant_related_person_id)->lockForUpdate()->firstOrFail();
            $this->assertBinding($customer, $locked, $account, $applicant);
            $this->assertApprovedSource($locked);
            $capability = $this->capability($locked->setRelation('providerAccount', $account));
            if (! $capability['can_sync']) {
                throw ValidationException::withMessages(['correction' => $capability['reason']]);
            }
            if ($locked->syncAttempts()->where('active_slot', 1)->exists()) {
                throw ValidationException::withMessages(['correction' => 'This correction already has an active or unresolved Nium update attempt.']);
            }

            $payload = $this->payloadFactory->build($customer, $locked->setRelation('providerAccount', $account));
            $requestId = (string) Str::uuid();
            $attempt = $locked->syncAttempts()->create([
                'correction_version' => $locked->correction_version,
                'request_id' => $requestId,
                'request_fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                'status' => 'submitting', 'active_slot' => 1, 'started_by_user_id' => $operator->id, 'started_at' => now(),
            ]);
            $locked->update(['status' => 'syncing']);

            return compact('locked', 'account', 'payload', 'requestId', 'attempt');
        }, 3);

        try {
            $response = $this->nium->putWithRequestId(
                $this->nium->path((string) config('services.nium.customer_update_endpoint'), [
                    'client' => $this->nium->clientId(), 'customer' => $claim['account']->external_customer_id,
                ]),
                $claim['payload'], $customer, $claim['requestId'], 'customer_update',
                (string) $claim['account']->external_reference,
            );
        } catch (ConnectionException|NiumEvidencePersistenceException $exception) {
            $this->finishAttempt($claim['attempt'], $claim['locked'], 'unknown', null, 'ambiguous_write_outcome');
            throw $exception;
        } catch (Throwable $exception) {
            $this->finishAttempt($claim['attempt'], $claim['locked'], 'unknown', null, 'unexpected_write_outcome');
            throw $exception;
        }

        if (! $response->successful()) {
            $ambiguous = $response->status() >= 500;
            $this->finishAttempt(
                $claim['attempt'], $claim['locked'], $ambiguous ? 'unknown' : 'sync_failed',
                $response->status(), $ambiguous ? 'provider_5xx' : 'provider_rejected',
            );
            throw new RuntimeException($ambiguous
                ? 'Nium Customer Update outcome is unknown and requires reconciliation.'
                : 'Nium Customer Update was rejected.');
        }

        $claim['attempt']->update(['status' => 'verification_pending', 'http_status' => $response->status(), 'put_completed_at' => now()]);
        $claim['locked']->update(['status' => 'verification_pending']);

        return $this->verify($customer, $claim['locked']->fresh(), $claim['attempt']->fresh(), $operator);
    }

    public function reconcile(User $customer, KycProfileCorrection $correction, User $operator): KycProfileCorrection
    {
        $attempt = $correction->syncAttempts()->where('active_slot', 1)->latest('id')->firstOrFail();
        if (! in_array($attempt->status, ['verification_pending', 'unknown'], true)) {
            throw ValidationException::withMessages(['correction' => 'This attempt does not require reconciliation.']);
        }

        return $this->verify($customer, $correction, $attempt, $operator);
    }

    public function resolveDeterministicFailure(
        User $customer,
        KycProfileCorrection $correction,
        User $operator,
        string $expectedRequestId,
        string $expectedRequestFingerprint,
        string $reason,
    ): KycProfileCorrection {
        return DB::transaction(function () use ($customer, $correction, $operator, $expectedRequestId, $expectedRequestFingerprint, $reason): KycProfileCorrection {
            $locked = KycProfileCorrection::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->kycProfile?->user_id !== (int) $customer->id || $locked->status !== 'sync_failed') {
                throw ValidationException::withMessages(['correction' => 'This correction does not have a recoverable deterministic failure.']);
            }
            $attempt = $locked->syncAttempts()->where('active_slot', 1)->lockForUpdate()->latest('id')->first();
            if (! $attempt instanceof KycCorrectionSyncAttempt
                || $attempt->status !== 'sync_failed'
                || $attempt->http_status === null
                || $attempt->http_status < 400
                || $attempt->http_status >= 500
                || ! hash_equals($attempt->request_id, $expectedRequestId)
                || ! hash_equals($attempt->request_fingerprint, $expectedRequestFingerprint)) {
                throw ValidationException::withMessages(['sync_attempt' => 'The deterministic failure evidence is stale or not eligible for recovery.']);
            }

            $attempt->update(['active_slot' => null, 'resolved_at' => $attempt->resolved_at ?? now()]);
            $locked->update([
                'status' => 'needs_more_info', 'active_slot' => null, 'review_note' => $reason, 'resolved_at' => now(),
            ]);
            AuditLog::query()->create([
                'user_id' => $operator->id, 'action' => 'kyc.correction_sync_failure_resolved',
                'entity_type' => 'kyc_profile_correction', 'entity_id' => (string) $locked->id,
                'old_data' => ['status' => 'sync_failed'],
                'new_data' => [
                    'status' => 'needs_more_info', 'correction_version' => $locked->correction_version,
                    'request_id' => $attempt->request_id, 'request_fingerprint' => $attempt->request_fingerprint,
                    'http_status' => $attempt->http_status, 'reason_recorded' => true,
                ],
            ]);

            return $locked->fresh();
        }, 3);
    }

    private function verify(User $customer, KycProfileCorrection $correction, KycCorrectionSyncAttempt $attempt, User $operator): KycProfileCorrection
    {
        $account = $correction->providerAccount;
        $response = $this->nium->get(
            $this->nium->path((string) config('services.nium.customer_get_endpoint'), [
                'client' => $this->nium->clientId(), 'customer' => $account->external_customer_id,
            ]), [], $customer, 'customer_update_verify', (string) $account->external_reference,
        );
        if (! $response->successful() || ! is_array($response->json())) {
            $attempt->update(['status' => 'verification_pending', 'failure_code' => 'customer_get_unverified']);
            $correction->update(['status' => 'verification_pending']);
            throw new RuntimeException('Customer Update was not verified; reconciliation is required.');
        }

        $data = $response->json();
        $result = $this->verificationResult($correction, $account, $data);
        if (! $result['verified']) {
            $attempt->update(['status' => 'verification_pending', 'verification_result' => $result, 'failure_code' => 'customer_get_mismatch']);
            $correction->update(['status' => 'verification_pending']);
            throw new RuntimeException('Customer Update response does not match the approved correction; reconciliation is required.');
        }

        DB::transaction(function () use ($correction, $attempt, $operator, $result): void {
            $locked = KycProfileCorrection::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();
            $applicant = KycRelatedPerson::query()->whereKey($locked->applicant_related_person_id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['verification_pending', 'syncing'], true)) {
                throw ValidationException::withMessages(['correction' => 'Correction state changed before verification was applied.']);
            }
            $applicant->update((array) $locked->proposed_values);
            $attempt->update([
                'status' => 'synced', 'active_slot' => null, 'verification_result' => $result,
                'verified_at' => now(), 'resolved_at' => now(),
            ]);
            $locked->update(['status' => 'synced', 'active_slot' => null, 'resolved_at' => now()]);
            AuditLog::query()->create([
                'user_id' => $operator->id, 'action' => 'kyc.correction_nium_synced',
                'entity_type' => 'kyc_profile_correction', 'entity_id' => (string) $locked->id,
                'old_data' => ['status' => 'verification_pending'],
                'new_data' => [
                    'status' => 'synced', 'correction_version' => $locked->correction_version,
                    'request_id' => $attempt->request_id, 'request_fingerprint' => $attempt->request_fingerprint,
                    'verified_fields' => ['applicant.first_name', 'applicant.last_name', 'applicant.date_of_birth'],
                ],
            ]);
        }, 3);

        return $correction->fresh();
    }

    private function verificationResult(KycProfileCorrection $correction, UserProviderAccount $account, array $data): array
    {
        $proposed = (array) $correction->proposed_values;
        $applicant = (array) ($data['applicant'] ?? []);
        $expectedExternalId = (string) $correction->applicant_external_id;
        $checks = [
            'customer_hash_id' => hash_equals((string) $account->external_customer_id, (string) ($data['customerHashId'] ?? '')),
            'customer_external_id' => hash_equals((string) $account->external_reference, (string) ($data['externalId'] ?? '')),
            'applicant_external_id' => hash_equals($expectedExternalId, (string) ($applicant['externalId'] ?? '')),
            'applicant_reference_id' => hash_equals((string) $correction->applicant_reference_id, (string) ($applicant['referenceId'] ?? '')),
            'first_name' => hash_equals((string) $proposed['first_name'], (string) ($applicant['firstName'] ?? '')),
            'last_name' => hash_equals((string) $proposed['last_name'], (string) ($applicant['lastName'] ?? '')),
            'date_of_birth' => hash_equals((string) $proposed['date_of_birth'], (string) ($applicant['dateOfBirth'] ?? '')),
            'security_clear' => $account->security_conflict_at === null && ! filled($account->security_conflict_reason),
            'stakeholder_bindings' => $this->stakeholderBindingsMatch($account, $data),
        ];

        return ['verified' => ! in_array(false, $checks, true), 'checks' => $checks];
    }

    private function stakeholderBindingsMatch(UserProviderAccount $account, array $data): bool
    {
        $expected = collect((array) data_get($account->metadata, 'nium_entity_kyc_states', []))
            ->filter(fn ($state): bool => is_array($state) && ($state['entity_type'] ?? null) === 'stakeholder')
            ->mapWithKeys(fn (array $state): array => [(string) ($state['external_id'] ?? '') => (string) ($state['provider_reference_id'] ?? '')])
            ->filter(fn (string $reference, string $external): bool => $external !== '' && $reference !== '')->all();
        $actual = collect((array) data_get($data, 'stakeholders.individual', []))
            ->mapWithKeys(fn ($person): array => is_array($person)
                ? [(string) ($person['externalId'] ?? '') => (string) ($person['referenceId'] ?? '')] : [])
            ->filter(fn (string $reference, string $external): bool => $external !== '' && $reference !== '')->all();

        return $expected === array_intersect_key($actual, $expected)
            && array_diff_key($actual, $expected) === [];
    }

    private function assertBinding(User $customer, KycProfileCorrection $correction, UserProviderAccount $account, KycRelatedPerson $applicant): void
    {
        $expectedExternalId = trim((string) $correction->applicant_external_id);
        $references = collect((array) data_get($account->metadata, 'nium_entity_kyc_states', []))
            ->filter(fn ($state): bool => is_array($state)
                && ($state['external_id'] ?? null) === $expectedExternalId
                && filled($state['provider_reference_id'] ?? null))
            ->pluck('provider_reference_id')->unique()->values();
        if ((int) $correction->kycProfile?->user_id !== (int) $customer->id
            || (int) $applicant->kyc_profile_id !== (int) $correction->kyc_profile_id
            || (int) $account->user_id !== (int) $customer->id
            || ! hash_equals((string) $correction->customer_hash_id, (string) $account->external_customer_id)
            || hash('sha256', (string) $account->external_customer_id) !== $correction->customer_hash_fingerprint
            || $expectedExternalId === ''
            || hash('sha256', $expectedExternalId) !== $correction->applicant_external_fingerprint
            || $references->count() !== 1
            || ! hash_equals((string) $correction->applicant_reference_id, (string) $references->sole())
            || hash('sha256', (string) $references->sole()) !== $correction->applicant_reference_fingerprint) {
            throw ValidationException::withMessages(['correction' => 'Immutable Nium correction binding is stale or invalid.']);
        }
        $before = (array) $correction->before_snapshot;
        $current = [
            'legal_name' => (string) $applicant->legal_name, 'first_name' => (string) $applicant->first_name,
            'last_name' => (string) $applicant->last_name, 'date_of_birth' => $applicant->date_of_birth?->toDateString(),
        ];
        if ($before !== $current) {
            throw ValidationException::withMessages(['correction' => 'The approved KYC source changed after review.']);
        }
    }

    private function assertApprovedSource(KycProfileCorrection $correction): void
    {
        $snapshot = $correction->approved_source_snapshot;
        $fingerprint = (string) $correction->approved_source_fingerprint;
        if (! is_array($snapshot) || $fingerprint === ''
            || ! hash_equals($fingerprint, $this->approvedSource->fingerprint($snapshot))) {
            throw ValidationException::withMessages(['correction' => 'The approved full KYC source snapshot is missing or invalid.']);
        }
        $current = $this->approvedSource->snapshot($correction->kycProfile);
        if (! hash_equals($fingerprint, $this->approvedSource->fingerprint($current))) {
            throw ValidationException::withMessages(['correction' => 'The approved company, document, address, bank, or stakeholder source changed before synchronization.']);
        }
    }

    private function finishAttempt(KycCorrectionSyncAttempt $attempt, KycProfileCorrection $correction, string $status, ?int $httpStatus, string $failureCode): void
    {
        $attempt->update([
            'status' => $status, 'http_status' => $httpStatus, 'failure_code' => $failureCode,
            'resolved_at' => $status === 'sync_failed' ? now() : null,
        ]);
        $correction->update(['status' => $status]);
    }
}
