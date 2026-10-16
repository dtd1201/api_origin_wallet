<?php

namespace App\Services\Nium;

use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\NiumRfiCase;
use App\Models\UserProviderAccount;
use App\Support\NiumOperationalData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class NiumRfiWorkflowService
{
    public function __construct(private readonly NiumKycNotificationService $kycNotifications) {}

    public function ingestCustomerEvidence(IntegrationProvider $provider, UserProviderAccount $account, array $payload, ?int $webhookEventId = null, ?string $authenticatedRequestId = null): ?NiumRfiCase
    {
        $status = strtolower(trim((string) ($payload['subStatus'] ?? $payload['rfiStatus'] ?? '')));
        if (! str_contains($status, 'rfi') && ! in_array($status, ['requested', 'action_required'], true)) {
            return null;
        }

        $reference = (string) ($payload['rfiId'] ?? $payload['caseId'] ?? $payload['requestId'] ?? $payload['eventId'] ?? $authenticatedRequestId ?? '');
        if ($reference === '') {
            throw new RuntimeException('Nium onboarding RFI evidence has no immutable provider reference.');
        }

        return NiumRfiCase::query()->firstOrCreate(
            ['provider_id' => $provider->id, 'user_provider_account_id' => $account->id, 'scope' => 'customer', 'origin' => 'webhook', 'provider_reference_fingerprint' => hash('sha256', $reference)],
            [
                'webhook_event_id' => $webhookEventId,
                'status' => 'provisional',
                'evidence' => NiumOperationalData::project($payload),
            ],
        );
    }

    public function saveFactualDraft(NiumRfiCase $case, array $answers, array $fileIds, int $reviewerId): NiumRfiCase
    {
        if ($answers === [] || ! array_is_list($answers)) {
            throw new RuntimeException('Nium RFI response draft must contain factual supplied answer entries.');
        }
        $provenanceAt = now()->toISOString();
        foreach ($answers as &$answer) {
            if (! is_array($answer) || blank($answer['questionId'] ?? null) || ! array_key_exists('answer', $answer) || $answer['answer'] === null) {
                throw new RuntimeException('Nium RFI response draft shape is incomplete.');
            }
            $answer['provenance'] = ['source' => 'human_supplied', 'reviewer_id' => $reviewerId, 'recorded_at' => $provenanceAt];
        }
        unset($answer);
        if ($case->scope === 'transaction') {
            $this->validateTransactionAnswers($case, $answers, $fileIds);
        } else {
            $this->validateCorporateAnswers($case, $answers, $fileIds);
        }
        $usesLocalDocumentIds = $case->scope === 'transaction'
            || collect($fileIds)->every(static fn (mixed $id): bool => is_string($id) && preg_match('/^[1-9][0-9]*$/', $id) === 1);
        $resolvedFileIds = $usesLocalDocumentIds
            ? $this->resolveTransactionDocuments($case, $fileIds)
            : $this->resolveFactualFileIds($case, $fileIds);

        $case->update(['response_draft' => $answers, 'supporting_file_ids' => $resolvedFileIds, 'submission_state' => 'draft']);

        return $case->fresh();
    }

    public function approve(NiumRfiCase $case, int $adminUserId): NiumRfiCase
    {
        if ($case->status !== 'requested' || $case->response_draft === null || collect($case->response_draft)->contains(fn ($answer) => data_get($answer, 'provenance.source') !== 'human_supplied' || blank(data_get($answer, 'provenance.reviewer_id')))) {
            throw new RuntimeException('Nium RFI cannot be approved without a factual response draft.');
        }
        $case->update(['approved_by' => $adminUserId, 'approved_at' => now(), 'submission_state' => 'approved']);

        return $case->fresh();
    }

    public function claimForProviderSubmission(NiumRfiCase $case, ?string $requestId = null, array $evidence = []): NiumRfiCase
    {
        $this->assertProviderContractConfirmed($case);

        return DB::transaction(function () use ($case, $requestId, $evidence): NiumRfiCase {
            $locked = NiumRfiCase::query()->lockForUpdate()->findOrFail($case->id);
            if ($locked->scope === 'transaction'
                && ($locked->status !== 'requested'
                    || strtoupper(trim((string) data_get($locked->evidence, 'rfiStatus'))) !== 'RFI_REQUESTED')) {
                throw new RuntimeException('Transaction RFI submission requires the latest authoritative RFI_REQUESTED status.');
            }
            if ($locked->scope === 'customer' && $locked->status !== 'requested') {
                throw new RuntimeException('Corporate RFI submission requires the latest authoritative requested status.');
            }
            if (! in_array($locked->submission_state, ['approved', 'admin_approved'], true) || $locked->approved_at === null) {
                throw new RuntimeException('Nium RFI submission requires separate human approval and an unclaimed case.');
            }
            $existingEvidence = $locked->provider_response_evidence;
            $existingEvidence = is_array($existingEvidence) && ! array_is_list($existingEvidence)
                ? $existingEvidence
                : [];
            $claimEvidence = ! array_is_list($evidence) ? $evidence : [];
            $locked->update([
                'submission_state' => 'claimed',
                'claimed_at' => now(),
                'provider_response_evidence' => array_filter([
                    ...$existingEvidence,
                    ...$claimEvidence,
                    'x_request_id' => $requestId,
                    'request_correlation_fingerprint' => $requestId ? hash('sha256', $requestId) : null,
                    'claimed_at' => now()->toISOString(),
                ], static fn (mixed $value): bool => $value !== null),
            ]);

            return $locked->fresh();
        });
    }

    public function assertProviderContractConfirmed(NiumRfiCase $case): void
    {
        $prefix = $case->scope === 'customer' ? 'customer' : 'transaction';
        $contract = [
            config("services.nium.{$prefix}_rfi_response_endpoint"),
            config("services.nium.{$prefix}_rfi_response_method"),
            config("services.nium.{$prefix}_rfi_request_schema_version"),
            config("services.nium.{$prefix}_rfi_response_contract_version"),
        ];
        if ($case->scope === 'transaction') {
            $officialEndpoint = '/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/transactions/{authCode}/rfi/upload';
            if ($contract[0] !== $officialEndpoint || strtoupper((string) $contract[1]) !== 'POST') {
                throw new RuntimeException('NIUM_RFI_PROVIDER_CONTRACT_GATE: exact Transaction RFI response contract is not configured.');
            }
        } else {
            $officialEndpoint = '/api/v1/client/{clientHashId}/corporate/rfi';
            $requestSchemaVersion = trim((string) $contract[2]);
            $responseContractVersion = trim((string) $contract[3]);
            if ($contract[0] !== $officialEndpoint
                || strtoupper((string) $contract[1]) !== 'POST'
                || $requestSchemaVersion === ''
                || $responseContractVersion === ''
                || $case->contract_gate === 'provider_contract_unconfirmed'
                || ! hash_equals($responseContractVersion, (string) $case->contract_gate)) {
                throw new RuntimeException('NIUM_RFI_PROVIDER_CONTRACT_GATE: exact Corporate RFI response contract is not configured.');
            }
        }
    }

    public function listPayload(NiumRfiCase $case): array
    {
        $payload = Arr::only($case->toArray(), [
            'id', 'scope', 'origin', 'status', 'contract_gate', 'submission_state', 'approved_at',
            'claimed_at', 'reconciled_at', 'created_at', 'updated_at',
        ]);

        $profile = $case->account?->user?->kycProfile;
        $customerName = $profile?->applicant_type === 'business' && filled($profile->business_name)
            ? trim((string) $profile->business_name)
            : trim((string) ($profile?->legal_name ?? ''));
        $template = is_array(data_get($case->evidence, 'template'))
            ? data_get($case->evidence, 'template')
            : [];
        $templateName = trim((string) ($template['name'] ?? $template['documentType'] ?? ''));
        $providerRemarks = trim((string) data_get($case->evidence, 'remarks', ''));
        $requiredFields = $template['requiredFields'] ?? data_get($case->evidence, 'requiredData', []);

        $providerStatus = strtoupper(trim((string) data_get($case->evidence, 'rfiStatus')));
        $templateId = data_get($case->evidence, 'templateId');
        $providerFingerprint = strtolower((string) $case->provider_reference_fingerprint);
        $templateFingerprint = is_string($templateId) && $templateId !== '' ? hash('sha256', $templateId) : null;
        $submissionEligible = $case->scope === 'customer'
            && $case->origin === 'authoritative_fetch'
            && config('services.nium.customer_rfi_authoritative_submission_enabled') === true
            && config('services.nium.customer_rfi_response_endpoint') === '/api/v1/client/{clientHashId}/corporate/rfi'
            && strtoupper((string) config('services.nium.customer_rfi_response_method')) === 'POST'
            && filled(config('services.nium.customer_rfi_request_schema_version'))
            && filled(config('services.nium.customer_rfi_response_contract_version'))
            && hash_equals((string) config('services.nium.customer_rfi_response_contract_version'), (string) $case->contract_gate)
            && $case->status === 'requested'
            && $providerStatus === 'RFI_REQUESTED'
            && in_array($case->submission_state, ['approved', 'admin_approved'], true)
            && $case->approved_at !== null
            && $case->claimed_at === null
            && $case->contract_gate !== 'provider_contract_unconfirmed';

        return [...$payload,
            'customer_id' => $case->account?->user_id,
            'customer_name' => $customerName !== '' ? $customerName : null,
            'template_name' => $templateName !== '' ? Str::limit($templateName, 160, '') : null,
            'provider_remarks' => $providerRemarks !== '' ? Str::limit($providerRemarks, 500, '') : null,
            'provider_status' => $providerStatus !== '' ? $providerStatus : null,
            'required_document_count' => is_array($requiredFields) ? count($requiredFields) : 0,
            'submission_eligible' => $submissionEligible,
            'provider_reference_fingerprint' => preg_match('/^[a-f0-9]{64}$/', $providerFingerprint) === 1 ? $providerFingerprint : null,
            'template_fingerprint' => $templateFingerprint,
            'status_refresh_eligible' => $case->scope === 'customer' && $case->origin === 'authoritative_fetch',
        ];
    }

    public function detailPayload(NiumRfiCase $case): array
    {
        $case->loadMissing(['account.user.kycProfile', 'documents', 'events']);

        return [...$this->listPayload($case),
            'origin' => $case->origin,
            'evidence' => array_filter([
                'template' => data_get($case->evidence, 'template'),
                'remarks' => data_get($case->evidence, 'remarks'),
                'rfiStatus' => data_get($case->evidence, 'rfiStatus'),
            ], static fn (mixed $value): bool => $value !== null),
            'response_draft' => $case->response_draft,
            'user_explanation' => $case->user_explanation,
            'revision_reason' => $case->revision_reason,
            'sent_to_user_at' => $case->sent_to_user_at,
            'user_submitted_at' => $case->user_submitted_at,
            'admin_reviewed_at' => $case->admin_reviewed_at,
            'documents' => $case->documents->whereNull('pivot.detached_at')->map(fn (KycDocument $document): array => [
                'id' => $document->id,
                'type' => $document->type,
                'status' => $document->status,
                'original_name' => $document->original_name,
                'mime_type' => $document->mime_type,
                'file_size' => $document->file_size,
                'file_url' => $document->file_url,
            ])->values()->all(),
            'history' => $case->events->map(fn ($event): array => [
                'event_type' => $event->event_type,
                'from_state' => $event->from_state,
                'to_state' => $event->to_state,
                'created_at' => $event->created_at,
            ])->all(),
            'supporting_file_count' => $case->documents->whereNull('pivot.detached_at')->count(),
        ];
    }

    public function sendToUser(NiumRfiCase $case, int $adminUserId): NiumRfiCase
    {
        $updated = $this->transition($case, ['new', 'revision_requested'], 'sent_to_user', $adminUserId, [
            'sent_to_user_at' => now(), 'revision_reason' => null,
        ], 'sent_to_user');
        $account = UserProviderAccount::query()->findOrFail($updated->user_provider_account_id);
        $this->kycNotifications->recordRfiSentToUser($account, $updated);

        return $updated;
    }

    public function saveUserDraft(NiumRfiCase $case, int $userId, array $documentIds, ?string $explanation): NiumRfiCase
    {
        return DB::transaction(function () use ($case, $userId, $documentIds, $explanation): NiumRfiCase {
            $locked = NiumRfiCase::query()->with('account')->lockForUpdate()->findOrFail($case->id);
            if ((int) $locked->account?->user_id !== $userId || ! in_array($locked->submission_state, ['sent_to_user', 'user_draft', 'revision_requested'], true)) {
                throw new RuntimeException('This Corporate RFI is not available for a customer draft.');
            }
            $this->syncCustomerDocuments($locked, $userId, $documentIds);
            $from = $locked->submission_state;
            $locked->update(['submission_state' => 'user_draft', 'user_explanation' => $explanation]);
            $this->recordEvent($locked, $userId, 'user_draft_saved', $from, 'user_draft');

            return $locked->fresh();
        });
    }

    public function submitByUser(NiumRfiCase $case, int $userId): NiumRfiCase
    {
        return DB::transaction(function () use ($case, $userId): NiumRfiCase {
            $locked = NiumRfiCase::query()->with(['account', 'documents'])->lockForUpdate()->findOrFail($case->id);
            if ((int) $locked->account?->user_id !== $userId || ! in_array($locked->submission_state, ['user_draft', 'revision_requested'], true)) {
                throw new RuntimeException('This Corporate RFI draft cannot be submitted.');
            }
            if ($locked->documents->whereNull('pivot.detached_at')->isEmpty()) {
                throw new RuntimeException('At least one supporting document is required.');
            }
            $from = $locked->submission_state;
            $locked->update(['submission_state' => 'user_submitted', 'user_submitted_at' => now()]);
            $this->recordEvent($locked, $userId, 'user_submitted', $from, 'user_submitted');

            return $locked->fresh();
        });
    }

    public function requestRevision(NiumRfiCase $case, int $adminUserId, string $reason): NiumRfiCase
    {
        $updated = $this->transition($case, ['user_submitted', 'admin_review'], 'revision_requested', $adminUserId, [
            'revision_reason' => $reason, 'admin_reviewed_at' => now(),
        ], 'revision_requested');
        $updated->documents()->wherePivotNull('detached_at')->update(['status' => 'changes_requested']);

        return $updated->fresh();
    }

    public function approveUserSubmission(NiumRfiCase $case, int $adminUserId): NiumRfiCase
    {
        $updated = $this->transition($case, ['user_submitted', 'admin_review'], 'admin_approved', $adminUserId, [
            'approved_by' => $adminUserId, 'approved_at' => now(), 'admin_reviewed_at' => now(),
        ], 'admin_approved');
        $updated->documents()->wherePivotNull('detached_at')->update(['status' => 'approved']);

        return $updated->fresh();
    }

    private function transition(NiumRfiCase $case, array $fromStates, string $toState, int $actorId, array $attributes, string $eventType): NiumRfiCase
    {
        return DB::transaction(function () use ($case, $fromStates, $toState, $actorId, $attributes, $eventType): NiumRfiCase {
            $locked = NiumRfiCase::query()->lockForUpdate()->findOrFail($case->id);
            if ($locked->origin !== 'authoritative_fetch' || ! in_array($locked->submission_state, $fromStates, true)) {
                throw new RuntimeException('Invalid Corporate RFI workflow transition.');
            }
            $from = $locked->submission_state;
            $locked->update([...$attributes, 'submission_state' => $toState]);
            $this->recordEvent($locked, $actorId, $eventType, $from, $toState);

            return $locked->fresh();
        });
    }

    private function syncCustomerDocuments(NiumRfiCase $case, int $userId, array $documentIds): void
    {
        $documentIds = collect($documentIds)->map(fn ($id): int => (int) $id)->filter()->unique()->values();
        $owned = KycDocument::query()->whereIn('id', $documentIds)
            ->whereHas('kycProfile', fn ($query) => $query->where('user_id', $userId))
            ->where('storage_disk', 'kyc_private')->get();
        if ($owned->count() !== $documentIds->count()) {
            throw new RuntimeException('One or more Corporate RFI documents are unavailable or not owned by this customer.');
        }
        $activeIds = $case->documents()->wherePivotNull('detached_at')->pluck('kyc_documents.id');
        foreach ($activeIds->diff($documentIds) as $documentId) {
            $case->documents()->updateExistingPivot($documentId, ['detached_at' => now()]);
        }
        foreach ($documentIds as $documentId) {
            $case->documents()->syncWithoutDetaching([$documentId => ['attached_by' => $userId, 'detached_at' => null]]);
            $case->documents()->updateExistingPivot($documentId, ['attached_by' => $userId, 'detached_at' => null]);
        }
    }

    private function recordEvent(NiumRfiCase $case, ?int $actorId, string $type, ?string $from, ?string $to, array $metadata = []): void
    {
        $case->events()->create(['actor_user_id' => $actorId, 'event_type' => $type, 'from_state' => $from, 'to_state' => $to, 'metadata' => $metadata ?: null]);
    }

    public function recordOperatorEvent(NiumRfiCase $case, int $actorId, string $type, string $from, string $to): void
    {
        $this->recordEvent($case, $actorId, $type, $from, $to);
    }

    public function requiresAuthoritativeRfiFetch(UserProviderAccount $account): bool
    {
        $subStatus = strtolower(trim((string) $account->provider_sub_status));
        $hasActionableCase = $subStatus === 'rfi_requested'
            || NiumRfiCase::query()->where('user_provider_account_id', $account->id)
                ->whereIn('status', ['provisional', 'requested', 'responded_under_review'])
                ->exists();

        return in_array($subStatus, ['rfi_requested', 'under_review'], true)
            && $hasActionableCase
            && filled($account->external_customer_id)
            && $account->customer_id_verified_at !== null
            && $account->security_conflict_at === null
            && blank($account->security_conflict_reason);
    }

    public function reconcileCustomerEvidence(
        UserProviderAccount $account,
        ?array $authoritativeRfis = null,
    ): void {
        $providerSubStatus = strtolower(trim((string) $account->provider_sub_status));
        $providerStatus = strtolower(trim((string) $account->provider_status));

        if (in_array($providerSubStatus, ['rfi_requested', 'under_review'], true)
            && $this->requiresAuthoritativeRfiFetch($account)) {
            if ($authoritativeRfis === null) {
                throw new RuntimeException('Nium Corporate RFI evidence is required while an actionable RFI is pending.');
            }
            if ($authoritativeRfis === []) {
                throw new RuntimeException('Nium Corporate RFI evidence cannot be empty while an actionable RFI is pending.');
            }

            $hasRequested = collect($authoritativeRfis)->contains(
                fn (array $providerRfi): bool => ($providerRfi['status'] ?? null) === 'RFI_REQUESTED',
            );

            $hasReconcilableAttempt = collect($authoritativeRfis)->contains(function (array $providerRfi) use ($account): bool {
                if (($providerRfi['status'] ?? null) !== 'RFI_RESPONDED' || ! is_string($providerRfi['rfiHashId'] ?? null)) {
                    return false;
                }

                return NiumRfiCase::query()
                    ->where('provider_id', $account->provider_id)
                    ->where('user_provider_account_id', $account->id)
                    ->where('scope', 'customer')
                    ->where('origin', 'authoritative_fetch')
                    ->where('provider_reference_fingerprint', hash('sha256', $providerRfi['rfiHashId']))
                    ->whereIn('submission_state', ['claimed', 'provider_acknowledged', 'unknown', 'responded'])
                    ->exists();
            });

            if ($providerSubStatus === 'rfi_requested' && ! $hasRequested && ! $hasReconcilableAttempt) {
                throw new RuntimeException('Nium Corporate RFI evidence does not contain the requested actionable RFI.');
            }

            DB::transaction(function () use ($account, $authoritativeRfis, $hasRequested): void {
                UserProviderAccount::query()->whereKey($account->id)->update([
                    'rfi_status' => $hasRequested ? 'requested' : 'responded',
                ]);

                foreach ($authoritativeRfis as $providerRfi) {
                    if (! is_array($providerRfi)
                        || blank($providerRfi['rfiHashId'] ?? null)
                        || blank($providerRfi['templateId'] ?? null)
                        || ! is_array($providerRfi['template'] ?? null)
                        || ! in_array($providerRfi['status'] ?? null, ['RFI_REQUESTED', 'RFI_RESPONDED'], true)) {
                        throw new RuntimeException('Nium Corporate RFI evidence contains an invalid provider RFI.');
                    }

                    $fingerprint = hash('sha256', $providerRfi['rfiHashId']);
                    $case = NiumRfiCase::query()->firstOrCreate([
                        'provider_id' => $account->provider_id,
                        'user_provider_account_id' => $account->id,
                        'scope' => 'customer',
                        'origin' => 'authoritative_fetch',
                        'provider_reference_fingerprint' => $fingerprint,
                    ], [
                        'status' => $providerRfi['status'] === 'RFI_REQUESTED' ? 'requested' : 'responded_under_review',
                        'submission_state' => 'new',
                        'evidence' => [],
                    ]);

                    $updates = [
                        'status' => $providerRfi['status'] === 'RFI_RESPONDED'
                            ? 'responded_under_review'
                            : 'requested',
                        'evidence' => [
                            'rfiHashId' => $providerRfi['rfiHashId'],
                            'templateId' => $providerRfi['templateId'],
                            'template' => $providerRfi['template'],
                            'remarks' => $providerRfi['remarks'] ?? null,
                            'rfiStatus' => $providerRfi['status'],
                        ],
                        'reconciled_at' => now(),
                    ];

                    if ($providerRfi['status'] === 'RFI_RESPONDED') {
                        $existingEvidence = (array) $case->provider_response_evidence;
                        if (($existingEvidence['submission_mode'] ?? null) === 'authoritative_legacy') {
                            $requestId = $existingEvidence['x_request_id'] ?? null;
                            $requestFingerprint = $existingEvidence['request_correlation_fingerprint'] ?? null;
                            $persistedProviderFingerprint = $existingEvidence['provider_reference_fingerprint'] ?? null;
                            $persistedTemplateFingerprint = $existingEvidence['template_fingerprint'] ?? null;
                            $alreadyReconciled = $case->submission_state === 'responded'
                                && ($existingEvidence['outcome'] ?? null) === 'RECONCILED';
                            if (! in_array($case->submission_state, ['provider_acknowledged', 'unknown', 'claimed'], true) && ! $alreadyReconciled
                                || ! is_string($requestId) || ! Str::isUuid($requestId)
                                || ! is_string($requestFingerprint)
                                || ! hash_equals(hash('sha256', $requestId), $requestFingerprint)
                                || ! is_string($persistedProviderFingerprint)
                                || ! hash_equals((string) $case->provider_reference_fingerprint, $persistedProviderFingerprint)
                                || ! hash_equals($fingerprint, $persistedProviderFingerprint)
                                || ! is_string($persistedTemplateFingerprint)
                                || ! hash_equals(hash('sha256', (string) $providerRfi['templateId']), $persistedTemplateFingerprint)) {
                                throw new RuntimeException('Authoritative Corporate RFI reconciliation evidence does not match the claimed submission.');
                            }
                            $updates['submission_state'] = 'responded';
                            $updates['provider_response_evidence'] = [
                                ...$existingEvidence,
                                'outcome' => 'RECONCILED',
                                'reason' => 'authoritative_get_confirmed_rfi_responded',
                                'manual_reconciliation_required' => false,
                                'authoritative_rfi_status' => 'RFI_RESPONDED',
                                'provider_reference_fingerprint' => $fingerprint,
                                'template_fingerprint' => hash('sha256', (string) $providerRfi['templateId']),
                                'reconciled_source' => 'authoritative_provider_rfi_fetch',
                                'reconciled_at' => now()->toISOString(),
                            ];
                        } else {
                            $updates['provider_response_evidence'] = array_filter([
                                'rfi_status' => 'RFI_RESPONDED',
                                'rfi_hash_id' => $providerRfi['rfiHashId'],
                                'template_id' => $providerRfi['templateId'],
                                'source' => 'authoritative_provider_rfi_fetch',
                                'recorded_at' => now()->toISOString(),
                            ], static fn (mixed $value): bool => $value !== null && $value !== '');
                        }
                    }

                    NiumRfiCase::query()->whereKey($case->id)->update($updates);
                    if ($updates['status'] === 'requested') {
                        $this->kycNotifications->recordConfirmedRfi(
                            $account,
                            NiumRfiCase::query()->findOrFail($case->id),
                        );
                    }
                }

                NiumRfiCase::query()->where('user_provider_account_id', $account->id)
                    ->where('scope', 'customer')->where('origin', 'webhook')
                    ->whereIn('status', ['provisional', 'requested', 'responded_under_review'])
                    ->update(['status' => 'historical_webhook_evidence', 'reconciled_at' => now()]);
            });

            return;
        }

        if ($providerStatus === 'clear' && $providerSubStatus === '') {
            $account->update(['rfi_status' => 'cleared']);
            NiumRfiCase::query()->where('user_provider_account_id', $account->id)
                ->whereIn('status', ['provisional', 'requested', 'responded_under_review'])
                ->update(['status' => 'resolved_authoritative_clear', 'reconciled_at' => now()]);
        }
    }

    private function resolveFactualFileIds(NiumRfiCase $case, array $fileIds): array
    {
        if ($fileIds === []) {
            return [];
        }
        $account = UserProviderAccount::query()->with('user.kycProfile')->findOrFail($case->user_provider_account_id);
        $resolved = [];
        foreach ($fileIds as $fileId) {
            if (! is_string($fileId) || trim($fileId) === '') {
                throw new RuntimeException('Nium RFI supporting File ID is invalid.');
            }
            $document = KycDocument::query()->where('kyc_profile_id', $account->user?->kycProfile?->id)
                ->where('metadata->nium_file_id', $fileId)->first();
            $metadata = (array) ($document?->metadata ?? []);
            if ($document === null || ! in_array(strtolower((string) $document->status), ['approved', 'verified'], true)
                || $document->status === 'superseded' || ($metadata['factual'] ?? false) !== true
                || ($metadata['synthetic'] ?? true) !== false || strtoupper((string) ($metadata['nium_file_state'] ?? '')) !== 'AVAILABLE'
                || trim((string) ($metadata['nium_file_id'] ?? '')) !== $fileId) {
                throw new RuntimeException('Nium RFI supporting File ID is not approved factual AVAILABLE evidence owned by this account.');
            }
            $resolved[] = ['document_id' => $document->id, 'file_id_fingerprint' => hash('sha256', $fileId), 'provider_file_id' => $fileId];
        }

        return $resolved;
    }

    private function validateTransactionAnswers(NiumRfiCase $case, array $answers, array $fileIds): void
    {
        $allowedFields = [
            'bankAccountNumber', 'bankName', 'companyName', 'dateOfBirth', 'firstName', 'middleName',
            'lastName', 'nationality', 'addressLine1', 'addressLine2', 'city', 'state', 'country',
            'postcode', 'employmentStatus', 'industryType', 'isPep', 'position', 'reasonForTransfer',
            'remitterBeneficiaryRelationship', 'sourceOfFunds', 'thirdPartyFunding', 'otherData',
            'identificationType', 'identificationValue', 'identificationDocIssuanceCountry',
            'identificationIssuingDate', 'identificationIssuingAuthority', 'identificationDocExpiry',
            'identificationDocIssuingAuthority', 'identificationDocReferenceNumber',
        ];
        $requested = collect((array) data_get($case->evidence, 'requiredData', []))
            ->pluck('value')
            ->filter(static fn (mixed $value): bool => is_string($value))
            ->map(static fn (string $value): string => trim($value))
            ->unique()->values()->all();

        $seen = [];
        foreach ($answers as $answer) {
            $field = (string) $answer['questionId'];
            if (! in_array($field, $allowedFields, true) || ! in_array($field, $requested, true)) {
                throw new RuntimeException('Transaction RFI draft contains an unrequested response field.');
            }
            if (isset($seen[$field]) || $answer['answer'] === '' || $answer['answer'] === []) {
                throw new RuntimeException('Transaction RFI draft contains an empty or duplicate response field.');
            }
            if ($this->containsDocumentData($answer['answer'])) {
                throw new RuntimeException('Raw or encoded document data cannot be persisted in a Transaction RFI draft.');
            }
            $seen[$field] = true;
        }

        $requestsDocuments = $this->transactionRfiRequestsDocuments($case);
        if ($fileIds !== [] && ! $requestsDocuments) {
            throw new RuntimeException('Transaction RFI draft contains unrequested supporting documents.');
        }
        if ($requestsDocuments && $fileIds === []) {
            throw new RuntimeException('Transaction RFI draft requires an approved factual supporting document.');
        }
    }

    private function validateCorporateAnswers(NiumRfiCase $case, array $answers, array $fileIds): void
    {
        $requested = collect((array) data_get($case->evidence, 'requiredData', []))
            ->pluck('value')->filter(static fn (mixed $value): bool => is_string($value))
            ->map(static fn (string $value): string => trim($value))->unique()->values()->all();
        $seen = [];
        foreach ($answers as $answer) {
            $field = trim((string) $answer['questionId']);
            if ($requested !== [] && ! in_array($field, $requested, true)) {
                throw new RuntimeException('Corporate RFI draft contains an unrequested response field.');
            }
            if (isset($seen[$field]) || $answer['answer'] === '' || $answer['answer'] === []) {
                throw new RuntimeException('Corporate RFI draft contains an empty or duplicate response field.');
            }
            if ($this->containsDocumentData($answer['answer'])) {
                throw new RuntimeException('Raw or encoded document data cannot be persisted in a Corporate RFI draft.');
            }
            $seen[$field] = true;
        }

        $requestsDocuments = collect((array) data_get($case->evidence, 'requiredData', []))->contains(
            static fn (mixed $required): bool => is_array($required)
                && strtoupper(trim((string) ($required['type'] ?? ''))) === 'DOCUMENT',
        );
        if ($requested !== [] && $fileIds !== [] && ! $requestsDocuments) {
            throw new RuntimeException('Corporate RFI draft contains unrequested supporting documents.');
        }
        if ($requested !== [] && $requestsDocuments && $fileIds === []) {
            throw new RuntimeException('Corporate RFI draft requires an approved factual supporting document.');
        }
    }

    private function resolveTransactionDocuments(NiumRfiCase $case, array $documentIds): array
    {
        $scopeLabel = $case->scope === 'customer' ? 'Corporate RFI' : 'Transaction RFI';
        if ($documentIds === []) {
            return [];
        }
        if (count($documentIds) > 4) {
            throw new RuntimeException("{$scopeLabel} supports at most four documents.");
        }

        $account = UserProviderAccount::query()->with('user.kycProfile')->findOrFail($case->user_provider_account_id);
        $resolved = [];
        $totalBytes = 0;
        foreach ($documentIds as $documentId) {
            if (! is_string($documentId) || preg_match('/^[1-9][0-9]*$/', $documentId) !== 1) {
                throw new RuntimeException("{$scopeLabel} supporting document ID is invalid.");
            }
            $document = KycDocument::query()
                ->whereKey((int) $documentId)
                ->where('kyc_profile_id', $account->user?->kycProfile?->id)
                ->first();
            $metadata = (array) ($document?->metadata ?? []);
            $mime = strtolower(trim((string) $document?->mime_type));
            $size = (int) ($document?->file_size ?? 0);
            if ($document === null
                || ! in_array(strtolower((string) $document->status), ['approved', 'verified'], true)
                || ($metadata['factual'] ?? false) !== true
                || ($metadata['synthetic'] ?? $metadata['synthetic_test'] ?? true) !== false
                || ! in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'], true)
                || $size < 1 || $size > 2 * 1024 * 1024
                || $document->storage_disk !== 'kyc_private'
                || blank($document->file_path) || str_starts_with((string) $document->file_path, '/')
                || str_contains((string) $document->file_path, '..') || blank($document->original_name)) {
                throw new RuntimeException("{$scopeLabel} supporting document is not approved factual owned evidence.");
            }
            $totalBytes += $size;
            if ($totalBytes >= 10 * 1024 * 1024) {
                throw new RuntimeException("{$scopeLabel} supporting documents must total less than 10 MB.");
            }
            $resolved[] = [
                'document_id' => $document->id,
                'file_name' => Str::limit(basename((string) $document->original_name), 255, ''),
                'file_type' => $mime,
                'byte_size' => $size,
                'file_hash_fingerprint' => filled($document->file_hash) ? hash('sha256', (string) $document->file_hash) : null,
            ];
        }

        return $resolved;
    }

    private function transactionRfiRequestsDocuments(NiumRfiCase $case): bool
    {
        return collect((array) data_get($case->evidence, 'requiredData', []))->contains(
            static fn (mixed $required): bool => is_array($required)
                && strtoupper(trim((string) ($required['type'] ?? ''))) === 'DOCUMENT'
                && trim((string) ($required['value'] ?? '')) === 'identificationDocument',
        );
    }

    private function containsDocumentData(mixed $value): bool
    {
        if (is_string($value)) {
            return str_starts_with(strtolower(trim($value)), 'data:') || strlen($value) > 10000;
        }
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), ['base64', 'filecontent', 'documentdata', 'content'], true)) {
                return true;
            }
            if ($this->containsDocumentData($item)) {
                return true;
            }
        }

        return false;
    }
}
