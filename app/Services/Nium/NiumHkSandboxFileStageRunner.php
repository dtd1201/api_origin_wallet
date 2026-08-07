<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\IntegrationProvider;
use App\Models\KycDocument;
use App\Models\KycProfile;
use App\Models\UserProviderAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $requestLogMaxId = (int) (ApiRequestLog::query()->max('id') ?? 0);

        if ($account7->external_customer_id !== null || $account7->external_account_id !== null) {
            throw new RuntimeException('Provider Account 7 is no longer an unresolved fixture account.');
        }

        $profile->load('relatedPersons');
        $roleBindings = $this->roleBindings($profile);
        $documents = $profile->documents()
            ->with('relatedPerson')
            ->get()
            ->filter(fn (KycDocument $document): bool => (((array) $document->metadata)['fixture_marker'] ?? null) === self::FIXTURE_MARKER)
            ->values();

        if ($documents->count() !== 3) {
            throw new RuntimeException('Exactly three isolated HK fixture documents are required.');
        }

        $roles = $documents->map(fn (KycDocument $document): string => $this->validateDocument(
            $document,
            $roleBindings,
        ))->sort()->values()->all();
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
            $role = (string) ((array) $document->metadata)['logical_role'];
            $document = $this->claimDocument(
                (int) $document->getKey(),
                $role,
                $roleBindings,
            );
            $createLogMaxId = (int) (ApiRequestLog::query()->max('id') ?? 0);

            try {
                $this->fileService->createFile($document, $profile->user);
            } catch (Throwable $exception) {
                $rejected = ApiRequestLog::query()
                    ->where('id', '>', $createLogMaxId)
                    ->where('request_method', 'POST')
                    ->where('request_body->kyc_document_id', $document->getKey())
                    ->whereBetween('response_status', [400, 599])
                    ->exists();
                $state = $rejected
                    ? 'STOP_CREATE_REJECTED_NO_RETRY'
                    : 'STOP_CREATE_OUTCOME_UNKNOWN_NO_RETRY';
                $document->refresh();
                $document->forceFill(['metadata' => [
                    ...(array) $document->metadata,
                    'file_stage_state' => $state,
                ]])->save();

                throw new RuntimeException(
                    $rejected
                        ? 'HK fixture File Create was rejected; stop without retry.'
                        : 'HK fixture File Create outcome is unknown; stop without retry.',
                    0,
                    $exception,
                );
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
                'file_stage_state' => $state === 'AVAILABLE'
                    ? 'FILE_AVAILABLE'
                    : 'HOLD_FILE_NOT_AVAILABLE',
            ]])->save();
            $results[] = [
                'document_id' => (int) $document->getKey(),
                'logical_role' => $role,
                'file_state' => $state,
            ];

            if ($state !== 'AVAILABLE') {
                $this->assertCustomerPostCount();

                throw new RuntimeException('HK fixture File Details is not AVAILABLE; hold without retry.');
            }
        }

        $this->assertCustomerPostCount();
        $this->assertSuccessfulRequestEvidence($requestLogMaxId, $documents->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertUniqueAvailableFileIds($documents->pluck('id')->map(fn ($id): int => (int) $id)->all());

        if ($account4Before !== $this->fingerprint(UserProviderAccount::query()->whereKey(4)->firstOrFail())) {
            throw new RuntimeException('Protected Account 4 changed during the file stage.');
        }

        if ($account7Before !== $this->fingerprint(UserProviderAccount::query()->whereKey(7)->firstOrFail())) {
            throw new RuntimeException('Provider Account 7 changed during the file stage.');
        }

        return $results;
    }

    /**
     * @param  array<string, int|null>  $roleBindings
     */
    private function validateDocument(KycDocument $document, array $roleBindings): string
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

        $roleExists = array_key_exists($role, $roleBindings);
        $expectedRelatedPersonId = $roleExists ? $roleBindings[$role] : false;

        if (! $roleExists || $document->kyc_related_person_id !== $expectedRelatedPersonId) {
            throw new RuntimeException('HK fixture document role is not bound to the expected related person.');
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

    /**
     * @param  array<string, int|null>  $roleBindings
     */
    private function claimDocument(int $documentId, string $role, array $roleBindings): KycDocument
    {
        return DB::transaction(function () use ($documentId, $role, $roleBindings): KycDocument {
            $document = KycDocument::query()->with('relatedPerson')->lockForUpdate()->findOrFail($documentId);
            $metadata = (array) $document->metadata;

            if (
                ($metadata['fixture_marker'] ?? null) !== self::FIXTURE_MARKER
                || ($metadata['logical_role'] ?? null) !== $role
                || isset($metadata['nium_file_id'])
                || isset($metadata['file_stage_execution_marker'])
                || ! array_key_exists($role, $roleBindings)
                || $document->kyc_related_person_id !== $roleBindings[$role]
            ) {
                throw new RuntimeException('HK fixture document atomic claim failed before provider HTTP.');
            }

            $document->forceFill(['metadata' => [
                ...$metadata,
                'file_stage_execution_marker' => 'nium-v5-hk-file-'.$role.'-create-v1',
                'file_stage_state' => 'CREATE_SUBMITTING',
            ]])->save();

            return $document->fresh(['relatedPerson']);
        }, 1);
    }

    /**
     * @return array<string, int|null>
     */
    private function roleBindings(KycProfile $profile): array
    {
        $applicants = $profile->relatedPersons->filter(fn ($person): bool => in_array(
            strtolower(trim((string) $person->relationship_type)),
            ['applicant', 'authorized_representative', 'authorised_representative'],
            true,
        ));
        $stakeholders = $profile->relatedPersons->reject(fn ($person): bool => $applicants->contains(
            fn ($applicant): bool => $applicant->is($person),
        ));

        if ($applicants->count() !== 1 || $stakeholders->count() !== 1) {
            throw new RuntimeException('Exactly one applicant and one stakeholder are required for the HK fixture.');
        }

        return [
            'corporate_registration' => null,
            'applicant_authorized_person_identity' => (int) $applicants->first()->getKey(),
            'beneficial_owner_stakeholder_identity' => (int) $stakeholders->first()->getKey(),
        ];
    }

    /**
     * @param  list<int>  $documentIds
     */
    private function assertSuccessfulRequestEvidence(int $requestLogMaxId, array $documentIds): void
    {
        $logs = ApiRequestLog::query()->where('id', '>', $requestLogMaxId)->get();

        if ($logs->count() !== 6 || $logs->where('request_method', 'POST')->count() !== 3 || $logs->where('request_method', 'GET')->count() !== 3) {
            throw new RuntimeException('HK fixture file-stage request evidence is not exactly three Create and three Details requests.');
        }

        foreach ($documentIds as $documentId) {
            if ($logs->filter(fn (ApiRequestLog $log): bool => (int) data_get($log->request_body, 'kyc_document_id') === $documentId)->count() !== 2) {
                throw new RuntimeException('HK fixture file-stage evidence is incomplete for a document.');
            }
        }

        if (ApiRequestLog::query()->count() !== 62) {
            throw new RuntimeException('ApiRequestLog count is not the expected successful value 62.');
        }
    }

    /**
     * @param  list<int>  $documentIds
     */
    private function assertUniqueAvailableFileIds(array $documentIds): void
    {
        $documents = KycDocument::query()->whereKey($documentIds)->get();
        $fileIds = $documents->map(fn (KycDocument $document): mixed => ((array) $document->metadata)['nium_file_id'] ?? null);

        if (
            $documents->count() !== 3
            || $documents->contains(fn (KycDocument $document): bool => strtoupper((string) (((array) $document->metadata)['nium_file_state'] ?? '')) !== 'AVAILABLE')
            || $fileIds->contains(fn ($fileId): bool => ! is_string($fileId) || ! Str::isUuid($fileId))
            || $fileIds->unique()->count() !== 3
        ) {
            throw new RuntimeException('HK fixture files are not three unique AVAILABLE File IDs.');
        }
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
        $niumProviderId = IntegrationProvider::query()->where('code', 'nium')->sole()->getKey();

        if (ApiRequestLog::query()
            ->where('provider_id', $niumProviderId)
            ->where('user_id', 9)
            ->where('operation', 'customer_create')
            ->where('request_method', 'POST')
            ->count() !== 3) {
            throw new RuntimeException('Fixture V4 Nium Customer Create POST count is not the locked value 3.');
        }
    }

    private function fingerprint(UserProviderAccount $account): string
    {
        $attributes = $account->getRawOriginal();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }
}
