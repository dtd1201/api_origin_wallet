<?php

namespace App\Services\Nium;

use App\Models\KycDocument;
use App\Models\User;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NiumCustomerDocumentPreparationService
{
    public function __construct(
        private readonly NiumFileService $fileService,
        private readonly NiumCustomerDocumentResolver $documentResolver,
    ) {}

    /**
     * @return array{ready: bool, pending_document_count: int}
     */
    public function prepare(User $user): array
    {
        $user->loadMissing(['kycProfile.documents', 'kycProfile.relatedPersons.documents']);
        $profile = $user->kycProfile;

        if ($profile === null || ! in_array(strtolower((string) $profile->status), ['approved', 'verified'], true)) {
            throw new RuntimeException('An approved internal KYC/KYB profile is required for Nium document preparation.');
        }

        $pendingDocumentCount = 0;
        $firstFailure = null;

        foreach (
            $this->documentResolver->forProfile($profile)
                ->sortBy(fn (KycDocument $document): int => (int) $document->getKey()) as $document
        ) {
            try {
                if ($this->prepareDocument($document, $user) === 'PROCESSING') {
                    $pendingDocumentCount++;
                }
            } catch (Throwable $exception) {
                $firstFailure ??= $exception;
            }
        }

        if ($firstFailure !== null) {
            throw $firstFailure;
        }

        return [
            'ready' => $pendingDocumentCount === 0,
            'pending_document_count' => $pendingDocumentCount,
        ];
    }

    /**
     * The cache lock prevents concurrent uploads during normal execution. It cannot provide
     * exactly-once delivery if Nium accepts the file and the process dies before nium_file_id
     * is persisted. Eliminating that crash window requires a Nium-confirmed idempotency or
     * file-recovery contract; x-request-id is not treated as an idempotency key here.
     */
    private function prepareDocument(KycDocument $document, User $user): string
    {
        $lock = $this->documentLock($document);

        try {
            $acquired = $lock->get();
        } catch (Throwable $exception) {
            throw $this->lockConfigurationException($exception);
        }

        if ($acquired !== true) {
            return 'PROCESSING';
        }

        try {
            return $this->prepareLockedDocument($document, $user);
        } finally {
            $lock->release();
        }
    }

    private function prepareLockedDocument(KycDocument $document, User $user): string
    {
        $document->refresh();
        $fileId = $this->metadataString($document, 'nium_file_id');

        if ($fileId === null) {
            $created = $this->fileService->createFile($document, $user);
            $persistedFileId = $this->metadataString($document, 'nium_file_id');

            if (
                $persistedFileId === null
                || ! Str::isUuid($persistedFileId)
                || ! hash_equals($persistedFileId, (string) ($created['id'] ?? ''))
            ) {
                throw new RuntimeException('Nium file creation returned an invalid file id.');
            }

            $state = $this->acceptedState($document, $created['state'] ?? null);

            if ($state === 'PROCESSING') {
                $details = $this->fileService->refreshDocumentState($document, $user);

                return $this->acceptedState($document, $details['state'] ?? null);
            }

            return $state;
        }

        if (! Str::isUuid($fileId)) {
            throw new RuntimeException("KYC document [{$document->getKey()}] has an invalid Nium file id.");
        }

        $state = $this->normalizedState($document);

        if ($state === 'AVAILABLE') {
            return $state;
        }

        if ($state !== 'PROCESSING') {
            throw new RuntimeException("KYC document [{$document->getKey()}] has an invalid Nium file state.");
        }

        $details = $this->fileService->refreshDocumentState($document, $user);

        return $this->acceptedState($document, $details['state'] ?? null);
    }

    private function documentLock(KycDocument $document): Lock
    {
        $storeName = (string) config('cache.default');
        $driver = (string) config("cache.stores.{$storeName}.driver");

        try {
            $store = Cache::store($storeName)->getStore();
        } catch (Throwable $exception) {
            throw $this->lockConfigurationException($exception);
        }

        if (! $store instanceof LockProvider || $driver === 'null') {
            throw $this->lockConfigurationException();
        }

        $ttl = max(1, (int) config('services.nium.timeout', 30)) + 30;

        try {
            return $store->lock(
                'provider:nium:kyc-document:'.$document->getKey(),
                $ttl,
            );
        } catch (Throwable $exception) {
            throw $this->lockConfigurationException($exception);
        }
    }

    private function lockConfigurationException(?Throwable $previous = null): RuntimeException
    {
        return new RuntimeException(
            'Nium document preparation requires a configured cache store with atomic lock support.',
            0,
            $previous,
        );
    }

    private function acceptedState(KycDocument $document, mixed $state): string
    {
        $state = strtoupper(trim(is_scalar($state) ? (string) $state : ''));

        if (! in_array($state, ['AVAILABLE', 'PROCESSING'], true)) {
            throw new RuntimeException(
                "KYC document [{$document->getKey()}] received an invalid Nium file state.",
            );
        }

        return $state;
    }

    private function normalizedState(KycDocument $document): string
    {
        return strtoupper((string) ($this->metadataString($document, 'nium_file_state') ?? ''));
    }

    private function metadataString(KycDocument $document, string $key): ?string
    {
        $value = ((array) $document->metadata)[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
