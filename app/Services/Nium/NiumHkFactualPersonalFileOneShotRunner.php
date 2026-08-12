<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\KycDocument;
use App\Models\KycProfile;
use App\Models\KycRelatedPerson;
use App\Models\UserProviderAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class NiumHkFactualPersonalFileOneShotRunner
{
    public function __construct(
        private readonly NiumFileService $fileService,
        private readonly NiumHkFactualPersonalDocumentPreparationService $preparation,
    ) {}

    public function audit(int $documentId, string $role): array
    {
        $document = $this->preflight($documentId, $role);

        return ['terminal' => 'READY_FOR_SEPARATE_HUMAN_APPROVAL', 'document_id' => $document->id, 'role' => $role, 'file_create_post_count' => 0];
    }

    public function run(int $documentId, string $role, bool $separateHumanApproval = false): array
    {
        if (! $separateHumanApproval) {
            throw new RuntimeException('Separate human approval is required for factual File Create.');
        }
        $document = $this->preflight($documentId, $role);
        $protected = $this->fingerprint(UserProviderAccount::query()->findOrFail(4));
        $logMaxId = (int) (ApiRequestLog::query()->max('id') ?? 0);
        $document = $this->claim($document, $role);

        try {
            $created = $this->fileService->createFile($document, $document->kycProfile->user);
            if (! is_string($created['id'] ?? null) || ! Str::isUuid($created['id'])) {
                throw new RuntimeException('Successful factual File Create did not persist a valid UUID.');
            }
        } catch (Throwable $exception) {
            $rejected = $this->newLogs($documentId, $logMaxId)->where('request_method', 'POST')->contains(fn ($log) => (int) $log->response_status >= 400);
            $this->mark($documentId, $rejected ? 'STOP_CREATE_REJECTED_NO_RETRY' : 'STOP_CREATE_OUTCOME_UNKNOWN_NO_RETRY');
            return $this->finish($rejected ? 'STOP_CREATE_REJECTED_NO_RETRY' : 'STOP_CREATE_OUTCOME_UNKNOWN_NO_RETRY', $documentId, $logMaxId, $protected);
        }

        try {
            $details = $this->fileService->refreshDocumentState($document->fresh(), $document->kycProfile->user);
        } catch (Throwable) {
            $this->mark($documentId, 'HOLD_DETAILS_OUTCOME_UNKNOWN');
            return $this->finish('HOLD_DETAILS_OUTCOME_UNKNOWN', $documentId, $logMaxId, $protected);
        }

        return $this->persistDetails($documentId, $details, $logMaxId, $protected);
    }

    public function continueDetails(int $documentId, string $role): array
    {
        $document = KycDocument::query()->with('kycProfile.user')->findOrFail($documentId);
        $metadata = (array) $document->metadata;
        if (($metadata['factual_file_role'] ?? null) !== $role
            || ($metadata['file_stage_state'] ?? null) !== 'HOLD_FILE_NOT_AVAILABLE'
            || ! is_string($metadata['nium_file_id'] ?? null) || ! Str::isUuid($metadata['nium_file_id'])) {
            throw new RuntimeException('Factual document is not at a File Details continuation checkpoint.');
        }
        $logMaxId = (int) (ApiRequestLog::query()->max('id') ?? 0);
        $protected = $this->fingerprint(UserProviderAccount::query()->findOrFail(4));
        try {
            $details = $this->fileService->refreshDocumentState($document, $document->kycProfile->user);
        } catch (Throwable) {
            return $this->finish('HOLD_DETAILS_OUTCOME_UNKNOWN', $documentId, $logMaxId, $protected);
        }
        return $this->persistDetails($documentId, $details, $logMaxId, $protected);
    }

    private function preflight(int $documentId, string $role): KycDocument
    {
        $account = UserProviderAccount::query()->whereKey(7)->where('user_id', 9)->firstOrFail();
        KycProfile::query()->whereKey(9)->where('user_id', 9)->firstOrFail();
        KycRelatedPerson::query()->whereKey(14)->where('kyc_profile_id', 9)->firstOrFail();
        $document = KycDocument::query()->with('kycProfile.user')->findOrFail($documentId);
        $this->preparation->assertPrepared($document, $role);
        $metadata = (array) $document->metadata;
        $accountMetadata = (array) $account->metadata;
        $applicantKey = 'ref_'.substr(hash('sha256', 'c620e0e9-ab0a-43bd-aa10-44f573db723a'), 0, 16);
        $stakeholderKey = 'ref_'.substr(hash('sha256', '7609d9d1-9d37-4e08-9197-602d792f7a2e'), 0, 16);
        if (($accountMetadata['nium_submit_kyc_attempts'][$applicantKey]['state'] ?? null) !== 'provider_accepted_200_sandbox_review'
            || ($accountMetadata['nium_submit_kyc_attempts'][$stakeholderKey]['state'] ?? null) !== 'rejected'
            || array_key_exists('nium_stakeholder_submit_kyc_retry_generation_2', $accountMetadata)) {
            throw new RuntimeException('Locked Submit KYC evidence is invalid for factual File stage.');
        }
        $applicantLogs = ApiRequestLog::query()->whereKey(104)->where('operation', 'submit_kyc')->where('request_method', 'POST')
            ->where('external_reference', 'c620e0e9-ab0a-43bd-aa10-44f573db723a')->get();
        $stakeholderLogs = ApiRequestLog::query()->where('operation', 'submit_kyc')->where('request_method', 'POST')
            ->where('external_reference', '7609d9d1-9d37-4e08-9197-602d792f7a2e')->get();
        if ($applicantLogs->count() !== 1 || $stakeholderLogs->count() !== 1 || (int) $stakeholderLogs->sole()->id !== 106) {
            throw new RuntimeException('Locked Submit KYC logs #104/#106 are invalid for factual File stage.');
        }
        if (isset($metadata['file_stage_execution_marker']) || $this->documentLogs($documentId)->where('request_method', 'POST')->isNotEmpty()) {
            throw new RuntimeException('Factual File Create has already been claimed or posted.');
        }
        return $document;
    }

    private function claim(KycDocument $document, string $role): KycDocument
    {
        return DB::transaction(function () use ($document, $role): KycDocument {
            $locked = KycDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->preparation->assertPrepared($locked, $role);
            $metadata = (array) $locked->metadata;
            if (isset($metadata['file_stage_execution_marker'])) {
                throw new RuntimeException('Factual File Create is already claimed.');
            }
            $locked->forceFill(['metadata' => [...$metadata,
                'file_stage_execution_marker' => $role === 'stakeholder_identity'
                    ? 'nium-hk-factual-person14-identity-file-create-v1'
                    : 'nium-hk-factual-person14-poa-file-create-v1',
                'file_stage_state' => 'CREATE_SUBMITTING',
            ]])->save();
            return $locked->fresh(['kycProfile.user']);
        });
    }

    private function persistDetails(int $documentId, array $details, int $logMaxId, string $protected): array
    {
        $document = KycDocument::query()->findOrFail($documentId);
        $fileId = $document->metadata['nium_file_id'] ?? null;
        if (! is_string($fileId) || ! is_string($details['id'] ?? null) || ! hash_equals($fileId, $details['id'])) {
            throw new RuntimeException('Factual File Details returned a mismatching File ID.');
        }
        $available = strtoupper(trim((string) ($details['state'] ?? ''))) === 'AVAILABLE';
        $document->forceFill([
            'status' => $available ? 'approved' : 'pending',
            'metadata' => [...(array) $document->metadata, 'file_stage_state' => $available ? 'FILE_AVAILABLE' : 'HOLD_FILE_NOT_AVAILABLE'],
        ])->save();
        return $this->finish($available ? 'PASS_FILE_AVAILABLE' : 'HOLD_FILE_NOT_AVAILABLE', $documentId, $logMaxId, $protected);
    }

    private function mark(int $documentId, string $state): void
    {
        $document = KycDocument::query()->findOrFail($documentId);
        $document->forceFill(['metadata' => [...(array) $document->metadata, 'file_stage_state' => $state]])->save();
    }

    private function finish(string $terminal, int $documentId, int $logMaxId, string $protected): array
    {
        $logs = $this->newLogs($documentId, $logMaxId);
        if ($logs->where('request_method', 'POST')->count() > 1 || $logs->where('request_method', 'GET')->count() > 1
            || $this->fingerprint(UserProviderAccount::query()->findOrFail(4)) !== $protected) {
            throw new RuntimeException('Factual personal File stage postcondition failed closed.');
        }
        return ['terminal' => $terminal, 'document_id' => $documentId, 'file_create_post_count' => $logs->where('request_method', 'POST')->count(), 'file_details_get_count' => $logs->where('request_method', 'GET')->count()];
    }

    private function documentLogs(int $documentId) { return ApiRequestLog::query()->get()->filter(fn ($log) => (int) data_get($log->request_body, 'kyc_document_id') === $documentId); }
    private function newLogs(int $documentId, int $maxId) { return $this->documentLogs($documentId)->where('id', '>', $maxId); }
    private function fingerprint(UserProviderAccount $account): string { $values = $account->getRawOriginal(); ksort($values); return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)); }
}
