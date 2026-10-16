<?php

namespace App\Services\Nium;

use App\Models\KycDocument;
use App\Models\NiumRfiCase;
use App\Models\UserProviderAccount;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class NiumCorporateRfiPayloadBuilder
{
    /**
     * Build a validated, provider-neutral representation of one authoritative Corporate RFI response.
     * The outer POST envelope remains intentionally excluded until Nium's exact contract is verified.
     */
    public function prepare(NiumRfiCase $case): array
    {
        if ($case->scope !== 'customer' || $case->transaction_id !== null || $case->origin !== 'authoritative_fetch') {
            throw new RuntimeException('Offline Corporate RFI preparation requires an authoritative customer-scoped case.');
        }
        if ($case->status !== 'requested'
            || ! in_array($case->submission_state, ['approved', 'admin_approved'], true)
            || $case->approved_at === null) {
            throw new RuntimeException('Offline Corporate RFI preparation requires separate human approval.');
        }

        $account = UserProviderAccount::query()->with('user.kycProfile')->whereKey($case->user_provider_account_id)
            ->where('provider_id', $case->provider_id)->firstOrFail();
        if ($account->user === null || $account->user->kycProfile === null) {
            throw new RuntimeException('Corporate RFI preparation requires an authoritative local customer owner.');
        }

        $rfiHashId = $this->identifier(data_get($case->evidence, 'rfiHashId'), 'rfiHashId');
        $templateId = $this->identifier(data_get($case->evidence, 'templateId'), 'templateId');
        if (! hash_equals(hash('sha256', $rfiHashId), (string) $case->provider_reference_fingerprint)) {
            throw new RuntimeException('Corporate RFI evidence does not match the case provider reference fingerprint.');
        }

        $template = data_get($case->evidence, 'template');
        if (! is_array($template) || array_is_list($template)) {
            throw new RuntimeException('Corporate RFI preparation requires the authoritative provider template.');
        }
        $rfiType = strtolower($this->identifier($template['rfiType'] ?? null, 'template rfiType'));
        if ($rfiType !== 'corporate') {
            throw new RuntimeException('Corporate RFI preparation requires a corporate provider template.');
        }
        $templateName = $this->identifier($template['name'] ?? null, 'template name');
        $documentType = $this->identifier($template['documentType'] ?? null, 'template documentType');
        $requiredFields = $template['requiredFields'] ?? null;
        if (! is_array($requiredFields) || ! array_is_list($requiredFields) || $requiredFields === []) {
            throw new RuntimeException('Corporate RFI preparation requires authoritative template requiredFields.');
        }

        $documents = null;
        $fields = [];
        $seenFields = [];
        foreach ($requiredFields as $requiredField) {
            if (! is_array($requiredField) || array_is_list($requiredField)) {
                throw new RuntimeException('Corporate RFI requiredFields contains an invalid field definition.');
            }
            $fieldValue = $this->identifier($requiredField['fieldValue'] ?? null, 'required fieldValue');
            $fieldType = strtolower($this->identifier($requiredField['type'] ?? null, 'required field type'));
            if (isset($seenFields[$fieldValue])) {
                throw new RuntimeException('Corporate RFI requiredFields contains a duplicate technical field name.');
            }
            $seenFields[$fieldValue] = true;

            if ($fieldType === 'document') {
                $documents ??= $this->documents($case, $account);
                $fields[] = ['fieldValue' => $fieldValue, 'type' => $fieldType, 'documents' => $documents];
                continue;
            }

            $answer = collect((array) $case->response_draft)->first(
                fn (mixed $item): bool => is_array($item) && ($item['questionId'] ?? null) === $fieldValue,
            );
            if (! is_array($answer)
                || data_get($answer, 'provenance.source') !== 'human_supplied'
                || ! array_key_exists('answer', $answer)
                || $answer['answer'] === '' || $answer['answer'] === null || $answer['answer'] === []) {
                throw new RuntimeException("Corporate RFI preparation requires a factual human-supplied answer for {$fieldValue}.");
            }
            $fields[] = ['fieldValue' => $fieldValue, 'type' => $fieldType, 'answer' => $answer['answer']];
        }

        return [
            'customerHashId' => $this->identifier($account->external_customer_id, 'customerHashId'),
            'rfiHashId' => $rfiHashId,
            'templateId' => $templateId,
            'template' => [
                'name' => $templateName,
                'documentType' => $documentType,
                'rfiType' => $rfiType,
            ],
            'fields' => $fields,
        ];
    }

    private function documents(NiumRfiCase $case, UserProviderAccount $account): array
    {
        $documents = $case->documents()->wherePivotNull('detached_at')->orderBy('kyc_documents.id')->get();
        if ($documents->isEmpty()) {
            throw new RuntimeException('Corporate RFI preparation requires an active approved supporting document.');
        }
        if ($documents->count() > 4) {
            throw new RuntimeException('Corporate RFI preparation supports at most four active documents.');
        }

        $prepared = [];
        $seenHashes = [];
        $totalBytes = 0;
        foreach ($documents as $document) {
            $this->assertDocument($document, $account);
            $storage = Storage::disk('kyc_private');
            $path = (string) $document->file_path;
            if (! $storage->exists($path)) {
                throw new RuntimeException('Corporate RFI supporting document is unavailable.');
            }
            $absolutePath = $storage->path($path);
            $root = realpath($storage->path(''));
            $resolved = realpath($absolutePath);
            if ($root === false || $resolved === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) || is_link($absolutePath)) {
                throw new RuntimeException('Corporate RFI supporting document path is unsafe.');
            }

            $bytes = $storage->get($path);
            $size = strlen($bytes);
            if ($size < 1 || $size > 2 * 1024 * 1024 || $size !== (int) $document->file_size) {
                throw new RuntimeException('Each Corporate RFI document must be no larger than 2 MB and match its recorded size.');
            }
            $totalBytes += $size;
            if ($totalBytes >= 10 * 1024 * 1024) {
                throw new RuntimeException('Corporate RFI documents must total less than 10 MB.');
            }

            $detected = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath));
            $declared = strtolower(trim((string) $document->mime_type));
            $declared = $declared === 'image/jpg' ? 'image/jpeg' : $declared;
            if ($detected !== $declared || ! in_array($detected, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
                throw new RuntimeException('Corporate RFI supporting document MIME does not match the approved file.');
            }
            $hash = hash('sha256', $bytes);
            if (blank($document->file_hash) || ! hash_equals(strtolower((string) $document->file_hash), $hash)) {
                throw new RuntimeException('Corporate RFI supporting document hash does not match approved evidence.');
            }
            if (isset($seenHashes[$hash])) {
                throw new RuntimeException('Corporate RFI preparation rejects duplicate supporting evidence.');
            }
            $seenHashes[$hash] = true;

            $prepared[] = [
                'documentId' => $document->id,
                'fileName' => Str::limit(basename((string) $document->original_name), 255, ''),
                'fileType' => $detected,
                'fileSize' => $size,
                'fileHash' => $hash,
                'document' => base64_encode($bytes),
            ];
        }

        return $prepared;
    }

    private function assertDocument(KycDocument $document, UserProviderAccount $account): void
    {
        $metadata = (array) $document->metadata;
        $owned = $document->kyc_profile_id === $account->user?->kycProfile?->id;
        if (! $owned
            || ! in_array(strtolower((string) $document->status), ['approved', 'verified'], true)
            || ($metadata['factual'] ?? false) !== true
            || ($metadata['synthetic'] ?? $metadata['synthetic_test'] ?? true) !== false
            || $document->storage_disk !== 'kyc_private'
            || blank($document->file_path) || str_starts_with((string) $document->file_path, '/')
            || str_contains((string) $document->file_path, '..')
            || blank($document->original_name) || basename((string) $document->original_name) !== $document->original_name) {
            throw new RuntimeException('Corporate RFI supporting document failed ownership, provenance, status, or storage validation.');
        }
    }

    private function identifier(mixed $value, string $name): string
    {
        if (! is_string($value) || trim($value) === '' || strlen(trim($value)) > 255) {
            throw new RuntimeException("Corporate RFI preparation requires authoritative {$name}.");
        }

        return trim($value);
    }
}
