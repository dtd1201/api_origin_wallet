<?php

namespace App\Services\Kyc;

use App\Models\KycDocument;
use App\Models\KycDocumentUpload;
use App\Models\KycProfileCorrection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class KycManualComplianceReviewService
{
    private const IDENTITY_FIELDS = ['legal_name', 'first_name', 'last_name', 'date_of_birth'];

    public function eligible(KycProfileCorrection $correction): bool
    {
        return $this->changedIdentityFields($correction) !== []
            && count($this->passportDocuments($correction)) === 1;
    }

    public function promotePassport(KycProfileCorrection $correction): KycDocument
    {
        $documents = $this->passportDocuments($correction);
        if ($this->changedIdentityFields($correction) === [] || count($documents) !== 1) {
            throw ValidationException::withMessages([
                'correction' => 'Manual KYC compliance review requires changed applicant identity evidence and exactly one passport.',
            ]);
        }

        $document = $documents[0];
        $this->validatePassport($correction, $document);

        $existing = $correction->kycProfile->documents()
            ->where('kyc_related_person_id', $correction->applicant_related_person_id)
            ->lockForUpdate()->get()
            ->first(fn (KycDocument $candidate): bool => (int) data_get($candidate->metadata, 'source_correction_id') === (int) $correction->id
            );
        if ($existing instanceof KycDocument) {
            return $existing;
        }

        $previous = $correction->applicant->documents()->lockForUpdate()->get()
            ->filter(fn (KycDocument $candidate): bool => in_array(strtolower((string) $candidate->status), ['approved', 'verified'], true))
            ->filter(fn (KycDocument $candidate): bool => in_array(strtolower((string) data_get($candidate->metadata, 'nium_document_type', $candidate->type)), ['passport', 'passport_front'], true))
            ->sortByDesc('id')->first();

        return $correction->kycProfile->documents()->create([
            'kyc_related_person_id' => $correction->applicant_related_person_id,
            'type' => 'passport',
            'status' => 'manual_kyc_evidence_approved',
            'file_url' => $document['file_url'],
            'storage_disk' => $document['storage_disk'],
            'file_path' => $document['file_path'],
            'original_name' => $document['original_name'] ?? null,
            'mime_type' => $document['mime_type'],
            'file_size' => $document['file_size'],
            'file_hash' => $document['file_hash'],
            'side' => $document['side'] ?? null,
            'document_number' => $document['document_number'],
            'issuing_country_code' => strtoupper((string) $document['issuing_country_code']),
            'issued_at' => $document['issued_at'] ?? null,
            'expires_at' => $document['expires_at'],
            'metadata' => [
                ...((array) ($document['metadata'] ?? [])),
                'nium_document_type' => 'passport',
                'source' => 'admin_reviewed_correction',
                'source_correction_id' => $correction->id,
                'source_upload_id' => KycDocumentUpload::query()
                    ->where('storage_disk', $document['storage_disk'])
                    ->where('file_path', $document['file_path'])->value('id'),
                'previous_document_id' => $previous?->id,
            ],
        ]);
    }

    /** @return list<string> */
    public function changedIdentityFields(KycProfileCorrection $correction): array
    {
        $before = (array) $correction->before_snapshot;
        $proposed = (array) $correction->proposed_values;

        return collect(self::IDENTITY_FIELDS)
            ->filter(fn (string $field): bool => array_key_exists($field, $proposed)
                && ($proposed[$field] ?? null) !== ($before[$field] ?? null))
            ->values()->all();
    }

    /** @return list<array> */
    private function passportDocuments(KycProfileCorrection $correction): array
    {
        return collect((array) $correction->proposed_documents)
            ->filter(fn (mixed $document): bool => is_array($document)
                && strtolower((string) ($document['type'] ?? '')) === 'passport')
            ->values()->all();
    }

    private function validatePassport(KycProfileCorrection $correction, array $document): void
    {
        $disk = (string) ($document['storage_disk'] ?? '');
        $path = (string) ($document['file_path'] ?? '');
        $upload = KycDocumentUpload::query()->where('storage_disk', $disk)
            ->where('file_path', $path)->lockForUpdate()->first();
        $valid = $upload instanceof KycDocumentUpload
            && (int) $upload->user_id === (int) $correction->kycProfile->user_id
            && (int) $upload->kyc_profile_correction_id === (int) $correction->id
            && $upload->status === 'referenced'
            && hash_equals((string) $upload->file_hash, (string) ($document['file_hash'] ?? ''))
            && (int) $upload->file_size === (int) ($document['file_size'] ?? 0)
            && hash_equals((string) $upload->mime_type, (string) ($document['mime_type'] ?? ''))
            && $disk === (string) config('services.kyc.documents_disk', 'kyc_private')
            && str_starts_with($path, 'kyc/'.$correction->kycProfile->user_id.'/documents/')
            && ! str_contains($path, '..')
            && Storage::disk($disk)->exists($path)
            && preg_match('/^[A-Za-z0-9]{1,30}$/', (string) ($document['document_number'] ?? '')) === 1
            && preg_match('/^[A-Z]{2}$/', strtoupper((string) ($document['issuing_country_code'] ?? ''))) === 1
            && filled($document['expires_at'] ?? null);

        if (! $valid) {
            throw ValidationException::withMessages([
                'correction' => 'The reviewed passport evidence is incomplete or no longer matches its private upload registry.',
            ]);
        }

        $actualHash = hash_file('sha256', Storage::disk($disk)->path($path));
        $actualSize = Storage::disk($disk)->size($path);
        $actualMime = Storage::disk($disk)->mimeType($path);
        try {
            $expiry = CarbonImmutable::createFromFormat('!Y-m-d', (string) $document['expires_at']);
        } catch (\Throwable) {
            $expiry = false;
        }
        if (! is_string($actualHash) || ! hash_equals((string) $document['file_hash'], $actualHash)
            || $actualSize !== (int) $document['file_size']
            || ! is_string($actualMime) || ! hash_equals((string) $document['mime_type'], $actualMime)
            || $expiry === false || $expiry->format('Y-m-d') !== (string) $document['expires_at'] || ! $expiry->isFuture()) {
            throw ValidationException::withMessages(['correction' => 'The reviewed passport evidence failed its integrity check.']);
        }
    }
}
