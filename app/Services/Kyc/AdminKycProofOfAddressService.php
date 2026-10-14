<?php

namespace App\Services\Kyc;

use App\Models\AuditLog;
use App\Models\KycDocument;
use App\Models\KycDocumentUpload;
use App\Models\KycProfileCorrection;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class AdminKycProofOfAddressService
{
    public function upload(
        User $customer,
        KycProfileCorrection $correction,
        User $admin,
        UploadedFile $file,
        ?string $issuedAt,
        array $verifiedAddress,
    ): KycDocument {
        $this->assertCorrectionBinding($customer, $correction);
        $disk = (string) config('services.kyc.documents_disk', 'kyc_private');
        $hash = hash_file('sha256', $file->getRealPath());
        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';
        $path = sprintf('kyc/%d/documents/%s-proof-of-address-%s.%s', $customer->id, $hash, Str::uuid(), $extension);

        try {
            return DB::transaction(function () use ($customer, $correction, $admin, $file, $issuedAt, $verifiedAddress, $disk, $hash, $path): KycDocument {
                $locked = KycProfileCorrection::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();
                $this->assertCorrectionBinding($customer, $locked);
                $existing = KycDocument::query()
                    ->where('kyc_profile_id', $locked->kyc_profile_id)
                    ->where('kyc_related_person_id', $locked->applicant_related_person_id)
                    ->whereIn('status', ['pending_admin_review', 'approved', 'verified'])
                    ->lockForUpdate()->get()
                    ->filter(fn (KycDocument $document): bool => strtolower(trim((string) data_get(
                        $document->metadata, 'nium_document_type', $document->type,
                    ))) === 'proof_of_address');
                if ($existing->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'file' => 'A current or pending proof-of-address document already exists for this applicant.',
                    ]);
                }
                $residence = strtoupper(trim((string) $locked->applicant?->residence_country_code));
                if (isset($verifiedAddress['country_code']) && $verifiedAddress['country_code'] !== $residence) {
                    throw ValidationException::withMessages([
                        'country_code' => 'The verified address country must match the correction-bound applicant residence.',
                    ]);
                }

                Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));
                $upload = KycDocumentUpload::query()->create([
                    'user_id' => $customer->id,
                    'kyc_profile_correction_id' => $locked->id,
                    'storage_disk' => $disk,
                    'file_path' => $path,
                    'file_hash' => $hash,
                    'mime_type' => (string) $file->getMimeType(),
                    'file_size' => (int) $file->getSize(),
                    'status' => 'referenced',
                    'referenced_at' => now(),
                ]);
                $document = KycDocument::query()->create([
                    'kyc_profile_id' => $locked->kyc_profile_id,
                    'kyc_related_person_id' => $locked->applicant_related_person_id,
                    'type' => 'proof_of_address',
                    'status' => 'pending_admin_review',
                    'file_url' => route('kyc-documents.show', ['user' => $customer, 'artifactHash' => $hash]),
                    'storage_disk' => $disk,
                    'file_path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => (string) $file->getMimeType(),
                    'file_size' => (int) $file->getSize(),
                    'file_hash' => $hash,
                    'issued_at' => $issuedAt,
                    'metadata' => array_filter([
                        'nium_document_type' => 'proof_of_address',
                        'source' => 'admin_uploaded_correction_evidence',
                        'source_correction_id' => $locked->id,
                        'source_upload_id' => $upload->id,
                        'user_provider_account_id' => $locked->user_provider_account_id,
                        'uploaded_by_admin_id' => $admin->id,
                        'verified_residence_address' => $verifiedAddress !== [] ? $verifiedAddress : null,
                    ], static fn (mixed $value): bool => $value !== null),
                ]);
                AuditLog::query()->create([
                    'user_id' => $admin->id,
                    'action' => 'kyc.correction_proof_of_address_uploaded',
                    'entity_type' => 'kyc_document',
                    'entity_id' => (string) $document->id,
                    'new_data' => [
                        'correction_id' => $locked->id,
                        'correction_version' => $locked->correction_version,
                        'provider_account_id' => $locked->user_provider_account_id,
                        'applicant_related_person_id' => $locked->applicant_related_person_id,
                        'status' => 'pending_admin_review',
                        'file_hash_fingerprint' => substr($hash, 0, 16),
                    ],
                ]);

                return $document;
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    public function approve(User $customer, KycProfileCorrection $correction, KycDocument $document, User $admin): KycDocument
    {
        return DB::transaction(function () use ($customer, $correction, $document, $admin): KycDocument {
            $lockedCorrection = KycProfileCorrection::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();
            $this->assertCorrectionBinding($customer, $lockedCorrection);
            $locked = KycDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->kyc_profile_id !== (int) $lockedCorrection->kyc_profile_id
                || (int) $locked->kyc_related_person_id !== (int) $lockedCorrection->applicant_related_person_id
                || strtolower((string) data_get($locked->metadata, 'nium_document_type', $locked->type)) !== 'proof_of_address'
                || (int) data_get($locked->metadata, 'source_correction_id') !== (int) $lockedCorrection->id
                || (int) data_get($locked->metadata, 'user_provider_account_id') !== (int) $lockedCorrection->user_provider_account_id) {
                throw new RuntimeException('The proof-of-address document does not match the correction-bound applicant and provider account.');
            }
            if ($locked->status === 'approved') {
                return $locked;
            }
            if ($locked->status !== 'pending_admin_review') {
                throw new RuntimeException('Only pending proof-of-address evidence can be approved.');
            }
            $upload = KycDocumentUpload::query()->whereKey(data_get($locked->metadata, 'source_upload_id'))->lockForUpdate()->first();
            $actualHash = Storage::disk((string) $locked->storage_disk)->exists((string) $locked->file_path)
                ? hash_file('sha256', Storage::disk((string) $locked->storage_disk)->path((string) $locked->file_path))
                : false;
            $actualSize = is_string($actualHash)
                ? Storage::disk((string) $locked->storage_disk)->size((string) $locked->file_path)
                : false;
            $actualMime = is_string($actualHash)
                ? Storage::disk((string) $locked->storage_disk)->mimeType((string) $locked->file_path)
                : false;
            if (! $upload instanceof KycDocumentUpload
                || (int) $upload->user_id !== (int) $customer->id
                || (int) $upload->kyc_profile_correction_id !== (int) $lockedCorrection->id
                || $upload->status !== 'referenced'
                || ! is_string($actualHash)
                || ! hash_equals((string) $locked->file_hash, $actualHash)
                || ! hash_equals((string) $upload->file_hash, $actualHash)
                || $actualSize !== (int) $locked->file_size
                || (int) $upload->file_size !== (int) $locked->file_size
                || ! is_string($actualMime)
                || ! in_array($actualMime, ['application/pdf', 'image/jpeg', 'image/png'], true)
                || ! hash_equals((string) $locked->mime_type, $actualMime)
                || ! hash_equals((string) $upload->mime_type, $actualMime)) {
                throw new RuntimeException('The proof-of-address upload registry or private file integrity check failed.');
            }
            $metadata = (array) $locked->metadata;
            $metadata['approved_by_admin_id'] = $admin->id;
            $metadata['approved_at'] = now()->toISOString();
            $locked->forceFill(['status' => 'approved', 'metadata' => $metadata])->save();
            AuditLog::query()->create([
                'user_id' => $admin->id,
                'action' => 'kyc.correction_proof_of_address_approved',
                'entity_type' => 'kyc_document',
                'entity_id' => (string) $locked->id,
                'old_data' => ['status' => 'pending_admin_review'],
                'new_data' => ['status' => 'approved', 'correction_id' => $lockedCorrection->id],
            ]);

            return $locked->fresh();
        }, 3);
    }

    private function assertCorrectionBinding(User $customer, KycProfileCorrection $correction): void
    {
        if ($correction->status !== 'manual_kyc_evidence_approved'
            || (int) ($correction->kycProfile?->user_id) !== (int) $customer->id
            || $correction->applicant_related_person_id === null
            || $correction->user_provider_account_id === null
            || (int) ($correction->providerAccount?->user_id) !== (int) $customer->id) {
            throw new RuntimeException('The approved Manual KYC correction binding is not eligible for proof-of-address evidence.');
        }
    }
}
