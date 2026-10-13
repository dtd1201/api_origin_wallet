<?php

namespace App\Services\Kyc;

use App\Models\AuditLog;
use App\Models\KycDocumentUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class KycDocumentUploadCleanupService
{
    public function cleanup(bool $dryRun = true, int $limit = 100): array
    {
        $result = ['examined' => 0, 'eligible' => 0, 'deleted' => 0, 'missing' => 0, 'retained' => 0, 'failed' => 0];
        $ids = KycDocumentUpload::query()->whereIn('status', ['uploaded', 'uploading', 'failed'])->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id');

        foreach ($ids as $id) {
            $claim = DB::transaction(function () use ($id, $dryRun, &$result): ?array {
                $upload = KycDocumentUpload::query()->whereKey($id)->lockForUpdate()->first();
                if (! $upload instanceof KycDocumentUpload
                    || ! in_array($upload->status, ['uploaded', 'uploading', 'failed'], true)
                    || $upload->expires_at?->isFuture()) {
                    return null;
                }
                $result['examined']++;
                if (! $this->hasSafeRegistryIdentity($upload)) {
                    $upload->update(['status' => 'retained', 'expires_at' => null]);
                    $result['retained']++;
                    return null;
                }
                if ($this->isReferenced($upload)) {
                    $upload->update(['status' => 'referenced', 'referenced_at' => now(), 'expires_at' => null]);
                    $result['retained']++;
                    return null;
                }
                $result['eligible']++;
                if ($dryRun) {
                    return null;
                }

                $upload->update(['status' => 'deleting']);

                return ['id' => $upload->id, 'disk' => $upload->storage_disk, 'path' => $upload->file_path];
            }, 3);

            if ($claim === null) {
                continue;
            }

            try {
                $disk = Storage::disk($claim['disk']);
                $exists = $disk->exists($claim['path']);
                if ($exists && ! $disk->delete($claim['path'])) {
                    throw new \RuntimeException('Private storage refused the delete operation.');
                }
                KycDocumentUpload::query()->whereKey($claim['id'])->where('status', 'deleting')->update([
                    'status' => 'deleted', 'deleted_at' => now(),
                ]);
                $exists ? $result['deleted']++ : $result['missing']++;
            } catch (\Throwable) {
                KycDocumentUpload::query()->whereKey($claim['id'])->where('status', 'deleting')->update(['status' => 'failed']);
                $result['failed']++;
            }
        }

        AuditLog::query()->create([
            'user_id' => null, 'action' => 'kyc.document_upload_cleanup',
            'entity_type' => 'kyc_document_upload_cleanup', 'entity_id' => now()->format('YmdHis'),
            'old_data' => null, 'new_data' => ['dry_run' => $dryRun, ...$result],
        ]);

        return $result;
    }

    private function isReferenced(KycDocumentUpload $upload): bool
    {
        if ($upload->kyc_profile_correction_id !== null) {
            return true;
        }
        foreach (['kyc_documents', 'kyc_company_director_documents'] as $table) {
            if (DB::table($table)->where('storage_disk', $upload->storage_disk)->where('file_path', $upload->file_path)->exists()) {
                return true;
            }
        }
        return false;
    }

    private function hasSafeRegistryIdentity(KycDocumentUpload $upload): bool
    {
        $expectedDisk = (string) config('services.kyc.documents_disk', 'kyc_private');
        $expectedPrefix = "kyc/{$upload->user_id}/documents/";
        if ($upload->storage_disk !== $expectedDisk
            || ! str_starts_with($upload->file_path, $expectedPrefix)
            || str_contains($upload->file_path, '..')
            || str_starts_with($upload->file_path, '/')) {
            return false;
        }

        $disk = Storage::disk($upload->storage_disk);
        if (! $disk->exists($upload->file_path)) {
            return true;
        }

        try {
            $contents = $disk->get($upload->file_path);
        } catch (\Throwable) {
            return false;
        }

        return strlen($contents) === (int) $upload->file_size
            && hash_equals((string) $upload->file_hash, hash('sha256', $contents));
    }
}
