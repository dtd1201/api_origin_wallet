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
            ['provider_id' => $provider->id, 'user_provider_account_id' => $account->id, 'scope' => 'customer', 'provider_reference_fingerprint' => hash('sha256', $reference)],
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
        }
        $resolvedFileIds = $case->scope === 'transaction'
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

    public function claimForProviderSubmission(NiumRfiCase $case, ?string $requestId = null): NiumRfiCase
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
            if (collect($contract)->contains(fn ($value) => blank($value))) {
                throw new RuntimeException('NIUM_RFI_PROVIDER_CONTRACT_GATE: exact response endpoint and body are not confirmed.');
            }
            if (! in_array(strtoupper((string) $contract[1]), ['POST', 'PUT'], true)
                || preg_match('/^[A-Za-z0-9._-]{1,80}$/', (string) $contract[2]) !== 1
                || preg_match('/^[A-Za-z0-9._-]{1,80}$/', (string) $contract[3]) !== 1) {
                throw new RuntimeException('NIUM_RFI_PROVIDER_CONTRACT_GATE: configured contract evidence is invalid.');
            }
        }

        return DB::transaction(function () use ($case, $requestId): NiumRfiCase {
            $locked = NiumRfiCase::query()->lockForUpdate()->findOrFail($case->id);
            if ($locked->submission_state !== 'approved' || $locked->approved_at === null) {
                throw new RuntimeException('Nium RFI submission requires separate human approval and an unclaimed case.');
            }
            $locked->update([
                'submission_state' => 'claimed',
                'claimed_at' => now(),
                'provider_response_evidence' => array_filter([
                    'x_request_id' => $requestId,
                    'request_correlation_fingerprint' => $requestId ? hash('sha256', $requestId) : null,
                    'claimed_at' => now()->toISOString(),
                ]),
            ]);
            return $locked->fresh();
        });
    }

    public function listPayload(NiumRfiCase $case): array
    {
        return Arr::only($case->toArray(), [
            'id', 'scope', 'status', 'contract_gate', 'submission_state', 'approved_at',
            'claimed_at', 'reconciled_at', 'created_at', 'updated_at',
        ]);
    }

    public function detailPayload(NiumRfiCase $case): array
    {
        return [...$this->listPayload($case), 'evidence' => $case->evidence, 'response_draft' => $case->response_draft,
            'supporting_file_count' => count((array) $case->supporting_file_ids)];
    }

    public function requiresAuthoritativeRfiFetch(UserProviderAccount $account): bool
    {
        return strtolower(trim((string) $account->provider_sub_status)) === 'under_review'
            && NiumRfiCase::query()->where('user_provider_account_id', $account->id)
                ->whereIn('status', ['provisional', 'requested'])
                ->exists();
    }

    public function reconcileCustomerEvidence(
        UserProviderAccount $account,
        ?array $authoritativeRfis = null,
    ): void
    {
        $providerSubStatus = strtolower(trim((string) $account->provider_sub_status));
        $providerStatus = strtolower(trim((string) $account->provider_status));

        if ($providerSubStatus === 'rfi_requested') {
            $account->update(['rfi_status' => 'requested']);
            NiumRfiCase::query()->where('user_provider_account_id', $account->id)
                ->whereIn('status', ['provisional', 'requested'])
                ->update(['status' => 'requested', 'reconciled_at' => now()]);

            return;
        }

        if ($providerSubStatus === 'under_review' && $this->requiresAuthoritativeRfiFetch($account)) {
            if ($authoritativeRfis === null) {
                throw new RuntimeException('Nium Corporate RFI evidence is required while an actionable RFI is under review.');
            }

            $cases = NiumRfiCase::query()->where('user_provider_account_id', $account->id)
                ->whereIn('status', ['provisional', 'requested'])
                ->orderBy('id')
                ->get();
            $matches = $this->matchAuthoritativeRfis($cases->all(), $authoritativeRfis);
            $hasRequested = collect($matches)->contains(
                fn (array $match): bool => $match['provider']['status'] === 'RFI_REQUESTED',
            );

            DB::transaction(function () use ($account, $matches, $hasRequested): void {
                UserProviderAccount::query()->whereKey($account->id)->update([
                    'rfi_status' => $hasRequested ? 'requested' : 'responded',
                ]);

                foreach ($matches as $match) {
                    $providerRfi = $match['provider'];
                    $updates = [
                        'status' => $providerRfi['status'] === 'RFI_RESPONDED'
                            ? 'responded_under_review'
                            : 'requested',
                        'evidence' => array_merge((array) $match['case']->evidence, array_filter([
                            'rfiHashId' => $providerRfi['rfiHashId'],
                            'caseId' => $providerRfi['caseId'] ?? null,
                            'referenceId' => $providerRfi['referenceId'] ?? null,
                            'templateId' => $providerRfi['templateId'] ?? null,
                            'rfiStatus' => $providerRfi['status'],
                        ], static fn (mixed $value): bool => $value !== null && $value !== '')),
                        'reconciled_at' => now(),
                    ];

                    if ($providerRfi['status'] === 'RFI_RESPONDED') {
                        $updates['provider_response_evidence'] = array_filter([
                            'rfi_status' => 'RFI_RESPONDED',
                            'rfi_hash_id' => $providerRfi['rfiHashId'],
                            'case_id' => $providerRfi['caseId'] ?? null,
                            'reference_id' => $providerRfi['referenceId'] ?? null,
                            'template_id' => $providerRfi['templateId'] ?? null,
                            'source' => 'authoritative_provider_rfi_fetch',
                            'recorded_at' => now()->toISOString(),
                        ], static fn (mixed $value): bool => $value !== null && $value !== '');
                    }

                    NiumRfiCase::query()->whereKey($match['case']->id)->update($updates);
                }
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

    private function matchAuthoritativeRfis(array $cases, array $providerRfis): array
    {
        if ($cases === [] || $providerRfis === [] || ! array_is_list($providerRfis)) {
            throw new RuntimeException('Nium Corporate RFI evidence cannot be matched to an actionable local case.');
        }

        foreach ($providerRfis as $providerRfi) {
            if (! is_array($providerRfi)
                || blank($providerRfi['rfiHashId'] ?? null)
                || ! in_array($providerRfi['status'] ?? null, ['RFI_REQUESTED', 'RFI_RESPONDED'], true)) {
                throw new RuntimeException('Nium Corporate RFI evidence contains an invalid provider RFI.');
            }
        }

        $matches = [];
        $usedProviderIndexes = [];
        foreach ($cases as $case) {
            $candidateIndexes = [];
            foreach ($providerRfis as $index => $providerRfi) {
                if (isset($usedProviderIndexes[$index])) {
                    continue;
                }
                if ($this->caseMatchesProviderRfi($case, $providerRfi)) {
                    $candidateIndexes[] = $index;
                }
            }

            if (count($candidateIndexes) === 0
                && count($cases) === 1
                && count($providerRfis) === 1) {
                $candidateIndexes = [0];
            }

            if (count($candidateIndexes) !== 1) {
                throw new RuntimeException('Nium Corporate RFI evidence is ambiguous for the local RFI case.');
            }

            $providerIndex = $candidateIndexes[0];
            $usedProviderIndexes[$providerIndex] = true;
            $matches[] = ['case' => $case, 'provider' => $providerRfis[$providerIndex]];
        }

        return $matches;
    }

    private function caseMatchesProviderRfi(NiumRfiCase $case, array $providerRfi): bool
    {
        $identifiers = array_values(array_filter([
            $providerRfi['rfiHashId'] ?? null,
            $providerRfi['caseId'] ?? null,
            $providerRfi['referenceId'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));

        foreach ($identifiers as $identifier) {
            if (hash_equals($case->provider_reference_fingerprint, hash('sha256', $identifier))) {
                return true;
            }
        }

        $evidence = (array) $case->evidence;
        foreach ([
            'rfiHashId' => 'rfiHashId',
            'rfi_hash_id' => 'rfiHashId',
            'caseId' => 'caseId',
            'case_id' => 'caseId',
            'referenceId' => 'referenceId',
            'reference_id' => 'referenceId',
        ] as $evidenceKey => $providerKey) {
            if (is_string($evidence[$evidenceKey] ?? null)
                && hash_equals($evidence[$evidenceKey], (string) ($providerRfi[$providerKey] ?? ''))) {
                return true;
            }
        }

        return false;
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
            'identificationDocExpiry', 'identificationDocIssuingAuthority', 'identificationDocReferenceNumber',
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

        if ($fileIds !== [] && ! $this->transactionRfiRequestsDocuments($case, $requested)) {
            throw new RuntimeException('Transaction RFI draft contains unrequested supporting documents.');
        }
    }

    private function resolveTransactionDocuments(NiumRfiCase $case, array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }
        if (count($documentIds) > 4) {
            throw new RuntimeException('Transaction RFI supports at most four documents.');
        }

        $account = UserProviderAccount::query()->with('user.kycProfile')->findOrFail($case->user_provider_account_id);
        $resolved = [];
        $totalBytes = 0;
        foreach ($documentIds as $documentId) {
            if (! is_string($documentId) || preg_match('/^[1-9][0-9]*$/', $documentId) !== 1) {
                throw new RuntimeException('Transaction RFI supporting document ID is invalid.');
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
                throw new RuntimeException('Transaction RFI supporting document is not approved factual owned evidence.');
            }
            $totalBytes += $size;
            if ($totalBytes >= 10 * 1024 * 1024) {
                throw new RuntimeException('Transaction RFI supporting documents must total less than 10 MB.');
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

    private function transactionRfiRequestsDocuments(NiumRfiCase $case, array $requested): bool
    {
        if (collect($requested)->contains(static fn (string $value): bool => in_array($value, [
            'identificationDocument', 'identificationDoc', 'document',
        ], true))) {
            return true;
        }

        return filled(data_get($case->evidence, 'documentType'))
            || str_contains(strtoupper((string) data_get($case->evidence, 'type')), 'DOCUMENT');
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
