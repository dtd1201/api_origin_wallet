<?php

namespace App\Services\Nium;

use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\NiumRfiCase;
use App\Models\UserProviderAccount;
use App\Support\NiumOperationalData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
        $resolvedFileIds = $this->resolveFactualFileIds($case, $fileIds);

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

    public function claimForProviderSubmission(NiumRfiCase $case): NiumRfiCase
    {
        $prefix = $case->scope === 'customer' ? 'customer' : 'transaction';
        $contract = [
            config("services.nium.{$prefix}_rfi_response_endpoint"),
            config("services.nium.{$prefix}_rfi_response_method"),
            config("services.nium.{$prefix}_rfi_request_schema_version"),
            config("services.nium.{$prefix}_rfi_response_contract_version"),
        ];
        if (collect($contract)->contains(fn ($value) => blank($value))) {
            throw new RuntimeException('NIUM_RFI_PROVIDER_CONTRACT_GATE: exact response endpoint and body are not confirmed.');
        }
        if (! in_array(strtoupper((string) $contract[1]), ['POST', 'PUT'], true)
            || preg_match('/^[A-Za-z0-9._-]{1,80}$/', (string) $contract[2]) !== 1
            || preg_match('/^[A-Za-z0-9._-]{1,80}$/', (string) $contract[3]) !== 1) {
            throw new RuntimeException('NIUM_RFI_PROVIDER_CONTRACT_GATE: configured contract evidence is invalid.');
        }

        return DB::transaction(function () use ($case): NiumRfiCase {
            $locked = NiumRfiCase::query()->lockForUpdate()->findOrFail($case->id);
            if ($locked->submission_state !== 'approved' || $locked->approved_at === null) {
                throw new RuntimeException('Nium RFI submission requires separate human approval and an unclaimed case.');
            }
            $locked->update(['submission_state' => 'claimed', 'claimed_at' => now()]);
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

    public function reconcileCustomerEvidence(UserProviderAccount $account): void
    {
        $outstanding = in_array($account->rfi_status, ['requested', 'action_required'], true)
            || str_contains(strtolower((string) $account->provider_sub_status), 'rfi');
        NiumRfiCase::query()->where('user_provider_account_id', $account->id)
            ->whereIn('status', ['provisional', 'requested'])
            ->update(['status' => $outstanding ? 'requested' : 'resolved_authoritative_clear', 'reconciled_at' => now()]);
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
}
