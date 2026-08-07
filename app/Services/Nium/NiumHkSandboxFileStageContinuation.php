<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\KycProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class NiumHkSandboxFileStageContinuation
{
    private const EXPECTED_HASHES = [
        21 => '68e006d3f97f33b24e5ced1a07aaa4ff970270acba6fcee05e7658814a57822a',
        22 => '310f7f2716bf6945d4591e459e13449df2f41e044487ff4c3f36b97228f397a2',
        23 => 'd4b5d6945d047f8a892c7cb93694e37c2dd6efb98b8f63e86b18394f0c2ad953',
    ];

    public function __construct(private readonly NiumFileService $fileService) {}

    public function continueDocument21(): string
    {
        $profile = $this->profile();
        $this->assertRequestCount(58);
        $document = $this->document(21);
        $metadata = (array) $document->metadata;

        if (
            ! isset($metadata['file_stage_execution_marker'])
            || ! is_string($metadata['nium_file_id'] ?? null)
            || ! Str::isUuid($metadata['nium_file_id'])
            || ($metadata['file_stage_state'] ?? null) !== 'HOLD_FILE_NOT_AVAILABLE'
            || strtoupper((string) ($metadata['nium_file_state'] ?? '')) === 'AVAILABLE'
        ) {
            throw new RuntimeException('Document 21 is not at the locked File Details continuation checkpoint.');
        }

        $this->assertUnclaimed($this->document(22));
        $this->assertUnclaimed($this->document(23));
        $this->assertDocument21History(1);

        try {
            $details = $this->fileService->refreshDocumentState($document, $profile->user);
        } catch (Throwable) {
            return 'HOLD_DETAILS_OUTCOME_UNKNOWN';
        }

        $state = strtoupper(trim((string) ($details['state'] ?? '')));
        $document->refresh();
        $document->forceFill(['metadata' => [
            ...(array) $document->metadata,
            'file_stage_state' => $state === 'AVAILABLE' ? 'FILE_AVAILABLE' : 'HOLD_FILE_NOT_AVAILABLE',
        ]])->save();
        $this->assertCustomerPostCount();

        return $state === 'AVAILABLE' ? 'PASS_FILE_AVAILABLE' : 'HOLD_FILE_NOT_AVAILABLE';
    }

    public function resumeNextDocument(): string
    {
        $profile = $this->profile();
        $this->assertRequestCount(59);
        $document21 = $this->document(21);

        if (
            strtoupper((string) (((array) $document21->metadata)['nium_file_state'] ?? '')) !== 'AVAILABLE'
            || (((array) $document21->metadata)['file_stage_state'] ?? null) !== 'FILE_AVAILABLE'
        ) {
            throw new RuntimeException('Document 21 must be AVAILABLE before resuming document 22.');
        }

        $this->assertDocument21History(2);
        $this->assertUnclaimed($this->document(22));
        $this->assertUnclaimed($this->document(23));
        $document22 = DB::transaction(function (): KycDocument {
            $document = KycDocument::query()->lockForUpdate()->findOrFail(22);
            $this->assertUnclaimed($document);
            $metadata = (array) $document->metadata;
            $document->forceFill(['metadata' => [
                ...$metadata,
                'file_stage_execution_marker' => 'nium-v5-hk-file-applicant_authorized_person_identity-create-v1',
                'file_stage_state' => 'CREATE_SUBMITTING',
            ]])->save();

            return $document->fresh();
        }, 1);
        $logMaxId = (int) (ApiRequestLog::query()->max('id') ?? 0);

        try {
            $this->fileService->createFile($document22, $profile->user);
        } catch (Throwable $exception) {
            $rejected = ApiRequestLog::query()->where('id', '>', $logMaxId)
                ->where('request_method', 'POST')->whereBetween('response_status', [400, 599])->exists();
            $document22->refresh();
            $document22->forceFill(['metadata' => [
                ...(array) $document22->metadata,
                'file_stage_state' => $rejected ? 'STOP_CREATE_REJECTED_NO_RETRY' : 'STOP_CREATE_OUTCOME_UNKNOWN_NO_RETRY',
            ]])->save();

            throw new RuntimeException(
                $rejected ? 'HK fixture File Create was rejected; stop without retry.' : 'HK fixture File Create outcome is unknown; stop without retry.',
                0,
                $exception,
            );
        }

        try {
            $details = $this->fileService->refreshDocumentState($document22->fresh(), $profile->user);
        } catch (Throwable) {
            return 'HOLD_DETAILS_OUTCOME_UNKNOWN';
        }

        $state = strtoupper(trim((string) ($details['state'] ?? '')));
        $document22->refresh();
        $document22->forceFill(['metadata' => [
            ...(array) $document22->metadata,
            'file_stage_state' => $state === 'AVAILABLE' ? 'FILE_AVAILABLE' : 'HOLD_FILE_NOT_AVAILABLE',
        ]])->save();
        $this->assertUnclaimed($this->document(23));
        $this->assertCustomerPostCount();

        return $state === 'AVAILABLE' ? 'PASS_DOCUMENT_AVAILABLE' : 'HOLD_FILE_NOT_AVAILABLE';
    }

    public function continueDocument22(): string
    {
        $profile = $this->profile();
        $this->assertRequestCount(61);
        $this->assertAvailable(21);
        $this->assertDocumentHistory(21, 1, 2);
        $this->assertDocumentHistory(22, 1, 1);
        $this->assertUnclaimed($this->document(23));

        $status = $this->continueDetails($this->document(22), $profile);

        if ($status !== 'HOLD_DETAILS_OUTCOME_UNKNOWN') {
            $this->assertRequestCount(62);
        }

        return $status;
    }

    public function resumeDocument23(): string
    {
        $profile = $this->profile();
        $this->assertRequestCount(62);
        $this->assertAvailable(21);
        $this->assertAvailable(22);
        $this->assertDocumentHistory(21, 1, 2);
        $this->assertDocumentHistory(22, 1, 2);
        $this->assertUnclaimed($this->document(23));

        $document23 = DB::transaction(function (): KycDocument {
            $document = KycDocument::query()->lockForUpdate()->findOrFail(23);
            $this->assertUnclaimed($document);
            $document->forceFill(['metadata' => [
                ...(array) $document->metadata,
                'file_stage_execution_marker' => 'nium-v5-hk-file-beneficial_owner_stakeholder_identity-create-v1',
                'file_stage_state' => 'CREATE_SUBMITTING',
            ]])->save();

            return $document->fresh();
        }, 1);

        $status = $this->createAndCheckOnce($document23, $profile);

        if ($status !== 'HOLD_DETAILS_OUTCOME_UNKNOWN') {
            $this->assertRequestCount(64);
        }

        return $status;
    }

    public function continueDocument23(): string
    {
        $profile = $this->profile();
        $this->assertAvailable(21);
        $this->assertAvailable(22);
        $this->assertDocumentHistory(21, 1, 2);
        $this->assertDocumentHistory(22, 1, 2);
        $detailsCount = $this->documentHistory(23)->where('request_method', 'GET')->count();

        if ($detailsCount < 1) {
            throw new RuntimeException('Document 23 has no initial File Details evidence.');
        }

        $this->assertDocumentHistory(23, 1, $detailsCount);
        $this->assertRequestCount(63 + $detailsCount);

        $status = $this->continueDetails($this->document(23), $profile);

        if ($status !== 'HOLD_DETAILS_OUTCOME_UNKNOWN') {
            $this->assertRequestCount(64 + $detailsCount);
        }

        return $status;
    }

    private function continueDetails(KycDocument $document, KycProfile $profile): string
    {
        $metadata = (array) $document->metadata;

        if (
            ! isset($metadata['file_stage_execution_marker'])
            || ! is_string($metadata['nium_file_id'] ?? null)
            || ! Str::isUuid($metadata['nium_file_id'])
            || ($metadata['file_stage_state'] ?? null) !== 'HOLD_FILE_NOT_AVAILABLE'
            || strtoupper((string) ($metadata['nium_file_state'] ?? '')) === 'AVAILABLE'
        ) {
            throw new RuntimeException("Document {$document->id} is not at a File Details continuation checkpoint.");
        }

        try {
            $details = $this->fileService->refreshDocumentState($document, $profile->user);
        } catch (Throwable) {
            return 'HOLD_DETAILS_OUTCOME_UNKNOWN';
        }

        return $this->persistDetailsOutcome($document, $details);
    }

    private function createAndCheckOnce(KycDocument $document, KycProfile $profile): string
    {
        $logMaxId = (int) (ApiRequestLog::query()->max('id') ?? 0);

        try {
            $this->fileService->createFile($document, $profile->user);
        } catch (Throwable $exception) {
            $rejected = ApiRequestLog::query()->where('id', '>', $logMaxId)
                ->where('request_method', 'POST')->whereBetween('response_status', [400, 599])->exists();
            $document->refresh();
            $document->forceFill(['metadata' => [
                ...(array) $document->metadata,
                'file_stage_state' => $rejected ? 'STOP_CREATE_REJECTED_NO_RETRY' : 'STOP_CREATE_OUTCOME_UNKNOWN_NO_RETRY',
            ]])->save();

            throw new RuntimeException(
                $rejected ? 'HK fixture File Create was rejected; stop without retry.' : 'HK fixture File Create outcome is unknown; stop without retry.',
                0,
                $exception,
            );
        }

        try {
            $details = $this->fileService->refreshDocumentState($document->fresh(), $profile->user);
        } catch (Throwable) {
            return 'HOLD_DETAILS_OUTCOME_UNKNOWN';
        }

        return $this->persistDetailsOutcome($document, $details);
    }

    private function persistDetailsOutcome(KycDocument $document, array $details): string
    {
        $state = strtoupper(trim((string) ($details['state'] ?? '')));
        $document->refresh();
        $document->forceFill(['metadata' => [
            ...(array) $document->metadata,
            'file_stage_state' => $state === 'AVAILABLE' ? 'FILE_AVAILABLE' : 'HOLD_FILE_NOT_AVAILABLE',
        ]])->save();
        $this->assertCustomerPostCount();

        return $state === 'AVAILABLE' ? 'PASS_FILE_AVAILABLE' : 'HOLD_FILE_NOT_AVAILABLE';
    }

    private function profile(): KycProfile
    {
        return KycProfile::query()->whereKey(9)->where('user_id', 9)->with('user')->firstOrFail();
    }

    private function document(int $id): KycDocument
    {
        $document = KycDocument::query()->whereKey($id)->where('kyc_profile_id', 9)->firstOrFail();
        $metadata = (array) $document->metadata;
        $expectedHash = self::EXPECTED_HASHES[$id];

        if (($metadata['fixture_marker'] ?? null) !== NiumHkSandboxFileStageRunner::FIXTURE_MARKER) {
            throw new RuntimeException("Document {$id} fixture marker is invalid.");
        }

        $storage = Storage::disk((string) $document->storage_disk);
        $path = (string) $document->file_path;

        if (! $storage->exists($path) || $document->file_hash !== $expectedHash || hash_file('sha256', $storage->path($path)) !== $expectedHash) {
            throw new RuntimeException("Document {$id} artifact hash is invalid.");
        }

        return $document;
    }

    private function assertUnclaimed(KycDocument $document): void
    {
        $metadata = (array) $document->metadata;

        if (isset($metadata['nium_file_id']) || isset($metadata['file_stage_execution_marker'])) {
            throw new RuntimeException("Document {$document->id} must remain unclaimed.");
        }
    }

    private function assertAvailable(int $documentId): void
    {
        $metadata = (array) $this->document($documentId)->metadata;

        if (strtoupper((string) ($metadata['nium_file_state'] ?? '')) !== 'AVAILABLE' || ($metadata['file_stage_state'] ?? null) !== 'FILE_AVAILABLE') {
            throw new RuntimeException("Document {$documentId} must be AVAILABLE.");
        }
    }

    private function assertDocumentHistory(int $documentId, int $posts, int $gets): void
    {
        $logs = $this->documentHistory($documentId);

        if ($logs->where('request_method', 'POST')->count() !== $posts || $logs->where('request_method', 'GET')->count() !== $gets) {
            throw new RuntimeException("Document {$documentId} provider history does not match the locked request counts.");
        }
    }

    private function documentHistory(int $documentId)
    {
        return ApiRequestLog::query()->get()->filter(
            fn (ApiRequestLog $log): bool => (int) data_get($log->request_body, 'kyc_document_id') === $documentId,
        );
    }

    private function assertDocument21History(int $expectedDetailsCount): void
    {
        $logs = ApiRequestLog::query()->get()->filter(
            fn (ApiRequestLog $log): bool => (int) data_get($log->request_body, 'kyc_document_id') === 21,
        );

        if ($logs->where('request_method', 'POST')->count() !== 1 || $logs->where('request_method', 'GET')->count() !== $expectedDetailsCount) {
            throw new RuntimeException('Document 21 provider history does not match the locked Create and Details counts.');
        }
    }

    private function assertRequestCount(int $expected): void
    {
        if (ApiRequestLog::query()->count() !== $expected) {
            throw new RuntimeException("ApiRequestLog count is not the locked value {$expected}.");
        }

        $this->assertCustomerPostCount();
    }

    private function assertCustomerPostCount(): void
    {
        $providerId = IntegrationProvider::query()->where('code', 'nium')->sole()->id;

        if (ApiRequestLog::query()->where('provider_id', $providerId)->where('user_id', 9)
            ->where('operation', 'customer_create')->where('request_method', 'POST')->count() !== 3) {
            throw new RuntimeException('Fixture V4 Nium Customer Create POST count is not the locked value 3.');
        }
    }
}
