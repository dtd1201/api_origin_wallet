<?php

namespace App\Services\Kyc;

use App\Models\AuditLog;
use App\Models\KycProfile;
use App\Models\KycProfileCorrection;
use App\Models\KycDocumentUpload;
use App\Models\KycRelatedPerson;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Support\PrimaryProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class KycApplicantCorrectionService
{
    private const FIELDS = ['legal_name', 'first_name', 'last_name', 'date_of_birth'];
    private const PASSPORT_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    private const DOCUMENT_FIELDS = ['type', 'file_url', 'storage_disk', 'file_path', 'original_name', 'mime_type', 'file_size', 'file_hash', 'side', 'document_number', 'issuing_country_code', 'issued_at', 'expires_at', 'metadata'];

    public function state(User $user): array
    {
        try {
            $context = $this->resolveContext($user);
        } catch (RuntimeException $exception) {
            return [
                'eligible' => false,
                'reason' => $exception->getMessage(),
                'correction' => $this->activeCorrection($user),
                'latest_resolved_correction' => $this->latestResolvedCorrection($user),
            ];
        }

        $active = $this->activeCorrection($user);

        return [
            'eligible' => $active === null,
            'reason' => $active === null ? null : 'A KYC correction is already awaiting review.',
            'current_values' => $this->snapshot($context['applicant']),
            'source_fingerprint' => $active?->status === 'requested' ? $active->source_fingerprint : $this->sourceFingerprint($context),
            'correction_version' => $active?->status === 'requested' ? $active->correction_version : $this->nextVersion($context['profile']),
            'correction' => $active,
            'latest_resolved_correction' => $active === null ? $this->latestResolvedCorrection($user) : null,
        ];
    }

    public function submit(User $user, array $values, int $version, string $sourceFingerprint, ?string $ip, ?string $userAgent): KycProfileCorrection
    {
        return DB::transaction(function () use ($user, $values, $version, $sourceFingerprint, $ip, $userAgent): KycProfileCorrection {
            $profile = KycProfile::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $account = UserProviderAccount::query()
                ->where('user_id', $user->id)
                ->whereHas('provider', fn ($query) => $query->whereRaw('LOWER(code) = ?', [PrimaryProvider::code()]))
                ->whereNotNull('external_customer_id')
                ->lockForUpdate()
                ->get();

            if ($account->count() !== 1) {
                throw new RuntimeException('The customer does not have exactly one existing Nium customer account.');
            }

            $context = $this->resolveContext($user, $profile, $account->sole());
            $expectedVersion = $this->nextVersion($profile);
            $expectedFingerprint = $this->sourceFingerprint($context);

            if ($profile->corrections()->where('active_slot', 1)->exists()) {
                throw ValidationException::withMessages([
                    'correction' => 'A KYC correction is already awaiting review.',
                ]);
            }

            if ($version !== $expectedVersion || ! hash_equals($expectedFingerprint, $sourceFingerprint)) {
                throw ValidationException::withMessages([
                    'correction_version' => 'The KYC correction form is stale. Refresh the profile before submitting.',
                ]);
            }

            $before = $this->snapshot($context['applicant']);
            $proposed = Arr::only($values, self::FIELDS);
            if ($before === $proposed) {
                throw ValidationException::withMessages([
                    'correction' => 'At least one applicant field must be changed.',
                ]);
            }

            $correction = $profile->corrections()->create([
                'applicant_related_person_id' => $context['applicant']->id,
                'user_provider_account_id' => $context['account']->id,
                'correction_version' => $expectedVersion,
                'source_fingerprint' => $expectedFingerprint,
                'customer_hash_id' => $context['customer_hash_id'],
                'applicant_reference_id' => $context['applicant_reference_id'],
                'applicant_external_id' => $context['external_id'],
                'customer_hash_fingerprint' => hash('sha256', $context['customer_hash_id']),
                'applicant_reference_fingerprint' => hash('sha256', $context['applicant_reference_id']),
                'applicant_external_fingerprint' => hash('sha256', $context['external_id']),
                'before_snapshot' => $before,
                'proposed_values' => $proposed,
                'status' => 'submitted',
                'active_slot' => 1,
                'created_by_user_id' => $user->id,
                'submitted_at' => now(),
            ]);

            AuditLog::query()->create([
                'user_id' => $user->id,
                'action' => 'kyc.correction_submitted',
                'entity_type' => 'kyc_profile_correction',
                'entity_id' => (string) $correction->id,
                'old_data' => null,
                'new_data' => [
                    'kyc_profile_id' => $profile->id,
                    'applicant_related_person_id' => $context['applicant']->id,
                    'correction_version' => $expectedVersion,
                    'source_fingerprint' => $expectedFingerprint,
                    'customer_hash_fingerprint' => $correction->customer_hash_fingerprint,
                    'applicant_reference_fingerprint' => $correction->applicant_reference_fingerprint,
                    'changed_field_paths' => collect(self::FIELDS)
                        ->filter(fn (string $field): bool => $before[$field] !== $proposed[$field])
                        ->map(fn (string $field): string => 'applicant.'.$field)->values()->all(),
                    'status' => 'submitted',
                ],
                'ip_address' => $ip,
                'user_agent' => Str::limit((string) $userAgent, 1000, ''),
            ]);

            return $correction->fresh();
        }, 3);
    }

    public function requestByAdmin(User $customer, User $admin, array $fields, array $documentTypes, string $instruction, ?string $ip, ?string $userAgent): KycProfileCorrection
    {
        return DB::transaction(function () use ($customer, $admin, $fields, $documentTypes, $instruction, $ip, $userAgent): KycProfileCorrection {
            $profile = KycProfile::query()->where('user_id', $customer->id)->lockForUpdate()->firstOrFail();
            $accounts = UserProviderAccount::query()->where('user_id', $customer->id)
                ->whereHas('provider', fn ($query) => $query->whereRaw('LOWER(code) = ?', [PrimaryProvider::code()]))
                ->whereNotNull('external_customer_id')->lockForUpdate()->get();
            if ($accounts->count() !== 1) {
                throw new RuntimeException('The customer does not have exactly one existing Nium customer account.');
            }
            $context = $this->resolveContext($customer, $profile, $accounts->sole());
            if ($profile->corrections()->where('active_slot', 1)->exists()) {
                throw ValidationException::withMessages(['correction' => 'An active KYC update request already exists.']);
            }
            $fields = array_values(array_unique(array_intersect(self::FIELDS, $fields)));
            $documentTypes = array_values(array_unique(array_intersect(['passport'], $documentTypes)));
            if ($fields === [] && $documentTypes === []) {
                throw ValidationException::withMessages(['scope' => 'Select at least one supported applicant field or passport document.']);
            }
            $before = $this->snapshot($context['applicant']);
            $correction = $profile->corrections()->create([
                'applicant_related_person_id' => $context['applicant']->id, 'user_provider_account_id' => $context['account']->id,
                'correction_version' => $this->nextVersion($profile), 'source_fingerprint' => $this->sourceFingerprint($context),
                'customer_hash_id' => $context['customer_hash_id'], 'applicant_reference_id' => $context['applicant_reference_id'],
                'applicant_external_id' => $context['external_id'],
                'customer_hash_fingerprint' => hash('sha256', $context['customer_hash_id']),
                'applicant_reference_fingerprint' => hash('sha256', $context['applicant_reference_id']),
                'applicant_external_fingerprint' => hash('sha256', $context['external_id']),
                'before_snapshot' => $before, 'proposed_values' => $before,
                'requested_fields' => $fields, 'requested_document_types' => $documentTypes,
                'request_instruction' => $instruction, 'request_origin' => 'admin',
                'status' => 'requested', 'active_slot' => 1, 'created_by_user_id' => $admin->id,
                'requested_by_user_id' => $admin->id, 'submitted_at' => now(),
            ]);
            AuditLog::query()->create([
                'user_id' => $admin->id, 'action' => 'kyc.correction_update_requested',
                'entity_type' => 'kyc_profile_correction', 'entity_id' => (string) $correction->id,
                'old_data' => null, 'new_data' => [
                    'status' => 'requested', 'correction_version' => $correction->correction_version,
                    'requested_field_paths' => array_map(fn ($field) => 'applicant.'.$field, $fields),
                    'requested_document_types' => $documentTypes, 'instruction_recorded' => true,
                ], 'ip_address' => $ip, 'user_agent' => Str::limit((string) $userAgent, 1000, ''),
            ]);
            return $correction->fresh();
        }, 3);
    }

    public function submitRequested(User $user, KycProfileCorrection $correction, array $values, array $documents, int $version, string $sourceFingerprint, ?string $ip, ?string $userAgent): KycProfileCorrection
    {
        return DB::transaction(function () use ($user, $correction, $values, $documents, $version, $sourceFingerprint, $ip, $userAgent): KycProfileCorrection {
            $profile = KycProfile::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $locked = KycProfileCorrection::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->kyc_profile_id !== (int) $profile->id) abort(404);
            if ($locked->status !== 'requested' || $locked->active_slot !== 1) {
                throw ValidationException::withMessages(['correction' => 'This KYC update request is no longer open.']);
            }
            if ($locked->correction_version !== $version || ! hash_equals($locked->source_fingerprint, $sourceFingerprint)) {
                throw ValidationException::withMessages(['correction_version' => 'The KYC update request is stale. Refresh before submitting.']);
            }
            $accounts = UserProviderAccount::query()->where('user_id', $user->id)
                ->whereHas('provider', fn ($query) => $query->whereRaw('LOWER(code) = ?', [PrimaryProvider::code()]))
                ->whereNotNull('external_customer_id')->lockForUpdate()->get();
            if ($accounts->count() !== 1) throw new RuntimeException('The customer does not have exactly one existing Nium customer account.');
            $context = $this->resolveContext($user, $profile, $accounts->sole());
            if (! hash_equals($locked->source_fingerprint, $this->sourceFingerprint($context))
                || (int) $locked->applicant_related_person_id !== (int) $context['applicant']->id
                || ! hash_equals((string) $locked->applicant_external_id, $context['external_id'])) {
                throw ValidationException::withMessages(['correction_version' => 'The approved KYC source or applicant binding changed. Refresh before submitting.']);
            }
            $requestedFields = (array) $locked->requested_fields;
            $before = (array) $locked->before_snapshot;
            $unexpected = collect(array_diff(array_keys($values), $requestedFields))
                ->filter(fn (string $field): bool => array_key_exists($field, $before) && $values[$field] !== $before[$field])
                ->values()->all();
            if ($unexpected !== []) {
                throw ValidationException::withMessages(['fields' => 'Only fields explicitly requested by the administrator may be changed.']);
            }
            $proposed = $before;
            foreach ($requestedFields as $field) {
                if (! array_key_exists($field, $values)) {
                    throw ValidationException::withMessages([$field => 'This requested field is required.']);
                }
                $proposed[$field] = $values[$field];
            }
            $requiredDocuments = (array) $locked->requested_document_types;
            $safeDocuments = collect($documents)->map(fn (array $document): array => Arr::only($document, self::DOCUMENT_FIELDS))->values()->all();
            foreach ($safeDocuments as $document) {
                $disk = (string) ($document['storage_disk'] ?? '');
                $path = (string) ($document['file_path'] ?? '');
                $hash = (string) ($document['file_hash'] ?? '');
                $expectedDisk = (string) config('services.kyc.documents_disk', 'kyc_private');
                if ($disk !== $expectedDisk || ! str_starts_with($path, "kyc/{$user->id}/documents/")
                    || ! Storage::disk($disk)->exists($path)) {
                    throw ValidationException::withMessages(['documents' => 'The uploaded passport evidence is missing or does not belong to this customer.']);
                }

                $actualHash = hash_file('sha256', Storage::disk($disk)->path($path));
                $actualSize = Storage::disk($disk)->size($path);
                $actualMime = Storage::disk($disk)->mimeType($path);
                if (! is_string($actualHash) || ! hash_equals($hash, $actualHash)
                    || (int) ($document['file_size'] ?? 0) !== $actualSize
                    || ! is_string($actualMime) || ! in_array($actualMime, self::PASSPORT_MIME_TYPES, true)
                    || ! hash_equals((string) ($document['mime_type'] ?? ''), $actualMime)) {
                    throw ValidationException::withMessages(['documents' => 'The uploaded passport evidence failed its integrity or content-type check.']);
                }

                $upload = KycDocumentUpload::query()->where('storage_disk', $disk)->where('file_path', $path)->lockForUpdate()->first();
                if (! $upload instanceof KycDocumentUpload
                    || (int) $upload->user_id !== (int) $user->id
                    || ! in_array($upload->status, ['uploaded', 'referenced'], true)
                    || ! hash_equals((string) $upload->file_hash, $hash)
                    || (int) $upload->file_size !== $actualSize
                    || ! hash_equals((string) $upload->mime_type, $actualMime)) {
                    throw ValidationException::withMessages(['documents' => 'The uploaded passport evidence is not available for this correction.']);
                }
            }
            foreach ($requiredDocuments as $type) {
                if (! collect($safeDocuments)->contains(fn ($document) => ($document['type'] ?? null) === $type && filled($document['file_hash'] ?? null) && filled($document['file_path'] ?? null))) {
                    throw ValidationException::withMessages(['documents' => "The requested {$type} document is required."]);
                }
            }
            $locked->update(['proposed_values' => $proposed, 'proposed_documents' => $safeDocuments, 'status' => 'submitted', 'submitted_at' => now()]);
            foreach ($safeDocuments as $document) {
                KycDocumentUpload::query()->updateOrCreate(
                    ['storage_disk' => $document['storage_disk'], 'file_path' => $document['file_path']],
                    [
                        'user_id' => $user->id,
                        'kyc_profile_correction_id' => $locked->id,
                        'file_hash' => $document['file_hash'],
                        'mime_type' => $document['mime_type'],
                        'file_size' => $document['file_size'],
                        'status' => 'referenced',
                        'expires_at' => null,
                        'referenced_at' => now(),
                    ],
                );
            }
            AuditLog::query()->create([
                'user_id' => $user->id, 'action' => 'kyc.correction_submitted', 'entity_type' => 'kyc_profile_correction',
                'entity_id' => (string) $locked->id, 'old_data' => ['status' => 'requested'],
                'new_data' => ['status' => 'submitted', 'correction_version' => $version,
                    'changed_field_paths' => array_map(fn ($field) => 'applicant.'.$field, $requestedFields),
                    'submitted_document_types' => array_values(array_unique(array_column($safeDocuments, 'type')))],
                'ip_address' => $ip, 'user_agent' => Str::limit((string) $userAgent, 1000, ''),
            ]);
            return $locked->fresh();
        }, 3);
    }

    public function resolveContext(
        User $user,
        ?KycProfile $lockedProfile = null,
        ?UserProviderAccount $lockedAccount = null,
        bool $requirePendingRetryState = true,
    ): array
    {
        $profile = $lockedProfile ?? $user->kycProfile()->with('relatedPersons')->first();
        if (! $profile instanceof KycProfile
            || ! in_array(strtolower((string) $profile->status), ['approved', 'verified'], true)
            || $profile->applicant_type !== 'business'
            || strtoupper((string) data_get($profile->metadata, 'nium_region')) !== 'HK'
            || strtolower((string) data_get($profile->metadata, 'nium_kyc_type')) !== 'full') {
            throw new RuntimeException('Only approved Corporate HK Full KYC profiles can request this correction.');
        }
        $profile->loadMissing('relatedPersons');

        $accounts = $lockedAccount ? collect([$lockedAccount]) : $user->providerAccounts()
            ->whereHas('provider', fn ($query) => $query->whereRaw('LOWER(code) = ?', [PrimaryProvider::code()]))
            ->whereNotNull('external_customer_id')->get();
        if ($accounts->count() !== 1) {
            throw new RuntimeException('The customer does not have exactly one existing Nium customer account.');
        }
        $account = $accounts->sole();
        if (($requirePendingRetryState && (
                strtolower((string) $account->provider_status) !== 'pending'
                || strtolower((string) $account->provider_sub_status) !== 'awaiting_kyc'
            ))
            || $account->customer_id_verified_at === null
            || $account->reconciliation_status !== 'reconciled'
            || $account->security_conflict_at !== null
            || filled($account->security_conflict_reason)) {
            throw new RuntimeException('The existing Nium customer is not in a verified pending/awaiting_kyc state.');
        }

        $evidence = $this->retryEvidence($account);
        $externalId = trim((string) ($evidence['external_id'] ?? ''));
        if (preg_match('/^origin-wallet-(?:person|applicant)-(\d+)$/', $externalId, $matches) !== 1) {
            throw new RuntimeException('The Nium retry evidence does not identify a supported applicant.');
        }
        $people = $profile->relatedPersons->where('id', (int) $matches[1])->values();
        if ($people->count() !== 1 || ! $this->isCorporateApplicant($profile, $people->sole())) {
            throw new RuntimeException('The Nium retry evidence does not match the approved corporate applicant.');
        }

        return [
            'profile' => $profile,
            'applicant' => $people->sole(),
            'account' => $account,
            'customer_hash_id' => trim((string) $account->external_customer_id),
            'applicant_reference_id' => trim((string) $evidence['reference_id']),
            'external_id' => $externalId,
        ];
    }

    private function retryEvidence(UserProviderAccount $account): array
    {
        $states = collect((array) data_get($account->metadata, 'nium_entity_kyc_states', []))
            ->filter(fn ($state): bool => is_array($state)
                && strtolower((string) ($state['entity_type'] ?? '')) === 'applicant'
                && strtolower((string) ($state['kyc_status'] ?? '')) === 'retry'
                && filled($state['external_id'] ?? null)
                && filled($state['provider_reference_id'] ?? null))->values();

        if ($states->count() === 1) {
            $state = $states->sole();
            return ['external_id' => $state['external_id'], 'reference_id' => $state['provider_reference_id']];
        }
        if ($states->count() > 1) {
            throw new RuntimeException('No unique Nium applicant retry evidence is available.');
        }

        $event = WebhookEvent::query()->where('provider_id', $account->provider_id)
            ->where('event_type', 'CUSTOMER_ENTITY_KYC_STATUS')
            ->where('external_resource_id', $account->external_customer_id)
            ->where('processing_status', 'processed')->orderByDesc('processed_at')->orderByDesc('id')->first();
        if (! $event instanceof WebhookEvent
            || ($event->payload['entityType'] ?? null) !== 'applicant'
            || ($event->payload['kycStatus'] ?? null) !== 'retry'
            || ! filled($event->payload['externalId'] ?? null)
            || ! filled($event->payload['referenceId'] ?? null)) {
            throw new RuntimeException('No unique Nium applicant retry evidence is available.');
        }

        return ['external_id' => $event->payload['externalId'], 'reference_id' => $event->payload['referenceId']];
    }

    private function isCorporateApplicant(KycProfile $profile, KycRelatedPerson $person): bool
    {
        $applicant = $profile->relatedPersons->first(fn (KycRelatedPerson $candidate): bool => strtolower((string) $candidate->relationship_type) === 'applicant'
            && $candidate->ownership_percentage !== null && (float) $candidate->ownership_percentage > 0);
        $applicant ??= $profile->relatedPersons->first(fn (KycRelatedPerson $candidate): bool => strtolower((string) $candidate->relationship_type) === 'beneficial_owner'
            && $candidate->ownership_percentage !== null && (float) $candidate->ownership_percentage > 0);

        return $applicant?->is($person) === true;
    }

    private function snapshot(KycRelatedPerson $applicant): array
    {
        return [
            'legal_name' => (string) $applicant->legal_name,
            'first_name' => (string) $applicant->first_name,
            'last_name' => (string) $applicant->last_name,
            'date_of_birth' => $applicant->date_of_birth?->toDateString(),
        ];
    }

    public function sourceFingerprint(array $context): string
    {
        return hash('sha256', json_encode([
            'profile_id' => $context['profile']->id,
            'applicant_id' => $context['applicant']->id,
            'account_id' => $context['account']->id,
            'customer_hash_id' => $context['customer_hash_id'],
            'applicant_reference_id' => $context['applicant_reference_id'],
            'external_id' => $context['external_id'],
            'values' => $this->snapshot($context['applicant']),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function nextVersion(KycProfile $profile): int
    {
        return ((int) $profile->corrections()->max('correction_version')) + 1;
    }

    private function activeCorrection(User $user): ?KycProfileCorrection
    {
        return KycProfileCorrection::query()->whereHas('kycProfile', fn ($query) => $query->where('user_id', $user->id))
            ->where('active_slot', 1)->latest('id')->first();
    }

    private function latestResolvedCorrection(User $user): ?KycProfileCorrection
    {
        return KycProfileCorrection::query()
            ->whereHas('kycProfile', fn ($query) => $query->where('user_id', $user->id))
            ->whereIn('status', ['needs_more_info', 'synced'])
            ->latest('id')->first();
    }
}
