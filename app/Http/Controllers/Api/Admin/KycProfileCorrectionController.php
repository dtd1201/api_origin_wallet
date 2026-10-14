<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycProfileCorrection;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Kyc\AdminKycCorrectionReviewService;
use App\Services\Kyc\AdminKycCorrectionSyncService;
use App\Services\Kyc\AdminKycProofOfAddressService;
use App\Services\Kyc\KycApplicantCorrectionService;
use App\Services\Kyc\KycCorrectionSyncPolicy;
use App\Services\Kyc\KycManualComplianceReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class KycProfileCorrectionController extends Controller
{
    public function uploadProofOfAddress(
        Request $request,
        User $user,
        KycProfileCorrection $correction,
        AdminKycProofOfAddressService $service,
    ): JsonResponse {
        $this->assertOwnedBy($user, $correction);
        $validated = $request->validate([
            'type' => ['required', Rule::in(['proof_of_address'])],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:20480'],
            'issued_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'state' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
        ]);
        $address = collect(['address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country_code'])
            ->mapWithKeys(fn (string $key): array => [$key => isset($validated[$key])
                ? ($key === 'country_code' ? strtoupper(trim((string) $validated[$key])) : trim((string) $validated[$key]))
                : null])
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')->all();
        try {
            $document = $service->upload(
                $user, $correction, $request->user(), $request->file('file'),
                $validated['issued_at'] ?? null, $address,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Proof of Address uploaded and awaiting explicit Admin approval.',
            'document' => $this->serializeProofOfAddress($document),
        ], 201);
    }

    public function approveProofOfAddress(
        Request $request,
        User $user,
        KycProfileCorrection $correction,
        KycDocument $document,
        AdminKycProofOfAddressService $service,
    ): JsonResponse {
        $this->assertOwnedBy($user, $correction);
        try {
            $approved = $service->approve($user, $correction, $document, $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Proof of Address approved. It will be prepared through the existing Nium File API flow when Submit KYC is explicitly requested.',
            'document' => $this->serializeProofOfAddress($approved),
        ]);
    }

    public function requestUpdate(Request $request, User $user, KycApplicantCorrectionService $service): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', Rule::in(['applicant'])],
            'fields' => ['sometimes', 'array'],
            'fields.*' => ['string', Rule::in(['legal_name', 'first_name', 'last_name', 'date_of_birth'])],
            'document_types' => ['sometimes', 'array'],
            'document_types.*' => ['string', Rule::in(['passport'])],
            'instruction' => ['required', 'string', 'max:2000'],
        ]);
        try {
            $correction = $service->requestByAdmin(
                $user, $request->user(), $validated['fields'] ?? [], $validated['document_types'] ?? [],
                $validated['instruction'], $request->ip(), $request->userAgent(),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'KYC update requested from the customer.', 'correction' => $this->serialize($correction)], 201);
    }

    public function index(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in([
                'requested', 'submitted', 'approved', 'needs_more_info', 'syncing', 'verification_pending',
                'sync_failed', 'unknown', 'synced', 'manual_kyc_evidence_approved', 'manual_kyc_compliance_review',
            ])],
        ]);
        $profile = $user->kycProfile()->firstOrFail();
        $corrections = $profile->corrections()
            ->with('reviewedBy:id,email,full_name')
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->latest('id')->get();

        return response()->json([
            'data' => $corrections->map(fn (KycProfileCorrection $correction): array => $this->serialize($correction)),
            'permissions' => $this->permissions($request),
        ]);
    }

    public function show(Request $request, User $user, KycProfileCorrection $correction): JsonResponse
    {
        $this->assertOwnedBy($user, $correction);

        return response()->json([
            'correction' => $this->serialize($correction->load('reviewedBy:id,email,full_name')),
            'permissions' => $this->permissions($request),
        ]);
    }

    public function approve(
        Request $request,
        User $user,
        KycProfileCorrection $correction,
        AdminKycCorrectionReviewService $service,
    ): JsonResponse {
        $this->assertOwnedBy($user, $correction);
        $validated = $this->reviewInput($request, false);

        try {
            $result = $service->approve(
                $user, $correction, $request->user(), (int) $validated['correction_version'],
                $validated['source_fingerprint'], $validated['review_note'] ?? null,
                $request->ip(), $request->userAgent(),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => $result['idempotent']
                ? 'KYC correction was already approved.'
                : ($result['correction']->status === 'manual_kyc_evidence_approved'
                    ? 'KYC evidence approved and ready for Manual Submit KYC.'
                    : 'KYC correction approved for the next synchronization stage.'),
            'correction' => $this->serialize($result['correction']),
            'idempotent' => $result['idempotent'],
        ]);
    }

    public function requestChanges(
        Request $request,
        User $user,
        KycProfileCorrection $correction,
        AdminKycCorrectionReviewService $service,
    ): JsonResponse {
        $this->assertOwnedBy($user, $correction);
        $validated = $this->reviewInput($request, true);

        try {
            $result = $service->requestChanges(
                $user, $correction, $request->user(), (int) $validated['correction_version'],
                $validated['source_fingerprint'], $validated['reason'],
                $request->ip(), $request->userAgent(),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => $result['idempotent'] ? 'Changes were already requested for this KYC correction.' : 'Applicant correction returned for more information.',
            'correction' => $this->serialize($result['correction']),
            'idempotent' => $result['idempotent'],
        ]);
    }

    public function sync(
        Request $request,
        User $user,
        KycProfileCorrection $correction,
        AdminKycCorrectionSyncService $service,
    ): JsonResponse {
        $this->assertOwnedBy($user, $correction);

        try {
            $synced = $service->sync($user, $correction, $request->user());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Nium Customer Update was verified.', 'correction' => $this->serialize($synced)]);
    }

    public function reconcile(
        Request $request,
        User $user,
        KycProfileCorrection $correction,
        AdminKycCorrectionSyncService $service,
    ): JsonResponse {
        $this->assertOwnedBy($user, $correction);

        try {
            $synced = $service->reconcile($user, $correction, $request->user());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Nium Customer Update reconciliation was verified.', 'correction' => $this->serialize($synced)]);
    }

    public function resolveFailure(
        Request $request,
        User $user,
        KycProfileCorrection $correction,
        AdminKycCorrectionSyncService $service,
    ): JsonResponse {
        $this->assertOwnedBy($user, $correction);
        $validated = $request->validate([
            'request_id' => ['required', 'uuid'],
            'request_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $resolved = $service->resolveDeterministicFailure(
            $user, $correction, $request->user(), $validated['request_id'],
            $validated['request_fingerprint'], $validated['reason'],
        );

        return response()->json([
            'message' => 'The deterministic Nium failure was closed and returned for a new correction.',
            'correction' => $this->serialize($resolved),
        ]);
    }

    private function reviewInput(Request $request, bool $requireReason): array
    {
        return $request->validate([
            'correction_version' => ['required', 'integer', 'min:1'],
            'source_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'review_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'reason' => [$requireReason ? 'required' : 'sometimes', 'string', 'max:1000'],
        ]);
    }

    private function assertOwnedBy(User $user, KycProfileCorrection $correction): void
    {
        abort_unless($user->kycProfile()->whereKey($correction->kyc_profile_id)->exists(), 404);
    }

    private function permissions(Request $request): array
    {
        return [
            'approve' => $request->user()?->hasPermission('kyc.approve') === true,
            'request_changes' => $request->user()?->hasPermission('kyc.reject') === true,
            'sync' => $request->user()?->hasPermission('kyc.approve') === true,
        ];
    }

    private function serialize(KycProfileCorrection $correction): array
    {
        $correction->loadMissing(['providerAccount', 'syncAttempts', 'manualKycContinuation']);
        $syncService = app(AdminKycCorrectionSyncService::class);
        $capability = $syncService->capability($correction);
        $approvalCapability = app(KycCorrectionSyncPolicy::class)->evaluate($correction->providerAccount);
        $manualReviewEligible = app(KycManualComplianceReviewService::class)->eligible($correction);
        $attempt = $correction->syncAttempts->sortByDesc('id')->first();
        $proofOfAddressDocuments = KycDocument::query()
            ->where('kyc_profile_id', $correction->kyc_profile_id)
            ->where('kyc_related_person_id', $correction->applicant_related_person_id)
            ->get()->filter(fn (KycDocument $document): bool =>
                strtolower(trim((string) data_get($document->metadata, 'nium_document_type', $document->type))) === 'proof_of_address'
                && (int) data_get($document->metadata, 'source_correction_id') === (int) $correction->id)
            ->map(fn (KycDocument $document): array => $this->serializeProofOfAddress($document))->values()->all();

        return [
            'id' => $correction->id,
            'kyc_profile_id' => $correction->kyc_profile_id,
            'applicant_related_person_id' => $correction->applicant_related_person_id,
            'correction_version' => $correction->correction_version,
            'source_fingerprint' => $correction->source_fingerprint,
            'status' => $correction->status,
            'before_values' => $correction->before_snapshot,
            'proposed_values' => $correction->proposed_values,
            'requested_fields' => $correction->requested_fields ?? [],
            'requested_document_types' => $correction->requested_document_types ?? [],
            'proposed_documents' => collect($correction->proposed_documents ?? [])->map(fn ($document) => [
                'type' => $document['type'] ?? null, 'file_url' => $document['file_url'] ?? null,
                'file_hash' => $document['file_hash'] ?? null, 'original_name' => $document['original_name'] ?? null,
                'mime_type' => $document['mime_type'] ?? null,
                'document_number' => $document['document_number'] ?? null,
                'issuing_country_code' => $document['issuing_country_code'] ?? null,
                'expires_at' => $document['expires_at'] ?? null,
            ])->all(),
            'request_instruction' => $correction->request_instruction,
            'request_origin' => $correction->request_origin,
            'customer_hash_fingerprint' => $correction->customer_hash_fingerprint,
            'applicant_reference_fingerprint' => $correction->applicant_reference_fingerprint,
            'submitted_at' => $correction->submitted_at?->toISOString(),
            'reviewed_at' => $correction->reviewed_at?->toISOString(),
            'resolved_at' => $correction->resolved_at?->toISOString(),
            'review_note' => $correction->review_note,
            'reviewed_by' => $correction->reviewedBy?->only(['id', 'email', 'full_name']),
            'manual_kyc_continuation' => $this->serializeContinuation($correction),
            'proof_of_address_documents' => $proofOfAddressDocuments,
            'sync' => [
                ...$capability,
                'status' => $attempt?->status,
                'request_id' => $attempt?->request_id,
                'request_fingerprint' => $attempt?->request_fingerprint,
                'http_status' => $attempt?->http_status,
                'failure_code' => $attempt?->failure_code,
                'verification' => $attempt?->verification_result,
                'started_at' => $attempt?->started_at?->toISOString(),
                'verified_at' => $attempt?->verified_at?->toISOString(),
                'can_reconcile' => in_array($attempt?->status, ['unknown', 'verification_pending'], true),
                'can_resolve_failure' => $attempt?->status === 'sync_failed'
                    && $attempt?->http_status !== null
                    && $attempt->http_status >= 400
                    && $attempt->http_status < 500,
            ],
            'review_capability' => [
                'can_approve' => $correction->status === 'submitted'
                    && ($manualReviewEligible || $approvalCapability['allowed']),
                'reason' => $manualReviewEligible || $approvalCapability['allowed'] ? null : $approvalCapability['reason'],
            ],
        ];
    }

    private function serializeProofOfAddress(KycDocument $document): array
    {
        return [
            'id' => $document->id,
            'type' => 'proof_of_address',
            'status' => $document->status,
            'file_url' => $document->file_url,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'file_size' => $document->file_size,
            'issued_at' => $document->issued_at?->format('Y-m-d'),
            'verified_residence_address' => data_get($document->metadata, 'verified_residence_address'),
            'nium_file_state' => data_get($document->metadata, 'nium_file_state'),
            'has_nium_file_id' => filled(data_get($document->metadata, 'nium_file_id')),
            'approved_at' => data_get($document->metadata, 'approved_at'),
        ];
    }

    private function serializeContinuation(KycProfileCorrection $correction): ?array
    {
        $continuation = $correction->manualKycContinuation;
        if ($continuation === null) {
            return null;
        }

        return [
            'state' => $continuation->state,
            'poll_attempts' => $continuation->poll_attempts,
            'next_attempt_at' => $continuation->next_attempt_at?->toISOString(),
            'deadline_at' => $continuation->deadline_at?->toISOString(),
            'submit_started_at' => $continuation->submit_started_at?->toISOString(),
            'submit_claim_expires_at' => $continuation->submit_claim_expires_at?->toISOString(),
            'completed_at' => $continuation->completed_at?->toISOString(),
            'hold_code' => $continuation->hold_code,
        ];
    }
}
