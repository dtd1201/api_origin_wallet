<?php

namespace App\Services\Nium;

use App\Models\KycDocument;
use App\Models\NiumRfiCase;
use App\Models\UserProviderAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class NiumTransactionRfiSubmissionService
{
    private const DIRECT_FIELDS = ['bankAccountNumber', 'bankName', 'companyName', 'dateOfBirth', 'firstName', 'middleName', 'lastName', 'nationality'];

    private const ADDRESS_FIELDS = ['addressLine1', 'addressLine2', 'city', 'state', 'country', 'postcode'];

    private const ADDITIONAL_INFO_FIELDS = ['employmentStatus', 'industryType', 'isPep', 'position', 'reasonForTransfer', 'remitterBeneficiaryRelationship', 'sourceOfFunds', 'thirdPartyFunding', 'otherData'];

    private const IDENTIFICATION_FIELDS = ['identificationType', 'identificationValue', 'identificationDocIssuanceCountry', 'identificationIssuingDate', 'identificationIssuingAuthority', 'identificationDocExpiry', 'identificationDocIssuingAuthority', 'identificationDocReferenceNumber'];

    private const SUPPORTED_MIME_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];

    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumRfiWorkflowService $workflow,
        private readonly NiumTransactionRfiOutcomePersister $outcomePersister,
    ) {}

    public function submit(NiumRfiCase $case): NiumRfiCase
    {
        if ($case->scope !== 'transaction' || ! $case->transaction_id) {
            throw new RuntimeException('Only a transaction-scoped Nium RFI case can use this submission contract.');
        }

        $case->loadMissing('transaction.user');
        $account = UserProviderAccount::query()->whereKey($case->user_provider_account_id)
            ->where('provider_id', $case->provider_id)->firstOrFail();
        if ($account->id === 4) {
            throw new RuntimeException('Account 4 is protected and cannot submit a Transaction RFI.');
        }
        $authCode = trim((string) data_get($case->evidence, 'authCode'));
        $rfiHashId = trim((string) data_get($case->evidence, 'rfiHashId'));
        if ($authCode === '' || $rfiHashId === '') {
            throw new RuntimeException('Transaction RFI submission requires authoritative authCode and rfiHashId evidence.');
        }

        $responseInfo = $this->buildFactualResponseInfo($case);
        $derivedIdentificationType = $this->documentRfiIdentificationType($case);
        if ($derivedIdentificationType !== null) {
            $responseInfo['identificationDoc']['identificationType'] = $derivedIdentificationType;
        }
        if ($this->requestsIdentificationDocument($case)
            && blank(data_get($responseInfo, 'identificationDoc.identificationType'))) {
            throw new RuntimeException('Transaction RFI identification document requires a confirmed factual identificationType; none can be derived from authoritative requiredData.');
        }
        if ($responseInfo === [] && (array) $case->supporting_file_ids === []) {
            throw new RuntimeException('Transaction RFI submission cannot contain an empty response.');
        }

        $endpoint = $this->niumService->path((string) config('services.nium.transaction_rfi_response_endpoint'), [
            'clientHashId' => $this->niumService->clientId(),
            'customerHashId' => $account->external_customer_id,
            'walletHashId' => $account->external_account_id,
            'authCode' => $authCode,
        ]);
        $requestId = (string) Str::uuid();
        $claimed = $this->workflow->claimForProviderSubmission($case, $requestId);

        try {
            $documents = $this->buildIdentificationDocuments($claimed, $account);
            if ($documents !== []) {
                $responseInfo['identificationDoc']['identificationDocument'] = $documents;
            }
        } catch (RuntimeException $exception) {
            $this->recordOutcome($claimed, 'rejected', [
                'x_request_id' => $requestId,
                'request_correlation_fingerprint' => hash('sha256', $requestId),
                'outcome' => 'local_document_validation_rejection',
                'recorded_at' => now()->toISOString(),
            ]);
            throw $exception;
        }

        $payload = ['rfiResponseRequest' => [[
            'rfiHashId' => $rfiHashId,
            'rfiResponseInfo' => $responseInfo,
        ]]];

        try {
            $response = $this->niumService->postWithRequestId(
                $endpoint,
                $payload,
                $case->transaction->user,
                $requestId,
                'transaction_rfi_submit',
                $claimed->provider_reference_fingerprint,
            );
        } catch (ConnectionException|NiumEvidencePersistenceException $exception) {
            return $this->markUnknown($claimed, $requestId, 'network_or_transport_uncertainty');
        }

        $statusCode = $response->status();
        if (in_array($statusCode, [408, 429], true) || $statusCode >= 500) {
            return $this->markUnknown($claimed, $requestId, 'provider_outcome_uncertain', $statusCode);
        }
        if ($statusCode >= 400) {
            return $this->recordOutcome($claimed, 'rejected', [
                'x_request_id' => $requestId,
                'request_correlation_fingerprint' => hash('sha256', $requestId),
                'http_status' => $statusCode,
                'outcome' => 'deterministic_rejection',
                'recorded_at' => now()->toISOString(),
            ]);
        }

        $data = $response->json();
        if (! is_array($data)) {
            return $this->markUnknown($claimed, $requestId, 'malformed_provider_response', $statusCode);
        }
        $rawProviderStatus = $data['status'] ?? null;
        $providerStatus = is_string($rawProviderStatus)
            ? strtoupper(trim($rawProviderStatus))
            : '';
        $safeProviderEvidence = array_filter([
            'compliance_id' => $this->bounded($data['complianceId'] ?? null, 255),
            'remarks' => $this->bounded($data['remarks'] ?? null, 500),
            'status' => $this->bounded($providerStatus, 100),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
        if ($providerStatus !== 'RFI_RESPONDED') {
            return $this->markUnknown(
                $claimed,
                $requestId,
                'unexpected_provider_success_status',
                $statusCode,
                $safeProviderEvidence,
            );
        }
        $evidence = [
            ...$safeProviderEvidence,
            'x_request_id' => $requestId,
            'request_correlation_fingerprint' => hash('sha256', $requestId),
            'recorded_at' => now()->toISOString(),
        ];

        return $this->recordOutcome($claimed, 'responded', $evidence);
    }

    private function buildFactualResponseInfo(NiumRfiCase $case): array
    {
        $requested = collect((array) data_get($case->evidence, 'requiredData', []))
            ->pluck('value')->filter(static fn (mixed $value): bool => is_string($value))
            ->map(static fn (string $value): string => trim($value))->unique()->values()->all();
        $responseInfo = [];

        foreach ((array) $case->response_draft as $answer) {
            if (! is_array($answer) || data_get($answer, 'provenance.source') !== 'human_supplied') {
                throw new RuntimeException('Transaction RFI submission requires factual human-supplied answers.');
            }
            $field = trim((string) ($answer['questionId'] ?? ''));
            $value = $answer['answer'] ?? null;
            if (! in_array($field, $requested, true)) {
                throw new RuntimeException('Transaction RFI submission contains an unrequested response field.');
            }
            if ($this->containsDocumentData($value)) {
                throw new RuntimeException('Transaction RFI submission cannot contain raw or encoded document data.');
            }
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (in_array($field, self::DIRECT_FIELDS, true)) {
                $responseInfo[$field] = $value;
            } elseif (in_array($field, self::ADDRESS_FIELDS, true)) {
                $responseInfo['address'][$field] = $value;
            } elseif (in_array($field, self::ADDITIONAL_INFO_FIELDS, true)) {
                $responseInfo['additionalInfo'][$field] = $value;
            } elseif (in_array($field, self::IDENTIFICATION_FIELDS, true)) {
                $responseInfo['identificationDoc'][$field] = $value;
            } else {
                throw new RuntimeException('Transaction RFI submission contains an unsupported response field.');
            }
        }

        return $responseInfo;
    }

    private function buildIdentificationDocuments(NiumRfiCase $case, UserProviderAccount $account): array
    {
        $references = (array) $case->supporting_file_ids;
        $documentRequested = $this->requestsIdentificationDocument($case);
        if (! $documentRequested && $references !== []) {
            throw new RuntimeException('Transaction RFI submission contains unrequested supporting documents.');
        }
        if ($documentRequested && $references === []) {
            throw new RuntimeException('Transaction RFI submission requires an approved factual supporting document.');
        }
        if ($references === []) {
            return [];
        }
        if (count($references) > 4) {
            throw new RuntimeException('Transaction RFI supports at most four documents.');
        }

        $documents = [];
        $totalBytes = 0;
        foreach ($references as $reference) {
            if (! is_array($reference) || ! is_int($reference['document_id'] ?? null)) {
                throw new RuntimeException('Transaction RFI document reference is invalid.');
            }
            $document = KycDocument::query()->whereKey($reference['document_id'])
                ->whereHas('kycProfile', fn ($query) => $query->where('user_id', $account->user_id))
                ->first();
            $metadata = (array) ($document?->metadata ?? []);
            $mime = strtolower(trim((string) $document?->mime_type));
            if ($document === null
                || ! in_array(strtolower((string) $document->status), ['approved', 'verified'], true)
                || ($metadata['factual'] ?? false) !== true
                || ($metadata['synthetic'] ?? $metadata['synthetic_test'] ?? true) !== false
                || ! in_array($mime, self::SUPPORTED_MIME_TYPES, true)
                || $document->storage_disk !== 'kyc_private'
                || blank($document->file_path) || str_starts_with((string) $document->file_path, '/')
                || str_contains((string) $document->file_path, '..')) {
                throw new RuntimeException('Transaction RFI supporting document failed final factual validation.');
            }

            $storage = Storage::disk((string) $document->storage_disk);
            $path = (string) $document->file_path;
            if (! $storage->exists($path)) {
                throw new RuntimeException('Transaction RFI supporting document is unavailable.');
            }
            $absolutePath = $storage->path($path);
            $root = realpath($storage->path(''));
            $resolved = realpath($absolutePath);
            if ($root === false || $resolved === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) || is_link($absolutePath)) {
                throw new RuntimeException('Transaction RFI supporting document path is unsafe.');
            }
            $bytes = $storage->get($path);
            $size = strlen($bytes);
            if ($size < 1 || $size > 2 * 1024 * 1024 || $size !== (int) $document->file_size) {
                throw new RuntimeException('Each Transaction RFI document must be no larger than 2 MB.');
            }
            $detectedMime = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath));
            $normalizedDeclaredMime = $mime === 'image/jpg' ? 'image/jpeg' : $mime;
            if (! in_array($detectedMime, ['image/jpeg', 'image/png', 'application/pdf'], true)
                || $detectedMime !== $normalizedDeclaredMime) {
                throw new RuntimeException('Transaction RFI supporting document MIME does not match the approved file.');
            }
            $totalBytes += $size;
            if ($totalBytes >= 10 * 1024 * 1024) {
                throw new RuntimeException('Transaction RFI documents must total less than 10 MB.');
            }
            if (filled($document->file_hash) && ! hash_equals(strtolower((string) $document->file_hash), hash('sha256', $bytes))) {
                throw new RuntimeException('Transaction RFI supporting document hash does not match approved evidence.');
            }

            $documents[] = [
                'fileName' => Str::limit(basename((string) $document->original_name), 255, ''),
                'fileType' => $mime,
                'document' => base64_encode($bytes),
            ];
        }

        return $documents;
    }

    private function requestsIdentificationDocument(NiumRfiCase $case): bool
    {
        return collect((array) data_get($case->evidence, 'requiredData', []))->contains(
            static fn (mixed $required): bool => is_array($required)
                && strtoupper(trim((string) ($required['type'] ?? ''))) === 'DOCUMENT'
                && trim((string) ($required['value'] ?? '')) === 'identificationDocument',
        );
    }

    private function documentRfiIdentificationType(NiumRfiCase $case): ?string
    {
        $description = strtolower(trim((string) data_get($case->evidence, 'description')));
        $requestInfoFor = strtolower(trim((string) data_get($case->evidence, 'requestInfoFor')));

        if ($description === 'salarystatement' || $requestInfoFor === 'creditor_salarystatement') {
            return 'SALARY_STATEMENT';
        }

        return null;
    }

    private function markUnknown(
        NiumRfiCase $case,
        string $requestId,
        string $reason,
        ?int $statusCode = null,
        array $safeProviderEvidence = [],
    ): NiumRfiCase {
        return $this->recordOutcome($case, 'unknown', array_filter([
            ...$safeProviderEvidence,
            'x_request_id' => $requestId,
            'request_correlation_fingerprint' => hash('sha256', $requestId),
            'outcome' => 'UNKNOWN',
            'manual_reconciliation_required' => true,
            'reason' => $reason,
            'http_status' => $statusCode,
            'recorded_at' => now()->toISOString(),
        ], static fn (mixed $value): bool => $value !== null));
    }

    private function recordOutcome(NiumRfiCase $case, string $submissionState, array $evidence): NiumRfiCase
    {
        try {
            $affected = $this->outcomePersister->persistClaimedOutcome($case->id, $submissionState, $evidence);
            if ($affected !== 1) {
                throw new RuntimeException('Conditional claimed-state outcome update affected an unexpected row count.');
            }

            return $case->fresh();
        } catch (Throwable $exception) {
            throw new NiumTransactionRfiManualReconciliationException([
                'case_id' => $case->id,
                'x_request_id' => $evidence['x_request_id'] ?? data_get($case->provider_response_evidence, 'x_request_id'),
                'request_correlation_fingerprint' => $evidence['request_correlation_fingerprint']
                    ?? data_get($case->provider_response_evidence, 'request_correlation_fingerprint'),
                'claimed_at' => $case->claimed_at?->toISOString(),
                'intended_submission_state' => $submissionState,
                'manual_reconciliation_required' => true,
            ], $exception);
        }
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
