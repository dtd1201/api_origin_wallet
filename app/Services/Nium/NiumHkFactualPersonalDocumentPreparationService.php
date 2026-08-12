<?php

namespace App\Services\Nium;

use App\Models\KycDocument;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class NiumHkFactualPersonalDocumentPreparationService
{
    private const ROLES = [
        'stakeholder_identity' => ['types' => ['passport_front', 'passport'], 'purpose' => 'passport', 'mimes' => ['application/pdf', 'image/jpeg', 'image/png']],
        'stakeholder_proof_of_address' => ['types' => ['bank_statement'], 'purpose' => 'bank_statement', 'mimes' => ['application/pdf']],
    ];

    public function prepare(string $role, string $path, string $expectedSha256, string $type): KycDocument
    {
        $contract = self::ROLES[$role] ?? throw new RuntimeException('Unsupported factual personal document role.');
        if (! in_array($type, $contract['types'], true)) {
            throw new RuntimeException('Document type is not allowed for the factual personal role.');
        }

        $evidence = $this->fileEvidence($path, $expectedSha256, $contract['mimes']);
        $this->assertUnique($role, $path, $expectedSha256);

        return KycDocument::query()->create([
            'kyc_profile_id' => 9,
            'kyc_related_person_id' => 14,
            'type' => $type,
            'status' => 'pending',
            'file_url' => 'private://prepared',
            'storage_disk' => 'kyc_private',
            'file_path' => $path,
            'original_name' => basename($path),
            'mime_type' => $evidence['mime'],
            'file_size' => $evidence['size'],
            'file_hash' => $expectedSha256,
            'metadata' => [
                ...NiumHkManualKycDocumentResolver::FUTURE_FACTUAL_METADATA,
                'document_purpose' => $contract['purpose'],
                'factual_file_role' => $role,
                'factual_file_expected_sha256' => $expectedSha256,
                'file_stage_state' => 'FACTUAL_FILE_PREPARED',
            ],
        ]);
    }

    public function assertPrepared(KycDocument $document, string $role): void
    {
        $contract = self::ROLES[$role] ?? throw new RuntimeException('Unsupported factual personal document role.');
        $metadata = (array) $document->metadata;
        if ((int) $document->kyc_profile_id !== 9 || (int) $document->kyc_related_person_id !== 14
            || ! in_array($document->type, $contract['types'], true)
            || $document->status !== 'pending'
            || ($metadata['document_purpose'] ?? null) !== $contract['purpose']
            || ($metadata['factual_file_role'] ?? null) !== $role
            || ($metadata['file_stage_state'] ?? null) !== 'FACTUAL_FILE_PREPARED'
            || isset($metadata['nium_file_id'])) {
            throw new RuntimeException('Factual personal document is not at the prepared checkpoint.');
        }
        foreach (NiumHkManualKycDocumentResolver::FUTURE_FACTUAL_METADATA as $key => $value) {
            if (($metadata[$key] ?? null) !== $value) {
                throw new RuntimeException('Factual personal provenance metadata is invalid.');
            }
        }
        $this->fileEvidence((string) $document->file_path, (string) $document->file_hash, $contract['mimes'], $document);
    }

    private function fileEvidence(string $path, string $hash, array $mimes, ?KycDocument $document = null): array
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || ! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new RuntimeException('Private artifact path or reviewed SHA-256 is invalid.');
        }
        $disk = Storage::disk('kyc_private');
        if (! $disk->exists($path)) {
            throw new RuntimeException('Private factual artifact is missing.');
        }
        $root = realpath($disk->path(''));
        $resolved = realpath($disk->path($path));
        if ($root === false || $resolved === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) || is_link($disk->path($path))) {
            throw new RuntimeException('Private factual artifact escapes storage or is a symlink.');
        }
        if ((fileperms($resolved) & 077) !== 0) {
            throw new RuntimeException('Private factual artifact permissions are too broad.');
        }
        $actualHash = hash_file('sha256', $resolved);
        $size = filesize($resolved);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($resolved);
        if (! is_string($actualHash) || ! hash_equals($hash, $actualHash)) {
            throw new RuntimeException('Private factual artifact SHA-256 mismatch.');
        }
        if ($document !== null && ((string) $document->file_hash !== $hash || (int) $document->file_size !== $size)) {
            throw new RuntimeException('Stored factual artifact integrity evidence is invalid.');
        }
        if (! is_string($mime) || ! in_array($mime, $mimes, true)) {
            throw new RuntimeException('Private factual artifact MIME is not allowed.');
        }
        return ['size' => $size, 'mime' => $mime];
    }

    private function assertUnique(string $role, string $path, string $hash): void
    {
        if (KycDocument::query()->whereIn('id', [18, 19, 20, 21, 22, 23, 24, 25])->where(fn ($q) => $q->where('file_hash', $hash)->orWhere('file_path', $path))->exists()
            || KycDocument::query()->where('kyc_related_person_id', 14)->where(fn ($q) => $q->where('file_hash', $hash)->orWhere('file_path', $path))->exists()
            || KycDocument::query()->where('kyc_related_person_id', 14)->get()->contains(fn (KycDocument $d) => (($d->metadata['factual_file_role'] ?? null) === $role && isset($d->metadata['file_stage_execution_marker'])))) {
            throw new RuntimeException('Factual personal document artifact or execution is already recorded.');
        }
    }
}
