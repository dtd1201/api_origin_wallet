<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\KycDocument;
use App\Models\KycProfileCorrection;
use App\Models\NiumManualKycContinuation;
use App\Models\NiumManualKycResubmissionAuthorization;
use App\Models\User;
use App\Models\UserProviderAccount;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class NiumHkManualKycResubmissionService
{
    public function __construct(
        private readonly NiumCustomerDocumentResolver $documentResolver,
        private readonly NiumHkSubmitKycPayloadFactory $payloadFactory,
    ) {}

    public function authorize(User $customer, User $admin, int $priorLogId, string $reason): NiumManualKycResubmissionAuthorization
    {
        if (! $admin->hasPermission('kyc.approve')) {
            throw new RuntimeException('The administrator is not authorized to approve manual KYC resubmissions.');
        }

        return DB::transaction(function () use ($customer, $admin, $priorLogId, $reason): NiumManualKycResubmissionAuthorization {
            $account = UserProviderAccount::query()->where('user_id', $customer->id)
                ->whereHas('provider', fn ($query) => $query->whereRaw('LOWER(code) = ?', ['nium']))
                ->whereNotNull('external_customer_id')->lockForUpdate()->sole();
            $existing = NiumManualKycResubmissionAuthorization::query()
                ->where('prior_api_request_log_id', $priorLogId)->lockForUpdate()->first();
            if ($existing instanceof NiumManualKycResubmissionAuthorization) {
                if ((int) $existing->user_provider_account_id !== (int) $account->id) {
                    throw new RuntimeException('The rejected Submit KYC log is already bound to another authorization.');
                }

                return $existing;
            }

            $correction = $customer->kycProfile?->corrections()->latest('id')->lockForUpdate()->first();
            if (! $correction instanceof KycProfileCorrection || $correction->status !== 'manual_kyc_evidence_approved') {
                throw new RuntimeException('The latest approved manual KYC correction is not eligible for resubmission.');
            }
            $snapshot = $this->snapshot($account, $correction);
            if (! $snapshot['address'] instanceof KycDocument) {
                throw new RuntimeException('An approved correction-bound proof of address is required before resubmission authorization.');
            }
            $this->payloadFactory->buildApplicantManual(
                $snapshot['passport'],
                (string) $correction->applicant_reference_id,
                $snapshot['residence_country'] === 'HK',
                $snapshot['address_file_id'] === null ? null : [
                    'type' => 'proof_of_address', 'fileIds' => [$snapshot['address_file_id']],
                ],
            );
            $log = ApiRequestLog::query()->whereKey($priorLogId)->lockForUpdate()->first();
            $attempt = collect((array) Arr::get((array) $account->metadata, 'nium_submit_kyc_attempts', []))
                ->first(fn (mixed $candidate): bool => is_array($candidate)
                    && (int) ($candidate['submit_kyc_log_id'] ?? 0) === $priorLogId);
            if (! $log instanceof ApiRequestLog
                || (int) $log->provider_id !== (int) $account->provider_id
                || (int) $log->user_id !== (int) $customer->id
                || $log->operation !== 'submit_kyc'
                || $log->request_method !== 'POST'
                || (int) $log->response_status !== 400
                || $log->transport_outcome !== 'response_received'
                || ! is_array($attempt)
                || ($attempt['state'] ?? null) !== 'rejected'
                || ($attempt['kyc_mode'] ?? null) !== 'manual_kyc'
                || ($attempt['entity_type'] ?? null) !== 'applicant'
                || (int) ($attempt['kyc_profile_correction_id'] ?? 0) !== (int) $correction->id
                || ! hash_equals((string) ($attempt['external_id'] ?? ''), (string) $correction->applicant_external_id)
                || ! hash_equals((string) ($attempt['manual_reference_id'] ?? ''), (string) $log->external_reference)
                || (int) ($attempt['provider_http_status'] ?? 0) !== 400
                || ! $this->hasErrorField($log, 'isResident')
                || array_key_exists('isResident', (array) $log->request_body)) {
                throw new RuntimeException('Only the matching deterministic isResident HTTP 400 rejection can be authorized.');
            }
            $blocked = collect((array) Arr::get((array) $account->metadata, 'nium_submit_kyc_attempts', []))
                ->contains(fn (mixed $candidate): bool => is_array($candidate)
                    && (int) ($candidate['submit_kyc_log_id'] ?? 0) !== $priorLogId
                    && ($candidate['kyc_mode'] ?? null) === 'manual_kyc'
                    && ($candidate['entity_type'] ?? null) === 'applicant'
                    && (int) ($candidate['kyc_profile_correction_id'] ?? 0) === (int) $correction->id
                    && in_array($candidate['state'] ?? null, [
                        'submitting', 'unknown', 'response_review', 'accepted', 'compliance_review',
                    ], true));
            $existingContinuation = NiumManualKycContinuation::query()
                ->where('kyc_profile_correction_id', $correction->id)
                ->exists();
            if ($blocked || $existingContinuation) {
                throw new RuntimeException('An unresolved attempt or existing continuation blocks resubmission authorization.');
            }

            $authorization = NiumManualKycResubmissionAuthorization::query()->create([
                'user_provider_account_id' => $account->id,
                'kyc_profile_correction_id' => $correction->id,
                'correction_version' => $correction->correction_version,
                'prior_api_request_log_id' => $log->id,
                'authorized_by_user_id' => $admin->id,
                'passport_document_id' => $snapshot['passport']->id,
                'proof_of_address_document_id' => $snapshot['address']?->id,
                'attempt_reference' => 'origin-wallet-manual-reauth-'.Str::uuid(),
                'state' => NiumManualKycResubmissionAuthorization::PENDING,
                'applicant_reference_fingerprint' => hash('sha256', (string) $correction->applicant_reference_id),
                'residence_fingerprint' => hash('sha256', $snapshot['residence_country']),
                'passport_evidence_fingerprint' => $snapshot['passport_evidence_fingerprint'],
                'passport_file_id_fingerprint' => hash('sha256', $snapshot['passport_file_id']),
                'proof_of_address_evidence_fingerprint' => $snapshot['address_evidence_fingerprint'],
                'proof_of_address_file_id_fingerprint' => $snapshot['address_file_id'] === null
                    ? null : hash('sha256', $snapshot['address_file_id']),
                'reason' => trim($reason),
                'authorized_at' => now(),
            ]);
            AuditLog::query()->create([
                'user_id' => $admin->id,
                'action' => 'kyc.nium_manual_resubmission_authorized',
                'entity_type' => 'nium_manual_kyc_resubmission_authorization',
                'entity_id' => (string) $authorization->id,
                'old_data' => null,
                'new_data' => [
                    'provider_account_id' => $account->id,
                    'correction_id' => $correction->id,
                    'correction_version' => $correction->correction_version,
                    'prior_api_request_log_id' => $log->id,
                    'attempt_reference_fingerprint' => substr(hash('sha256', $authorization->attempt_reference), 0, 16),
                ],
            ]);

            return $authorization;
        }, 3);
    }

    public function pendingFor(UserProviderAccount $account, KycProfileCorrection $correction): ?NiumManualKycResubmissionAuthorization
    {
        return NiumManualKycResubmissionAuthorization::query()
            ->where('user_provider_account_id', $account->id)
            ->where('kyc_profile_correction_id', $correction->id)
            ->whereIn('state', [
                NiumManualKycResubmissionAuthorization::PENDING,
                NiumManualKycResubmissionAuthorization::CONSUMED,
                NiumManualKycResubmissionAuthorization::TERMINAL,
            ])
            ->latest('id')->first();
    }

    public function latestFor(User $customer): ?NiumManualKycResubmissionAuthorization
    {
        return NiumManualKycResubmissionAuthorization::query()
            ->whereHas('providerAccount', fn ($query) => $query->where('user_id', $customer->id))
            ->latest('id')->first();
    }

    /** @return array{passport: KycDocument, address: ?KycDocument, residence_country: string, passport_file_id: string, address_file_id: ?string, passport_evidence_fingerprint: string, address_evidence_fingerprint: ?string} */
    public function snapshot(UserProviderAccount $account, KycProfileCorrection $correction): array
    {
        $person = $correction->applicant;
        if ($person === null
            || (int) $person->id !== (int) $correction->applicant_related_person_id
            || (int) $person->kyc_profile_id !== (int) $correction->kyc_profile_id
            || (int) ($correction->kycProfile?->user_id) !== (int) $account->user_id
            || ! in_array(strtolower((string) $person->status), ['approved', 'verified'], true)
            || ! in_array(strtolower((string) $person->relationship_type), ['applicant', 'beneficial_owner'], true)
            || (int) $correction->user_provider_account_id !== (int) $account->id
            || ! hash_equals((string) $correction->customer_hash_id, (string) $account->external_customer_id)
            || ! hash_equals((string) $correction->customer_hash_fingerprint, hash('sha256', (string) $account->external_customer_id))) {
            throw new RuntimeException('The correction no longer matches the verified Nium account and applicant.');
        }
        $residence = strtoupper(trim((string) $person->residence_country_code));
        if (preg_match('/^[A-Z]{2}$/', $residence) !== 1) {
            throw new RuntimeException('The correction-bound applicant residence is missing or ambiguous.');
        }
        foreach ([(array) $correction->before_snapshot, (array) $correction->proposed_values] as $correctionValues) {
            if (array_key_exists('residence_country_code', $correctionValues)
                && strtoupper(trim((string) $correctionValues['residence_country_code'])) !== $residence) {
                throw new RuntimeException('The correction residence evidence contradicts the verified applicant residence.');
            }
        }
        $passports = $person->documents()->get()->filter(fn (KycDocument $document): bool =>
            (int) $document->kyc_profile_id === (int) $correction->kyc_profile_id
            && (int) $document->kyc_related_person_id === (int) $person->id
            && in_array(strtolower(trim((string) data_get($document->metadata, 'nium_document_type', $document->type))), ['passport', 'passport_front'], true)
            && (int) data_get($document->metadata, 'source_correction_id') === (int) $correction->id
            && data_get($document->metadata, 'source') === 'admin_reviewed_correction'
            && $document->status === 'manual_kyc_evidence_approved')->values();
        if ($passports->count() !== 1) {
            throw new RuntimeException('Exactly one approved correction-bound passport is required.');
        }
        $passport = $passports->sole();
        $passportFileId = trim((string) data_get($passport?->metadata, 'nium_file_id'));
        if (! $passport instanceof KycDocument || ! Str::isUuid($passportFileId)
            || strtoupper((string) data_get($passport->metadata, 'nium_file_state')) !== 'AVAILABLE') {
            throw new RuntimeException('The approved correction passport is not AVAILABLE at Nium.');
        }
        $rawAddressEvidence = $person->documents()->get()->filter(fn (KycDocument $document): bool =>
            strtolower(trim((string) data_get($document->metadata, 'nium_document_type', $document->type))) === 'proof_of_address');
        $addressRequired = collect((array) $correction->requested_document_types)
            ->contains(fn (mixed $type): bool => strtolower(trim((string) $type)) === 'proof_of_address');
        $addresses = $this->documentResolver->relatedPersonDocuments($person)
            ->filter(fn (KycDocument $document): bool =>
                (int) $document->kyc_profile_id === (int) $correction->kyc_profile_id
                && (int) $document->kyc_related_person_id === (int) $person->id
                && in_array(strtolower(trim((string) $document->status)), ['approved', 'verified'], true)
                && strtolower(trim((string) data_get($document->metadata, 'nium_document_type', $document->type))) === 'proof_of_address'
                && (data_get($document->metadata, 'source_correction_id') === null
                    ? ! $addressRequired
                    : (int) data_get($document->metadata, 'source_correction_id') === (int) $correction->id))
            ->values();
        if ($addresses->isEmpty() && $rawAddressEvidence->isNotEmpty()) {
            throw new RuntimeException('The applicant has proof-of-address evidence, but none is current, approved and correction-relevant.');
        }
        if ($addressRequired && $addresses->isEmpty()) {
            throw new RuntimeException('An approved correction-bound proof of address is required before resubmission authorization.');
        }
        if ($addresses->count() > 1) {
            throw new RuntimeException('Proof-of-address evidence is ambiguous.');
        }
        $address = $addresses->first();
        $addressFileId = $address === null ? null : trim((string) data_get($address->metadata, 'nium_file_id'));
        if ($addressFileId !== null && $addressFileId !== '' && ! Str::isUuid($addressFileId)) {
            throw new RuntimeException('The approved proof-of-address File ID is invalid.');
        }

        return compact('passport', 'address') + [
            'residence_country' => $residence,
            'passport_file_id' => $passportFileId,
            'address_file_id' => $addressFileId !== '' ? $addressFileId : null,
            'passport_evidence_fingerprint' => $this->evidenceFingerprint($passport),
            'address_evidence_fingerprint' => $address === null ? null : $this->evidenceFingerprint($address),
        ];
    }

    public function evidenceFingerprint(KycDocument $document): string
    {
        return hash('sha256', json_encode([
            'id' => (int) $document->id,
            'kyc_profile_id' => (int) $document->kyc_profile_id,
            'kyc_related_person_id' => (int) $document->kyc_related_person_id,
            'type' => strtolower(trim((string) data_get($document->metadata, 'nium_document_type', $document->type))),
            'status' => strtolower(trim((string) $document->status)),
            'file_hash' => strtolower(trim((string) $document->file_hash)),
            'storage_disk' => (string) $document->storage_disk,
            'file_path' => (string) $document->file_path,
            'file_size' => (int) $document->file_size,
            'source' => (string) data_get($document->metadata, 'source'),
            'source_correction_id' => (int) data_get($document->metadata, 'source_correction_id'),
        ], JSON_THROW_ON_ERROR));
    }

    private function hasErrorField(ApiRequestLog $log, string $field): bool
    {
        $body = (array) $log->response_body;

        return ($body['error_field'] ?? null) === $field
            || collect((array) ($body['error_items'] ?? []))->contains(
                fn (mixed $item): bool => is_array($item) && ($item['error_field'] ?? null) === $field,
            );
    }
}
