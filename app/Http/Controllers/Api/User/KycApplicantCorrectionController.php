<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Models\KycProfileCorrection;
use App\Models\User;
use App\Services\Kyc\AdminKycProofOfAddressService;
use App\Services\Kyc\KycApplicantCorrectionService;
use App\Services\Kyc\KycCorrectionSyncPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use RuntimeException;

class KycApplicantCorrectionController extends Controller
{
    public function uploadProofOfAddress(Request $request, User $user, KycProfileCorrection $correction, AdminKycProofOfAddressService $service): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:20480'],
            'correction_version' => ['required', 'integer', 'min:1'],
            'issued_at' => ['nullable', 'date', 'before_or_equal:today'],
            'address_line1' => ['nullable', 'string', 'max:255'], 'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'], 'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:40'], 'country_code' => ['nullable', 'string', 'size:2'],
        ]);
        abort_unless((int) $correction->kycProfile?->user_id === (int) $user->id, 404);
        if ((int) $correction->correction_version !== (int) $validated['correction_version']) {
            throw ValidationException::withMessages(['correction_version' => 'This Proof of Address request is stale. Refresh before uploading.']);
        }
        $address = collect(['address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country_code'])
            ->mapWithKeys(fn (string $key): array => [$key => filled($validated[$key] ?? null)
                ? ($key === 'country_code' ? strtoupper(trim((string) $validated[$key])) : trim((string) $validated[$key])) : null])
            ->filter()->all();
        try {
            $document = $service->uploadByCustomer($user, $correction, $request->file('file'), $validated['issued_at'] ?? null, $address, $request->ip(), $request->userAgent());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['message' => 'Proof of Address uploaded for Admin review.', 'document' => $this->serializeProofOfAddress($document)], 201);
    }

    public function show(User $user, KycApplicantCorrectionService $service): JsonResponse
    {
        return response()->json($this->serializeState($service->state($user)));
    }

    public function store(Request $request, User $user, KycApplicantCorrectionService $service): JsonResponse
    {
        $allowedFields = ['correction_id', 'correction_version', 'source_fingerprint', 'legal_name', 'first_name', 'last_name', 'date_of_birth', 'documents'];
        $unexpectedFields = array_values(array_diff(array_keys($request->all()), $allowedFields));
        if ($unexpectedFields !== []) {
            throw ValidationException::withMessages([
                'correction' => 'Only applicant legal name, passport first name, passport last name, and date of birth may be corrected.',
            ]);
        }

        $validated = $request->validate([
            'correction_version' => ['required', 'integer', 'min:1'],
            'source_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'correction_id' => ['sometimes', 'integer', 'min:1'],
            'legal_name' => ['sometimes', 'string', 'max:255'],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'date_of_birth' => ['sometimes', 'date_format:Y-m-d', 'before:today'],
            'documents' => ['sometimes', 'array', 'max:5'],
            'documents.*.type' => ['required_with:documents', Rule::in(['passport'])],
            'documents.*.file_url' => ['required_with:documents', 'url', 'max:2048'],
            'documents.*.storage_disk' => ['required_with:documents', 'string', 'max:50'],
            'documents.*.file_path' => ['required_with:documents', 'string', 'max:2048'],
            'documents.*.file_hash' => ['required_with:documents', 'string', 'size:64'],
            'documents.*.original_name' => ['nullable', 'string', 'max:255'],
            'documents.*.mime_type' => ['nullable', 'string', 'max:100'],
            'documents.*.file_size' => ['nullable', 'integer', 'min:1', 'max:20971520'],
            'documents.*.side' => ['nullable', 'string', 'max:20'],
            'documents.*.document_number' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9]+$/'],
            'documents.*.issuing_country_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'documents.*.issued_at' => ['nullable', 'date'],
            'documents.*.expires_at' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'documents.*.metadata' => ['sometimes', 'array'],
        ]);

        foreach (['legal_name', 'first_name', 'last_name'] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = preg_replace('/\s+/', ' ', trim($validated[$field]));
            }
        }

        try {
            if (isset($validated['correction_id'])) {
                $correction = KycProfileCorrection::query()->findOrFail($validated['correction_id']);
                $correction = $service->submitRequested(
                    $user, $correction,
                    array_intersect_key($validated, array_flip(['legal_name', 'first_name', 'last_name', 'date_of_birth'])),
                    $validated['documents'] ?? [], (int) $validated['correction_version'],
                    $validated['source_fingerprint'], $request->ip(), $request->userAgent(),
                );
            } else {
                foreach (['legal_name', 'first_name', 'last_name', 'date_of_birth'] as $field) {
                    if (! array_key_exists($field, $validated)) {
                        throw ValidationException::withMessages([$field => 'This field is required.']);
                    }
                }
                $correction = $service->submit($user, $validated, (int) $validated['correction_version'], $validated['source_fingerprint'], $request->ip(), $request->userAgent());
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'KYC applicant correction submitted for review.',
            'correction' => $this->serializeCorrection($correction),
        ], 201);
    }

    private function serializeState(array $state): array
    {
        if (($state['correction'] ?? null) instanceof KycProfileCorrection) {
            $state['correction'] = $this->serializeCorrection($state['correction']);
        }
        if (($state['latest_resolved_correction'] ?? null) instanceof KycProfileCorrection) {
            $state['latest_resolved_correction'] = $this->serializeCorrection($state['latest_resolved_correction']);
        }

        return $state;
    }

    private function serializeCorrection(KycProfileCorrection $correction): array
    {
        $correction->loadMissing(['providerAccount', 'manualKycContinuation']);
        $providerUpdate = app(KycCorrectionSyncPolicy::class)->evaluate($correction->providerAccount);

        $proofOfAddressDocuments = KycDocument::query()->where('kyc_profile_id', $correction->kyc_profile_id)
            ->where('kyc_related_person_id', $correction->applicant_related_person_id)->get()
            ->filter(fn (KycDocument $document): bool => (int) data_get($document->metadata, 'source_correction_id') === (int) $correction->id
                && strtolower((string) data_get($document->metadata, 'nium_document_type', $document->type)) === 'proof_of_address')
            ->map(fn (KycDocument $document): array => $this->serializeProofOfAddress($document))->values()->all();

        return [
            'id' => $correction->id,
            'correction_version' => $correction->correction_version,
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
            'proof_of_address_documents' => $proofOfAddressDocuments,
            'submitted_at' => $correction->submitted_at?->toISOString(),
            'reviewed_at' => $correction->reviewed_at?->toISOString(),
            'review_note' => $correction->review_note,
            'provider_update' => $providerUpdate,
            'manual_kyc_continuation' => $correction->manualKycContinuation === null ? null : [
                'state' => $correction->manualKycContinuation->state,
                'next_attempt_at' => $correction->manualKycContinuation->next_attempt_at?->toISOString(),
                'completed_at' => $correction->manualKycContinuation->completed_at?->toISOString(),
            ],
        ];
    }

    private function serializeProofOfAddress(KycDocument $document): array
    {
        return [
            'id' => $document->id, 'type' => 'proof_of_address', 'status' => $document->status,
            'file_url' => $document->file_url, 'original_name' => $document->original_name,
            'mime_type' => $document->mime_type, 'file_size' => $document->file_size,
            'issued_at' => $document->issued_at?->format('Y-m-d'),
            'customer_provided_residence_address' => data_get($document->metadata, 'customer_provided_residence_address'),
            'review_feedback' => data_get($document->metadata, 'review_feedback'),
            'upload_origin' => data_get($document->metadata, 'upload_origin'),
        ];
    }
}
