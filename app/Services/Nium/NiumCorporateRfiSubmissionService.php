<?php

namespace App\Services\Nium;

use App\Models\KycDocument;
use App\Models\NiumRfiCase;
use App\Models\UserProviderAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class NiumCorporateRfiSubmissionService
{
    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumRfiWorkflowService $workflow,
    ) {}

    public function submit(NiumRfiCase $case): NiumRfiCase
    {
        if ($case->scope !== 'customer' || $case->transaction_id !== null) {
            throw new RuntimeException('Only a customer-scoped Nium RFI case can use the Corporate submission contract.');
        }
        if ($case->status !== 'requested' || $case->submission_state !== 'approved' || $case->approved_at === null) {
            throw new RuntimeException('Corporate RFI submission requires a requested case with separate human approval.');
        }

        $account = UserProviderAccount::query()->with('user')->whereKey($case->user_provider_account_id)
            ->where('provider_id', $case->provider_id)->firstOrFail();
        if ($account->user === null) {
            throw new RuntimeException('Corporate RFI submission requires an authoritative local customer owner.');
        }
        $identifiers = $this->identifiers($case, $account);
        $responses = $this->buildResponses($case, $account, $identifiers['rfiHashId'], $identifiers['rfiTemplateId']);
        $requestId = (string) Str::uuid();
        $claimed = $this->workflow->claimForProviderSubmission($case, $requestId);
        $endpoint = $this->niumService->path((string) config('services.nium.customer_rfi_response_endpoint'), [
            'clientHashId' => $this->niumService->clientId(),
        ]);
        $payload = [
            'caseId' => $identifiers['caseId'],
            'clientId' => $identifiers['clientId'],
            'customerHashId' => $identifiers['customerHashId'],
            'region' => $identifiers['region'],
            'rfiResponseRequest' => $responses,
        ];

        try {
            $response = $this->niumService->postWithRequestId(
                $endpoint, $payload, $account->user, $requestId,
                'corporate_rfi_submit', $claimed->provider_reference_fingerprint,
            );
        } catch (ConnectionException|NiumEvidencePersistenceException) {
            return $this->unknown($claimed, $requestId, 'network_or_transport_uncertainty');
        }

        $statusCode = $response->status();
        if (in_array($statusCode, [408, 429], true) || $statusCode >= 500) {
            return $this->unknown($claimed, $requestId, 'provider_outcome_uncertain', $statusCode);
        }
        if ($statusCode >= 400) {
            return $this->persist($claimed, 'rejected', [
                'x_request_id' => $requestId,
                'request_correlation_fingerprint' => hash('sha256', $requestId),
                'http_status' => $statusCode,
                'outcome' => 'deterministic_rejection',
                'recorded_at' => now()->toISOString(),
            ]);
        }
        if ($statusCode !== 200) {
            return $this->unknown($claimed, $requestId, 'unexpected_provider_success_status', $statusCode);
        }

        $data = $response->json();
        if (! is_array($data)) {
            return $this->unknown($claimed, $requestId, 'malformed_provider_response', $statusCode);
        }
        $providerStatus = is_string($data['status'] ?? null) ? strtoupper(trim($data['status'])) : '';
        $safe = array_filter([
            'status' => $this->bounded($providerStatus, 100),
            'case_id' => $this->bounded($data['caseId'] ?? null, 255),
        ]);
        if ($providerStatus !== 'RFI_RESPONDED') {
            return $this->unknown($claimed, $requestId, 'unexpected_provider_success_status', $statusCode, $safe);
        }

        return $this->persist($claimed, 'responded', [
            ...$safe,
            'x_request_id' => $requestId,
            'request_correlation_fingerprint' => hash('sha256', $requestId),
            'recorded_at' => now()->toISOString(),
        ]);
    }

    private function identifiers(NiumRfiCase $case, UserProviderAccount $account): array
    {
        $metadata = (array) $account->metadata;
        $values = [
            'caseId' => data_get($case->evidence, 'caseId'),
            'customerHashId' => $account->external_customer_id,
            'region' => data_get($case->evidence, 'region') ?? ($metadata['nium_region'] ?? null),
            'rfiHashId' => data_get($case->evidence, 'rfiHashId'),
            'rfiTemplateId' => data_get($case->evidence, 'rfiTemplateId') ?? data_get($case->evidence, 'templateId'),
            'clientId' => data_get($case->evidence, 'clientId') ?? ($metadata['nium_client_id'] ?? $metadata['clientId'] ?? null),
        ];
        foreach ($values as $name => $value) {
            if (! is_string($value) || trim($value) === '' || strlen(trim($value)) > 255) {
                throw new RuntimeException("Corporate RFI submission requires authoritative {$name}.");
            }
            $values[$name] = trim($value);
        }

        return $values;
    }

    private function buildResponses(NiumRfiCase $case, UserProviderAccount $account, string $rfiHashId, string $rfiTemplateId): array
    {
        $requested = collect((array) data_get($case->evidence, 'requiredData', []))
            ->pluck('value')->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => trim($value))->unique()->values()->all();
        $response = ['rfiHashId' => $rfiHashId, 'rfiTemplateId' => $rfiTemplateId];
        $seen = [];
        foreach ((array) $case->response_draft as $answer) {
            if (! is_array($answer) || data_get($answer, 'provenance.source') !== 'human_supplied') {
                throw new RuntimeException('Corporate RFI submission requires factual human-approved answers.');
            }
            $field = trim((string) ($answer['questionId'] ?? ''));
            $value = $answer['answer'] ?? null;
            if (! in_array($field, $requested, true)) {
                throw new RuntimeException('Corporate RFI submission contains an unrequested response field.');
            }
            if (isset($seen[$field]) || $value === null || $value === '' || $value === [] || $this->containsDocumentData($value)) {
                throw new RuntimeException('Corporate RFI submission contains invalid, duplicate, or raw document response data.');
            }
            if (in_array($field, ['rfiHashId', 'rfiTemplateId'], true)) {
                throw new RuntimeException('Corporate RFI provider identifiers cannot be supplied as response fields.');
            }
            $response[$field] = $value;
            $seen[$field] = true;
        }
        $documentFields = collect((array) data_get($case->evidence, 'requiredData', []))
            ->filter(fn ($item) => is_array($item) && strtoupper(trim((string) ($item['type'] ?? ''))) === 'DOCUMENT')
            ->pluck('value')->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => trim($value))->unique()->values()->all();
        $documents = $this->buildDocuments($case, $account);
        if ($documentFields === [] && $documents !== []) {
            throw new RuntimeException('Corporate RFI submission contains unrequested supporting documents.');
        }
        if ($documentFields !== [] && $documents === []) {
            throw new RuntimeException('Corporate RFI submission requires an approved factual supporting document.');
        }
        foreach ($documentFields as $field) {
            $response[$field] = $documents;
        }
        if (count($response) === 2) {
            throw new RuntimeException('Corporate RFI submission cannot contain an empty factual response.');
        }

        return [$response];
    }

    private function buildDocuments(NiumRfiCase $case, UserProviderAccount $account): array
    {
        $documents = [];
        $totalBytes = 0;
        foreach ((array) $case->supporting_file_ids as $reference) {
            if (! is_array($reference) || ! is_int($reference['document_id'] ?? null)) {
                throw new RuntimeException('Corporate RFI document reference is invalid.');
            }
            $document = KycDocument::query()->whereKey($reference['document_id'])
                ->whereHas('kycProfile', fn ($query) => $query->where('user_id', $account->user_id))->first();
            $metadata = (array) ($document?->metadata ?? []);
            $mime = strtolower(trim((string) $document?->mime_type));
            if ($document === null
                || ! in_array(strtolower((string) $document->status), ['approved', 'verified'], true)
                || ($metadata['factual'] ?? false) !== true
                || ($metadata['synthetic'] ?? $metadata['synthetic_test'] ?? true) !== false
                || ! in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'], true)
                || $document->storage_disk !== 'kyc_private'
                || blank($document->file_path) || str_starts_with((string) $document->file_path, '/')
                || str_contains((string) $document->file_path, '..')) {
                throw new RuntimeException('Corporate RFI supporting document failed final factual validation.');
            }
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
                throw new RuntimeException('Each Corporate RFI document must be no larger than 2 MB.');
            }
            $detected = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath));
            $declared = $mime === 'image/jpg' ? 'image/jpeg' : $mime;
            if ($detected !== $declared || ! in_array($detected, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
                throw new RuntimeException('Corporate RFI supporting document MIME does not match the approved file.');
            }
            $totalBytes += $size;
            if ($totalBytes >= 10 * 1024 * 1024) {
                throw new RuntimeException('Corporate RFI documents must total less than 10 MB.');
            }
            if (filled($document->file_hash) && ! hash_equals(strtolower((string) $document->file_hash), hash('sha256', $bytes))) {
                throw new RuntimeException('Corporate RFI supporting document hash does not match approved evidence.');
            }
            $documents[] = [
                'fileName' => Str::limit(basename((string) $document->original_name), 255, ''),
                'fileType' => $mime,
                'document' => base64_encode($bytes),
            ];
        }

        return $documents;
    }

    private function unknown(NiumRfiCase $case, string $requestId, string $reason, ?int $statusCode = null, array $safe = []): NiumRfiCase
    {
        return $this->persist($case, 'unknown', array_filter([
            ...$safe,
            'x_request_id' => $requestId,
            'request_correlation_fingerprint' => hash('sha256', $requestId),
            'outcome' => 'UNKNOWN',
            'manual_reconciliation_required' => true,
            'reason' => $reason,
            'http_status' => $statusCode,
            'recorded_at' => now()->toISOString(),
        ], fn ($value) => $value !== null));
    }

    private function persist(NiumRfiCase $case, string $state, array $evidence): NiumRfiCase
    {
        $affected = NiumRfiCase::query()->whereKey($case->id)->where('submission_state', 'claimed')->update([
            'submission_state' => $state,
            'provider_response_evidence' => $evidence,
            'reconciled_at' => now(),
        ]);
        if ($affected !== 1) {
            throw new RuntimeException('Corporate RFI outcome requires manual reconciliation after persistence uncertainty.');
        }

        return $case->fresh();
    }

    private function bounded(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim(strip_tags((string) $value));

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private function containsDocumentData(mixed $value): bool
    {
        if (is_string($value)) {
            return str_starts_with(strtolower(trim($value)), 'data:') || strlen($value) > 10000;
        }
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), ['base64', 'filecontent', 'documentdata', 'content', 'document'], true)) {
                return true;
            }
            if ($this->containsDocumentData($item)) {
                return true;
            }
        }

        return false;
    }
}
