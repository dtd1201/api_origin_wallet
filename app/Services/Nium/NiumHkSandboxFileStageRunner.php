<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\KycDocument;
use App\Models\KycProfile;
use App\Models\UserProviderAccount;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class NiumHkSandboxFileStageRunner
{
    public const FIXTURE_MARKER = 'nium-corporate-synthetic-v5-hk';

    private const HISTORICAL_DOCUMENT_IDS = [18, 19, 20];

    private const EXPECTED_HASHES = [
        'corporate_registration' => '68e006d3f97f33b24e5ced1a07aaa4ff970270acba6fcee05e7658814a57822a',
        'applicant_authorized_person_identity' => '310f7f2716bf6945d4591e459e13449df2f41e044487ff4c3f36b97228f397a2',
        'beneficial_owner_stakeholder_identity' => 'd4b5d6945d047f8a892c7cb93694e37c2dd6efb98b8f63e86b18394f0c2ad953',
    ];

    public function __construct(private readonly NiumFileService $fileService) {}

    /**
     * @return list<array{document_id: int, logical_role: string, file_state: string}>
     */
    public function run(): array
    {
        $profile = KycProfile::query()->whereKey(9)->where('user_id', 9)->firstOrFail();
        $account4Before = $this->fingerprint(UserProviderAccount::query()->whereKey(4)->firstOrFail());
        $account7 = UserProviderAccount::query()->whereKey(7)->where('user_id', 9)->firstOrFail();
        $account7Before = $this->fingerprint($account7);
        $this->assertPreflightRequestCounts();

        if ($account7->external_customer_id !== null || $account7->external_account_id !== null) {
            throw new RuntimeException('Provider Account 7 is no longer an unresolved fixture account.');
        }

        $documents = $profile->documents()
            ->with('relatedPerson')
            ->get()
            ->filter(fn (KycDocument $document): bool => (((array) $document->metadata)['fixture_marker'] ?? null) === self::FIXTURE_MARKER)
            ->values();

        if ($documents->count() !== 3) {
            throw new RuntimeException('Exactly three isolated HK fixture documents are required.');
        }

        $roles = $documents->map(fn (KycDocument $document): string => $this->validateDocument($document))->sort()->values()->all();
        $expectedRoles = array_keys(self::EXPECTED_HASHES);
        sort($expectedRoles);

        if ($roles !== $expectedRoles) {
            throw new RuntimeException('The isolated HK fixture document roles are incomplete or duplicated.');
        }

        $results = [];

        foreach ($documents->sortBy(fn (KycDocument $document): int => array_search(
            ((array) $document->metadata)['logical_role'],
            array_keys(self::EXPECTED_HASHES),
            true,
        )) as $document) {
            $metadata = (array) $document->metadata;
            $role = (string) $metadata['logical_role'];
            $marker = 'nium-v5-hk-file-'.$role.'-create-v1';
            $document->forceFill(['metadata' => [
                ...$metadata,
                'file_stage_execution_marker' => $marker,
                'file_stage_state' => 'CREATE_SUBMITTING',
            ]])->save();

            try {
                $this->fileService->createFile($document, $profile->user);
            } catch (Throwable $exception) {
                $document->refresh();
                $document->forceFill(['metadata' => [
                    ...(array) $document->metadata,
                    'file_stage_state' => 'STOP_NO_RETRY',
                ]])->save();

                throw new RuntimeException('HK fixture File Create outcome is ambiguous; stop without retry.', 0, $exception);
            }

            $document->refresh();
            $fileId = ((array) $document->metadata)['nium_file_id'] ?? null;

            if (! is_string($fileId) || trim($fileId) === '') {
                throw new RuntimeException('Successful File Create evidence did not persist a File ID.');
            }

            $details = $this->fileService->refreshDocumentState($document, $profile->user);
            $state = strtoupper(trim((string) ($details['state'] ?? '')));
            $document->refresh();
            $document->forceFill(['metadata' => [
                ...(array) $document->metadata,
                'file_stage_state' => 'DETAILS_CHECKED_ONCE',
            ]])->save();
            $results[] = [
                'document_id' => (int) $document->getKey(),
                'logical_role' => $role,
                'file_state' => $state,
            ];
        }

        $this->assertCustomerPostCount();

        if ($account4Before !== $this->fingerprint(UserProviderAccount::query()->whereKey(4)->firstOrFail())) {
            throw new RuntimeException('Protected Account 4 changed during the file stage.');
        }

        if ($account7Before !== $this->fingerprint(UserProviderAccount::query()->whereKey(7)->firstOrFail())) {
            throw new RuntimeException('Provider Account 7 changed during the file stage.');
        }

        return $results;
    }

    private function validateDocument(KycDocument $document): string
    {
        if (in_array((int) $document->getKey(), self::HISTORICAL_DOCUMENT_IDS, true)) {
            throw new RuntimeException('Historical documents 18, 19, and 20 are forbidden.');
        }

        $metadata = (array) $document->metadata;
        $role = is_string($metadata['logical_role'] ?? null) ? $metadata['logical_role'] : '';
        $expectedHash = self::EXPECTED_HASHES[$role] ?? null;

        if ($expectedHash === null || ($metadata['expected_sha256'] ?? null) !== $expectedHash) {
            throw new RuntimeException('HK fixture document role or expected hash is invalid.');
        }

        if (isset($metadata['nium_file_id']) || isset($metadata['file_stage_execution_marker'])) {
            throw new RuntimeException('HK fixture document has already entered the file stage.');
        }

        $disk = trim((string) $document->storage_disk);
        $path = trim((string) $document->file_path);

        if ($disk !== 'kyc_private' || $path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new RuntimeException('HK fixture document storage path is invalid.');
        }

        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            throw new RuntimeException('HK fixture document artifact is missing.');
        }

        $absolutePath = $storage->path($path);
        $root = realpath($storage->path(''));
        $resolved = realpath($absolutePath);

        if ($root === false || $resolved === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) || is_link($absolutePath)) {
            throw new RuntimeException('HK fixture document path escapes private storage.');
        }

        if ((fileperms($absolutePath) & 0077) !== 0) {
            throw new RuntimeException('HK fixture document permissions are not restrictive.');
        }

        if (
            filesize($absolutePath) !== (int) $document->file_size
            || (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath) !== 'application/pdf'
            || hash_file('sha256', $absolutePath) !== $expectedHash
            || $document->file_hash !== $expectedHash
        ) {
            throw new RuntimeException('HK fixture document artifact evidence does not match the reviewed manifest.');
        }

        return $role;
    }

    private function assertPreflightRequestCounts(): void
    {
        if (ApiRequestLog::query()->count() !== 56) {
            throw new RuntimeException('ApiRequestLog count is not the locked value 56.');
        }

        $this->assertCustomerPostCount();
    }

    private function assertCustomerPostCount(): void
    {
        if (ApiRequestLog::query()->where('operation', 'customer_create')->where('request_method', 'POST')->count() !== 3) {
            throw new RuntimeException('Customer Create POST count is not the locked value 3.');
        }
    }

    private function fingerprint(UserProviderAccount $account): string
    {
        $attributes = $account->getRawOriginal();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }
}
