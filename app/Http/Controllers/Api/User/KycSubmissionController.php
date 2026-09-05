<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\KycProfile;
use App\Models\KycRequirement;
use App\Models\User;
use App\Services\Aml\AmlScreeningService;
use App\Services\Compliance\ComplianceEvidenceService;
use App\Services\Kyc\BusinessRegistryVerificationService;
use App\Services\Nium\NiumRegionResolver;
use App\Support\KycAuditProjection;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KycSubmissionController extends Controller
{
    private const HK_CORPORATE_BANK_ACCOUNT_FALLBACK = [
        'bankCode' => '016',
        'bankName' => 'DBS Bank (Hong Kong) Limited',
        'currency' => 'USD',
        'accountName' => 'DBS TEST COMPANY LIMITED',
        'bankCountry' => 'HK',
        'routingCodes' => [
            [
                'type' => 'SWIFT',
                'value' => 'DHBKHKHH',
            ],
        ],
        'accountNumber' => '999999999',
    ];

    private const SG_CORPORATE_BUSINESS_ADDRESS_KEYS = [
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country_code',
    ];

    public function show(User $user): JsonResponse
    {
        $user->load(
            'kycProfile.documents',
            'kycProfile.relatedPersons.documents',
            'kycProfile.requirements',
            'kycProfile.amlScreenings.matches',
            'kycProfile.reviewedBy',
        );

        return response()->json([
            'kyc_status' => $user->kyc_status,
            'kyc_profile' => $user->kycProfile,
            'kyc_submission' => $user->kycProfile,
        ]);
    }

    public function uploadDocument(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:100'],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf,mp4,mov,webm', 'max:20480'],
            'subject_type' => ['nullable', 'string', Rule::in([
                'applicant',
                'business',
                'authorized_representative',
                'beneficial_owner',
                'agent',
            ])],
            'side' => ['nullable', 'string', 'max:20'],
            'issuing_country_code' => ['nullable', 'string', 'size:2'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $file = $request->file('file');
        $disk = (string) config('services.kyc.documents_disk', 'kyc_private');
        $fileHash = hash_file('sha256', $file->getRealPath());
        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';
        $safeType = Str::slug((string) $validated['type']) ?: 'document';
        $path = sprintf(
            'kyc/%d/documents/%s-%s.%s',
            $user->id,
            $fileHash,
            $safeType,
            $extension,
        );

        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));

        $document = array_filter([
            'type' => $validated['type'],
            'file_url' => route('kyc-documents.show', [
                'user' => $user,
                'artifactHash' => $fileHash,
            ]),
            'storage_disk' => $disk,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'file_hash' => $fileHash,
            'side' => $validated['side'] ?? null,
            'document_number' => $validated['document_number'] ?? null,
            'issuing_country_code' => isset($validated['issuing_country_code'])
                ? strtoupper((string) $validated['issuing_country_code'])
                : null,
            'issued_at' => $validated['issued_at'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'metadata' => array_filter([
                ...($validated['metadata'] ?? []),
                'subject_type' => $validated['subject_type'] ?? null,
                'uploaded_at' => now()->toISOString(),
            ], static fn ($value) => $value !== null && $value !== ''),
        ], static fn ($value) => $value !== null && $value !== '');

        return response()->json([
            'message' => 'KYC document uploaded successfully.',
            'document' => $document,
        ], 201);
    }

    public function showDocument(Request $request, User $user, string $artifactHash): StreamedResponse
    {
        $authenticatedUser = $request->user();

        if (! $authenticatedUser instanceof User) {
            abort(401);
        }

        $authenticatedUser->loadMissing('roles');

        if ($authenticatedUser->id !== $user->id && ! $authenticatedUser->isAdmin()) {
            abort(403);
        }

        $document = $user->kycProfile?->documents()
            ->where('file_hash', $artifactHash)
            ->first();
        $disk = (string) ($document?->storage_disk ?: config('services.kyc.documents_disk', 'kyc_private'));
        $path = (string) ($document?->file_path ?: $this->uploadedDocumentPath($disk, $user, $artifactHash));

        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        return Storage::disk($disk)->response(
            $path,
            $document?->original_name ?: basename($path),
            [
                'Content-Type' => $document?->mime_type ?: (Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream'),
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function submit(
        Request $request,
        User $user,
        AmlScreeningService $amlScreeningService,
        BusinessRegistryVerificationService $businessRegistryVerificationService,
        NiumRegionResolver $regionResolver,
    ): JsonResponse {
        $this->validateNiumRegionInput($request, $regionResolver);
        $this->applyHkCorporateBankAccountFallback($request);
        $validated = $request->validate($this->rules($request, $user, $regionResolver));

        if ($request->exists('metadata')) {
            $validated['metadata'] = (array) $request->input('metadata');

            if (
                array_key_exists('nium_region', $validated['metadata'])
                && $validated['metadata']['nium_region'] !== null
            ) {
                $validated['metadata']['nium_region'] = $regionResolver->resolve(
                    $validated['metadata']['nium_region'],
                    null,
                    null,
                    null,
                );
            }
        } else {
            $existingMetadata = $user->kycProfile()->first()?->metadata;

            if ($existingMetadata !== null) {
                $validated['metadata'] = (array) $existingMetadata;
            }
        }

        $validated = $this->enrichHkCorporateFullContract($request, $validated);

        $validated = $this->attachBusinessRegistryVerification($validated, $businessRegistryVerificationService);

        $kycProfile = DB::transaction(function () use ($user, $validated, $amlScreeningService): KycProfile {
            $existingDocuments = $user->kycProfile?->documents()->get() ?? collect();
            $payload = Arr::only($validated, $this->profileFields());
            $payload['status'] = 'submitted';
            $payload['submitted_at'] = now();
            $payload['reviewed_by_user_id'] = null;
            $payload['reviewed_at'] = null;
            $payload['review_note'] = null;
            $payload['rejection_reason'] = null;

            $kycProfile = $user->kycProfile()->updateOrCreate(
                ['user_id' => $user->id],
                $payload,
            );

            $kycProfile->documents()->delete();
            $kycProfile->relatedPersons()->delete();
            $kycProfile->requirements()->delete();

            foreach ($validated['documents'] ?? [] as $document) {
                $document = $this->preserveNiumDocumentMetadata($document, $existingDocuments, null);
                $kycProfile->documents()->create([
                    ...Arr::only($document, $this->documentFields()),
                    'status' => 'submitted',
                ]);
            }

            foreach ($validated['related_persons'] ?? [] as $relatedPerson) {
                $relatedPersonDocuments = $relatedPerson['documents'] ?? [];
                $relatedPerson = $this->normalizeNiumCorporateRelatedPerson($relatedPerson);
                $createdRelatedPerson = $kycProfile->relatedPersons()->create([
                    ...Arr::only($relatedPerson, $this->relatedPersonFields()),
                    'status' => 'submitted',
                ]);

                foreach ($relatedPersonDocuments as $document) {
                    $document = $this->preserveNiumDocumentMetadata(
                        $document,
                        $existingDocuments,
                        (string) $relatedPerson['relationship_type'],
                    );
                    $kycProfile->documents()->create([
                        ...Arr::only($document, $this->documentFields()),
                        'kyc_related_person_id' => $createdRelatedPerson->id,
                        'status' => 'submitted',
                    ]);
                }
            }

            foreach ($this->buildRequirements($validated) as $requirement) {
                $kycProfile->requirements()->create($requirement);
            }

            $amlScreeningService->prepareProfile($kycProfile->fresh(['user', 'relatedPersons']));

            $user->update([
                'status' => 'pending',
                'kyc_status' => 'pending',
            ]);

            return $kycProfile->fresh(['documents', 'relatedPersons.documents', 'requirements', 'amlScreenings.matches', 'reviewedBy']);
        });

        return response()->json([
            'message' => 'KYC profile submitted and is pending internal review.',
            'kyc_status' => $user->fresh()->kyc_status,
            'kyc_profile' => $kycProfile,
            'kyc_submission' => $kycProfile,
        ], 202);
    }

    private function normalizeNiumCorporateRelatedPerson(array $person): array
    {
        $relationship = strtolower((string) ($person['relationship_type'] ?? ''));

        $metadata = (array) ($person['metadata'] ?? []);

        if ($relationship === 'authorized_representative') {
            $metadata['positions'] = [
                'REPRESENTATIVE',
                'DIRECTOR',
                'UBO',
                'SHAREHOLDER',
            ];
        }

        if ($relationship === 'beneficial_owner') {
            $metadata['positions'] = [
                'DIRECTOR',
                'UBO',
                'SHAREHOLDER',
            ];
        }

        $person['metadata'] = $metadata;

        return $person;
    }

    public function resubmitRequirement(
        Request $request,
        User $user,
        KycRequirement $requirement,
        ComplianceEvidenceService $complianceEvidenceService,
    ): JsonResponse {
        /** @var KycProfile $kycProfile */
        $kycProfile = $user->kycProfile()
            ->with(['documents', 'relatedPersons.documents', 'requirements', 'amlScreenings.matches', 'reviewedBy'])
            ->firstOrFail();

        abort_if($requirement->kyc_profile_id !== $kycProfile->id, 404);

        $validated = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'profile' => ['sometimes', 'array'],
            'profile.applicant_type' => ['sometimes', 'string', Rule::in(['individual', 'business'])],
            'profile.legal_name' => ['sometimes', 'string', 'max:255'],
            'profile.date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'profile.nationality_country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'profile.residence_country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'profile.business_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.business_registration_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.tax_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.registered_country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'profile.address_line1' => ['sometimes', 'string', 'max:255'],
            'profile.address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.city' => ['sometimes', 'string', 'max:100'],
            'profile.state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.postal_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'profile.country_code' => ['sometimes', 'string', 'size:2'],
            'profile.metadata' => ['sometimes', 'array'],
            'related_person' => ['sometimes', 'array'],
            'related_person.relationship_type' => ['sometimes', 'string', 'max:50'],
            'related_person.legal_name' => ['sometimes', 'string', 'max:255'],
            'related_person.date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'related_person.nationality_country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'related_person.residence_country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'related_person.ownership_percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'related_person.address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'related_person.address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'related_person.city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'related_person.state' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],
            'related_person.postal_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'related_person.country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'related_person.metadata' => ['sometimes', 'array'],
            'document' => ['sometimes', 'array'],
            'document.type' => ['required_with:document', 'string', 'max:50'],
            'document.file_url' => ['required_with:document', 'url', 'max:2048'],
            'document.storage_disk' => ['nullable', 'string', 'max:50'],
            'document.file_path' => ['nullable', 'string', 'max:2048'],
            'document.original_name' => ['nullable', 'string', 'max:255'],
            'document.mime_type' => ['nullable', 'string', 'max:100'],
            'document.file_size' => ['nullable', 'integer', 'min:0'],
            'document.file_hash' => ['nullable', 'string', 'max:255'],
            'document.side' => ['nullable', 'string', 'max:20'],
            'document.document_number' => ['nullable', 'string', 'max:100'],
            'document.issuing_country_code' => ['nullable', 'string', 'size:2'],
            'document.issued_at' => ['nullable', 'date'],
            'document.expires_at' => ['nullable', 'date', 'after:today'],
            'document.metadata' => ['sometimes', 'array'],
            'metadata' => ['sometimes', 'array'],
        ]);

        if (
            ! array_key_exists('profile', $validated)
            && ! array_key_exists('related_person', $validated)
            && ! array_key_exists('document', $validated)
        ) {
            throw ValidationException::withMessages([
                'requirement' => 'Submit updated information or a replacement document for this requirement.',
            ]);
        }

        $kycProfile = DB::transaction(function () use ($request, $user, $kycProfile, $requirement, $validated, $complianceEvidenceService): KycProfile {
            $oldData = $kycProfile->toArray();
            $profilePayload = Arr::only($validated['profile'] ?? [], $this->profileFields());
            $relatedPersonPayload = Arr::only($validated['related_person'] ?? [], $this->relatedPersonFields());
            $documentPayload = Arr::only($validated['document'] ?? [], $this->documentFields());
            $resubmittedDocument = null;
            $previousDocument = null;

            if ($profilePayload !== []) {
                $kycProfile->update($profilePayload);
            }

            if (($requirement->subject_type === 'related_person') && $requirement->subject_id && $relatedPersonPayload !== []) {
                $kycProfile->relatedPersons()
                    ->whereKey($requirement->subject_id)
                    ->update([
                        ...$relatedPersonPayload,
                        'status' => 'submitted',
                    ]);
            }

            if ($documentPayload !== []) {
                if ($requirement->subject_type === 'document' && $requirement->subject_id) {
                    $previousDocument = $kycProfile->documents()->whereKey($requirement->subject_id)->first();
                }

                $metadata = [
                    ...($documentPayload['metadata'] ?? []),
                    'resubmission_requirement_id' => $requirement->id,
                    'resubmission_requirement_key' => $requirement->key,
                    'previous_document_id' => $previousDocument?->id,
                    'resubmitted_at' => now()->toISOString(),
                ];

                $resubmittedDocument = $kycProfile->documents()->create([
                    ...$documentPayload,
                    'kyc_related_person_id' => $previousDocument?->kyc_related_person_id
                        ?: ($requirement->metadata['related_person_id'] ?? null),
                    'status' => 'submitted',
                    'metadata' => array_filter($metadata, static fn ($value) => $value !== null && $value !== ''),
                ]);
            }

            $requirementMetadata = $requirement->metadata ?? [];
            $requirementMetadata = [
                ...$requirementMetadata,
                ...($validated['metadata'] ?? []),
                'resubmitted_at' => now()->toISOString(),
                'resubmission_note' => $validated['note'] ?? null,
                'resubmission_count' => ((int) ($requirementMetadata['resubmission_count'] ?? 0)) + 1,
                'resubmitted_profile_fields' => array_keys($profilePayload),
                'resubmitted_related_person_fields' => array_keys($relatedPersonPayload),
                'resubmitted_document_id' => $resubmittedDocument?->id,
            ];

            $requirement->update([
                'status' => 'submitted',
                'metadata' => array_filter($requirementMetadata, static fn ($value) => $value !== null && $value !== ''),
            ]);

            $hasOpenRequirements = $kycProfile->requirements()
                ->where('id', '!=', $requirement->id)
                ->whereIn('status', ['required', 'needs_more_info', 'rejected'])
                ->exists();

            $kycProfile->update([
                'status' => $hasOpenRequirements ? 'needs_more_info' : 'submitted',
                'submitted_at' => $hasOpenRequirements ? $kycProfile->submitted_at : now(),
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
            ]);

            $user->update([
                'status' => 'pending',
                'kyc_status' => $hasOpenRequirements ? 'needs_more_info' : 'pending',
            ]);

            $complianceEvidenceService->invalidateNiumSubmission(
                profile: $kycProfile->fresh(),
                reason: 'kyc_requirement_resubmitted',
                actorUserId: $user->id,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );

            AuditLog::query()->create([
                'user_id' => $user->id,
                'action' => 'kyc.requirement_resubmitted',
                'entity_type' => 'kyc_requirement',
                'entity_id' => (string) $requirement->id,
                'old_data' => null,
                'new_data' => KycAuditProjection::profile(
                    $kycProfile->fresh(['documents', 'relatedPersons', 'requirements']),
                    is_array($oldData) ? ($oldData['status'] ?? null) : null,
                    array_values(array_filter([
                        ...array_map(fn (string $field): string => 'profile.'.$field, array_keys($profilePayload)),
                        ...array_map(fn (string $field): string => 'related_person.'.$field, array_keys($relatedPersonPayload)),
                        'requirements.'.$requirement->id.'.status',
                        $resubmittedDocument ? 'documents.'.$resubmittedDocument->id : null,
                    ])),
                ),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ]);

            return $kycProfile->fresh(['documents', 'relatedPersons.documents', 'requirements', 'amlScreenings.matches', 'reviewedBy']);
        });

        return response()->json([
            'message' => 'KYC requirement resubmitted.',
            'kyc_status' => $user->fresh()->kyc_status,
            'kyc_profile' => $kycProfile,
            'kyc_submission' => $kycProfile,
        ], 202);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attachBusinessRegistryVerification(
        array $validated,
        BusinessRegistryVerificationService $businessRegistryVerificationService,
    ): array {
        if (($validated['applicant_type'] ?? null) !== 'business') {
            return $validated;
        }

        $verification = $businessRegistryVerificationService->verify(
            countryCode: (string) ($validated['registered_country_code'] ?? $validated['country_code'] ?? ''),
            businessRegistrationNumber: $validated['business_registration_number'] ?? null,
            taxId: $validated['tax_id'] ?? null,
            businessName: $validated['business_name'] ?? null,
        );

        if (($verification['status'] ?? null) === 'invalid') {
            throw ValidationException::withMessages([
                'business_registration_number' => $verification['message'] ?? 'Business registry verification failed.',
            ]);
        }

        $validated['metadata'] = [
            ...($validated['metadata'] ?? []),
            'business_registry_verification' => $verification,
        ];

        return $validated;
    }

    private function uploadedDocumentPath(string $disk, User $user, string $artifactHash): ?string
    {
        foreach (Storage::disk($disk)->files("kyc/{$user->id}/documents") as $path) {
            if (str_starts_with(basename($path), $artifactHash.'-')) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(
        Request $request,
        User $user,
        NiumRegionResolver $regionResolver,
    ): array {
        $submittedMetadata = $request->input('metadata');
        $effectiveMetadata = $request->exists('metadata')
            ? (is_array($submittedMetadata) ? $submittedMetadata : [])
            : (array) ($user->kycProfile()->first()?->metadata ?? []);
        $requestedRegion = $regionResolver->resolveForValidation(
            $effectiveMetadata['nium_region'] ?? null,
            $request->input('registered_country_code'),
            $request->input('residence_country_code'),
            $request->input('country_code'),
        );
        $isSgCorporate = $request->input('applicant_type') === 'business'
            && $requestedRegion === 'SG';
        $isHkCorporate = $request->input('applicant_type') === 'business'
            && $requestedRegion === 'HK';
        $isHkCorporateFull = $isHkCorporate
            && strtolower((string) $request->input('metadata.nium_kyc_type')) === 'full';
        $relationship = $request->input(
            'metadata.nium_v5_fields.addresses.isBusinessAddressSameAsRegisteredAddress',
        );
        $businessAddressRule = match ($relationship) {
            true => ['sometimes', $this->sgCorporateBusinessAddressRule(true)],
            false => ['required', $this->sgCorporateBusinessAddressRule(false)],
            default => ['sometimes'],
        };
        $businessAddressFieldRule = static fn (array $rules): array => $relationship === false
            ? ['required', ...$rules]
            : ['sometimes', 'nullable', ...$rules];

        return [
            'applicant_type' => ['required', 'string', Rule::in(['individual', 'business'])],
            'legal_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required_if:applicant_type,individual', 'nullable', 'date', 'before:today'],
            'nationality_country_code' => ['nullable', 'string', 'size:2'],
            'residence_country_code' => ['nullable', 'string', 'size:2'],
            'business_name' => ['required_if:applicant_type,business', 'nullable', 'string', 'max:255'],
            'business_registration_number' => $isHkCorporate
                ? ['required', 'string', 'regex:/^\d{8}$/']
                : ['nullable', 'string', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'registered_country_code' => ['required_if:applicant_type,business', 'nullable', 'string', 'size:2'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'country_code' => ['required', 'string', 'size:2'],
            'documents' => ['sometimes', 'array'],
            'documents.*.type' => ['required_with:documents', 'string', 'max:50'],
            'documents.*.file_url' => ['required_with:documents', 'url', 'max:2048'],
            'documents.*.storage_disk' => ['nullable', 'string', 'max:50'],
            'documents.*.file_path' => ['nullable', 'string', 'max:2048'],
            'documents.*.original_name' => ['nullable', 'string', 'max:255'],
            'documents.*.mime_type' => ['nullable', 'string', 'max:100'],
            'documents.*.file_size' => ['nullable', 'integer', 'min:0'],
            'documents.*.file_hash' => ['nullable', 'string', 'max:255'],
            'documents.*.side' => ['nullable', 'string', 'max:20'],
            'documents.*.document_number' => ['nullable', 'string', 'max:100'],
            'documents.*.issuing_country_code' => ['nullable', 'string', 'size:2'],
            'documents.*.issued_at' => ['nullable', 'date'],
            'documents.*.expires_at' => ['nullable', 'date', 'after:today'],
            'documents.*.metadata' => ['sometimes', 'array'],
            'related_persons' => ['sometimes', 'array'],
            'related_persons.*.relationship_type' => ['required_with:related_persons', 'string', 'max:50'],
            'related_persons.*.legal_name' => ['required_with:related_persons', 'string', 'max:255'],
            'related_persons.*.date_of_birth' => ['nullable', 'date', 'before:today'],
            'related_persons.*.nationality_country_code' => ['nullable', 'string', 'size:2'],
            'related_persons.*.residence_country_code' => ['nullable', 'string', 'size:2'],
            'related_persons.*.ownership_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'related_persons.*.address_line1' => ['nullable', 'string', 'max:255'],
            'related_persons.*.address_line2' => ['nullable', 'string', 'max:255'],
            'related_persons.*.city' => ['nullable', 'string', 'max:100'],
            'related_persons.*.state' => [
                'nullable',
                'string',
                'max:100',
            ],
            'related_persons.*.postal_code' => ['nullable', 'string', 'max:30'],
            'related_persons.*.country_code' => ['nullable', 'string', 'size:2'],
            'related_persons.*.metadata' => ['sometimes', 'array'],
            'related_persons.*.documents' => ['sometimes', 'array'],
            'related_persons.*.documents.*.type' => ['required_with:related_persons.*.documents', 'string', 'max:50'],
            'related_persons.*.documents.*.file_url' => ['required_with:related_persons.*.documents', 'url', 'max:2048'],
            'related_persons.*.documents.*.storage_disk' => ['nullable', 'string', 'max:50'],
            'related_persons.*.documents.*.file_path' => ['nullable', 'string', 'max:2048'],
            'related_persons.*.documents.*.original_name' => ['nullable', 'string', 'max:255'],
            'related_persons.*.documents.*.mime_type' => ['nullable', 'string', 'max:100'],
            'related_persons.*.documents.*.file_size' => ['nullable', 'integer', 'min:0'],
            'related_persons.*.documents.*.file_hash' => ['nullable', 'string', 'max:255'],
            'related_persons.*.documents.*.side' => ['nullable', 'string', 'max:20'],
            'related_persons.*.documents.*.document_number' => ['nullable', 'string', 'max:100'],
            'related_persons.*.documents.*.issuing_country_code' => ['nullable', 'string', 'size:2'],
            'related_persons.*.documents.*.issued_at' => ['nullable', 'date'],
            'related_persons.*.documents.*.expires_at' => ['nullable', 'date', 'after:today'],
            'related_persons.*.documents.*.metadata' => ['sometimes', 'array'],
            'metadata' => ['sometimes', 'array'],
            ...($isHkCorporate ? [
                'metadata.nium_region' => ['required', 'in:HK'],
                'metadata.nium_kyc_type' => ['required', 'in:full'],
                'documents.*.document_number' => [
                    'nullable',
                    'string',
                    'max:100',
                    static function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                        preg_match('/^documents\.(\d+)\.document_number$/', $attribute, $matches);
                        $type = $request->input('documents.'.($matches[1] ?? '').'.type');

                        if (in_array($type, ['business_registration', 'certificate_of_incorporation'], true)) {
                            if (! is_string($value) || trim($value) === '') {
                                $fail('The business registration document identification number is required.');
                            } elseif ($value !== $request->input('business_registration_number')) {
                                $fail('The business registration document identification number must match business_registration_number.');
                            }
                        }
                    },
                ],
            ] : []),
            ...($isHkCorporateFull ? [
                'metadata.nium_v5_fields' => ['required', 'array'],
                'metadata.nium_v5_fields.tradeName' => ['required', 'string', 'max:255'],
                'metadata.nium_v5_fields.addresses' => ['required', 'array'],
                'metadata.nium_v5_fields.addresses.isBusinessAddressSameAsRegisteredAddress' => ['required', 'boolean:strict'],
                'metadata.nium_v5_fields.applicantDeclaration' => ['required', 'accepted'],
                'metadata.nium_v5_fields.applicantDeclarationTimeStamp' => ['required', 'date_format:Y-m-d H:i:s'],
                'metadata.nium_v5_fields.isMultiLayeredCompany' => ['required', 'boolean:strict'],
                'metadata.nium_v5_fields.bankAccountDetails' => ['required', 'array'],
                'metadata.nium_v5_fields.bankAccountDetails.accountName' => ['required', 'string'],
                'metadata.nium_v5_fields.bankAccountDetails.accountNumber' => ['required', 'string'],
                'metadata.nium_v5_fields.bankAccountDetails.bankCountry' => ['required', 'string', 'size:2'],
                'metadata.nium_v5_fields.bankAccountDetails.bankName' => ['required', 'string'],
                'metadata.nium_v5_fields.bankAccountDetails.currency' => ['required', 'string', 'size:3'],
                'metadata.nium_v5_fields.bankAccountDetails.routingCodes' => ['required', 'array', 'min:1'],
                'metadata.nium_v5_fields.bankAccountDetails.routingCodes.*.type' => ['required', 'string'],
                'metadata.nium_v5_fields.bankAccountDetails.routingCodes.*.value' => ['required', 'string'],
                'metadata.nium_v5_fields.deviceDescriptor' => ['required', 'string', 'max:255'],
                'metadata.nium_v5_fields.deviceDetails' => ['prohibited'],
                'metadata.nium_v5_fields.natureOfBusiness.industryCodes' => ['required', 'array', 'min:1'],
                'metadata.nium_v5_fields.natureOfBusiness.industryCodes.*' => ['required', 'string'],
                'metadata.nium_v5_fields.natureOfBusiness.operatingCountries' => ['required', 'array', 'min:1'],
                'metadata.nium_v5_fields.natureOfBusiness.operatingCountries.*' => ['required', 'string', 'size:2'],
                'metadata.nium_v5_fields.expectedAccountUsage' => ['required', 'array'],
                'metadata.nium_v5_fields.expectedAccountUsage.intendedUses' => ['required', 'array', 'min:1'],
                'metadata.nium_v5_fields.expectedAccountUsage.intendedUses.*' => ['required', 'string'],
                'metadata.nium_v5_fields.expectedAccountUsage.credit' => ['required', 'array'],
                'metadata.nium_v5_fields.expectedAccountUsage.debit' => ['required', 'array'],
                ...collect(['credit', 'debit'])->flatMap(fn (string $direction): array => [
                    "metadata.nium_v5_fields.expectedAccountUsage.{$direction}.averageTransactionValue" => ['required', 'string'],
                    "metadata.nium_v5_fields.expectedAccountUsage.{$direction}.monthlyTransactionVolume" => ['required', 'string'],
                    "metadata.nium_v5_fields.expectedAccountUsage.{$direction}.monthlyTransactions" => ['required', 'string'],
                    "metadata.nium_v5_fields.expectedAccountUsage.{$direction}.topTransactionCountries" => ['required', 'array', 'min:1'],
                    "metadata.nium_v5_fields.expectedAccountUsage.{$direction}.topTransactionCountries.*" => ['required', 'string', 'size:2'],
                ])->all(),
                'metadata.nium_v5_fields.sizeOfBusiness.annualTurnover' => ['required', 'string'],
                'metadata.nium_v5_fields.sizeOfBusiness.totalEmployees' => ['required', 'string'],
                'related_persons.0.metadata.positions' => ['required', 'array', 'min:1'],
                'related_persons.0.metadata.positions.*' => ['required', 'string'],
            ] : []),
            ...($isSgCorporate ? [
                'metadata.nium_v5_fields' => ['required', 'array'],
                'metadata.nium_v5_fields.addresses' => ['required', 'array', 'min:1'],
                'metadata.nium_v5_fields.addresses.isBusinessAddressSameAsRegisteredAddress' => [
                    'required',
                    'boolean:strict',
                ],
                'metadata.nium_v5_fields.addresses.businessAddress' => $businessAddressRule,
                'metadata.nium_v5_fields.addresses.businessAddress.address_line1' => $businessAddressFieldRule([
                    'string',
                    'max:255',
                ]),
                'metadata.nium_v5_fields.addresses.businessAddress.address_line2' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:255',
                ],
                'metadata.nium_v5_fields.addresses.businessAddress.city' => $businessAddressFieldRule([
                    'string',
                    'max:100',
                ]),
                'metadata.nium_v5_fields.addresses.businessAddress.state' => $businessAddressFieldRule([
                    'string',
                    'max:100',
                ]),
                'metadata.nium_v5_fields.addresses.businessAddress.postal_code' => $businessAddressFieldRule([
                    'string',
                    'max:30',
                ]),
                'metadata.nium_v5_fields.addresses.businessAddress.country_code' => $businessAddressFieldRule([
                    'string',
                    'size:2',
                    'regex:/^[A-Za-z]{2}$/',
                ]),
            ] : []),
        ];
    }

    private function validateNiumRegionInput(
        Request $request,
        NiumRegionResolver $regionResolver,
    ): void {
        [$supplied, $value] = $this->submittedNiumRegion($request);

        if (
            $supplied
            && $value !== null
            && ! $regionResolver->isSupportedExplicitRegion($value)
        ) {
            throw ValidationException::withMessages([
                'metadata.nium_region' => 'The selected Nium region is invalid.',
            ]);
        }
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function submittedNiumRegion(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);
        $metadata = is_array($decoded) ? ($decoded['metadata'] ?? null) : null;

        if (is_array($metadata) && array_key_exists('nium_region', $metadata)) {
            return [true, $metadata['nium_region']];
        }

        $metadata = $request->input('metadata');

        return is_array($metadata) && array_key_exists('nium_region', $metadata)
            ? [true, $metadata['nium_region']]
            : [false, null];
    }

    private function sgCorporateBusinessAddressRule(bool $relationship): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($relationship): void {
            if ($relationship) {
                if ($value === null || $value === []) {
                    return;
                }

                if (
                    ! is_array($value)
                    || array_is_list($value)
                    || array_diff(array_keys($value), self::SG_CORPORATE_BUSINESS_ADDRESS_KEYS) !== []
                ) {
                    $fail('The SG corporate business address conflicts with the declared address relationship.');

                    return;
                }

                if (
                    array_keys($value) === ['address_line2']
                    && (
                        $value['address_line2'] === null
                        || (
                            is_string($value['address_line2'])
                            && trim($value['address_line2']) === ''
                        )
                    )
                ) {
                    return;
                }

                $fail('The SG corporate business address conflicts with the declared address relationship.');

                return;
            }

            if (
                ! is_array($value)
                || $value === []
                || array_is_list($value)
                || array_diff(array_keys($value), self::SG_CORPORATE_BUSINESS_ADDRESS_KEYS) !== []
            ) {
                $fail('The SG corporate business address is invalid.');

                return;
            }

            foreach (['address_line1', 'city', 'state', 'postal_code', 'country_code'] as $field) {
                if (! is_string($value[$field] ?? null) || trim($value[$field]) === '') {
                    $fail('The SG corporate business address is invalid.');

                    return;
                }
            }

            if (
                array_key_exists('address_line2', $value)
                && $value['address_line2'] !== null
                && ! is_string($value['address_line2'])
            ) {
                $fail('The SG corporate business address is invalid.');

                return;
            }

            if (preg_match('/^[A-Za-z]{2}$/', $value['country_code']) !== 1) {
                $fail('The SG corporate business address is invalid.');
            }
        };
    }

    /**
     * @return list<string>
     */
    private function profileFields(): array
    {
        return [
            'applicant_type',
            'legal_name',
            'date_of_birth',
            'nationality_country_code',
            'residence_country_code',
            'business_name',
            'business_registration_number',
            'tax_id',
            'registered_country_code',
            'address_line1',
            'address_line2',
            'city',
            'state',
            'postal_code',
            'country_code',
            'metadata',
        ];
    }

    /** Server owns IP/session identity; the browser contributes only a bounded display descriptor. */
    private function enrichHkCorporateFullContract(Request $request, array $validated): array
    {
        if (($validated['applicant_type'] ?? null) !== 'business'
            || strtoupper((string) data_get($validated, 'metadata.nium_region')) !== 'HK') {
            return $validated;
        }

        $ipAddress = (string) $request->ip();
        if (filter_var(
            $ipAddress,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            throw ValidationException::withMessages(['device' => 'HK Corporate Full onboarding requires a trusted public IPv4 address.']);
        }

        $documents = collect($validated['documents'] ?? []);
        $filing = $documents->first(fn (array $document): bool => in_array(strtolower((string) ($document['type'] ?? '')), ['nar1', 'nnc1'], true));
        if (! is_array($filing)
            || empty($filing['issued_at'])
            || data_get($filing, 'metadata.is_most_recent_filing') !== true) {
            throw ValidationException::withMessages(['documents' => 'HK private companies require a dated latest NAR1 or NNC1 filing.']);
        }

        $registration = $documents->first(fn (array $document): bool => in_array(strtolower((string) ($document['type'] ?? '')), ['business_registration', 'certificate_of_incorporation'], true));
        if (! is_array($registration) || empty($registration['issued_at'])) {
            throw ValidationException::withMessages(['documents' => 'The business registration document issue date is required.']);
        }

        if (data_get($validated, 'metadata.nium_v5_fields.isMultiLayeredCompany') === true
            && ! $documents->contains(fn (array $document): bool => in_array(strtolower((string) ($document['type'] ?? '')), ['corporate_structure', 'ownership_chart'], true))) {
            throw ValidationException::withMessages(['documents' => 'A corporate structure document is required for a multilayered company.']);
        }

        $descriptor = trim((string) data_get($validated, 'metadata.nium_v5_fields.deviceDescriptor'));
        data_forget($validated, 'metadata.nium_v5_fields.deviceDescriptor');
        data_set($validated, 'metadata.nium_v5_fields.deviceDetails', [
            'ipCountryCode' => strtoupper((string) $validated['registered_country_code']),
            'deviceInfo' => $descriptor,
            'ipAddress' => $ipAddress,
            'sessionId' => (string) Str::uuid(),
        ]);

        return $validated;
    }

    private function applyHkCorporateBankAccountFallback(Request $request): void
    {
        if (
            $request->input('applicant_type') !== 'business'
            || strtoupper((string) $request->input('metadata.nium_region')) !== 'HK'
            || strtolower((string) $request->input('metadata.nium_kyc_type')) !== 'full'
        ) {
            return;
        }

        $fields = $request->input('metadata.nium_v5_fields');

        if (! is_array($fields)) {
            return;
        }

        $bankAccountDetails = $fields['bankAccountDetails'] ?? null;

        if (array_key_exists('bankAccountDetails', $fields) && ! $this->isEmptyBankAccountPlaceholder($bankAccountDetails)) {
            return;
        }

        $metadata = (array) $request->input('metadata', []);
        data_set($metadata, 'nium_v5_fields.bankAccountDetails', self::HK_CORPORATE_BANK_ACCOUNT_FALLBACK);
        $request->merge(['metadata' => $metadata]);
    }

    private function isEmptyBankAccountPlaceholder(mixed $details): bool
    {
        if (! is_array($details)) {
            return false;
        }

        $routingValues = collect($details['routingCodes'] ?? [])
            ->filter(fn (mixed $routingCode): bool => is_array($routingCode))
            ->pluck('value');

        return collect([
            $details['accountName'] ?? null,
            $details['accountNumber'] ?? null,
            $details['bankName'] ?? null,
            ...$routingValues,
        ])->every(fn (mixed $value): bool => ! is_string($value) || trim($value) === '');
    }

    private function preserveNiumDocumentMetadata(array $document, $existingDocuments, ?string $relationshipType): array
    {
        $clientMetadata = collect((array) ($document['metadata'] ?? []))
            ->reject(fn (mixed $value, mixed $key): bool => is_string($key)
                && str_starts_with(strtolower($key), 'nium_')
                && strtolower($key) !== 'nium_document_type')
            ->all();
        $document['metadata'] = $clientMetadata;
        $hash = trim((string) ($document['file_hash'] ?? ''));
        if ($hash === '') {
            return $document;
        }

        $existing = $existingDocuments->first(function ($candidate) use ($document, $hash, $relationshipType): bool {
            $candidateRelationship = $candidate->relatedPerson?->relationship_type;

            return hash_equals((string) $candidate->file_hash, $hash)
                && strtolower((string) $candidate->type) === strtolower((string) ($document['type'] ?? ''))
                && ($relationshipType === null ? $candidate->kyc_related_person_id === null : $candidateRelationship === $relationshipType);
        });

        if ($existing === null) {
            return $document;
        }

        $serverMetadata = Arr::only((array) $existing->metadata, [
            'nium_file_id',
            'nium_file_state',
            'nium_document_type',
            'nium_uploaded_at',
            'nium_available_at',
            'nium_last_checked_at',
        ]);
        $document['metadata'] = [...$clientMetadata, ...$serverMetadata];

        return $document;
    }

    /**
     * @return list<string>
     */
    private function documentFields(): array
    {
        return [
            'type',
            'file_url',
            'storage_disk',
            'file_path',
            'original_name',
            'mime_type',
            'file_size',
            'file_hash',
            'side',
            'document_number',
            'issuing_country_code',
            'issued_at',
            'expires_at',
            'metadata',
        ];
    }

    /**
     * @return list<string>
     */
    private function relatedPersonFields(): array
    {
        return [
            'relationship_type',
            'legal_name',
            'date_of_birth',
            'nationality_country_code',
            'residence_country_code',
            'ownership_percentage',
            'address_line1',
            'address_line2',
            'city',
            'state',
            'postal_code',
            'country_code',
            'metadata',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<array<string, mixed>>
     */
    private function buildRequirements(array $validated): array
    {
        $profileDocumentTypes = collect($validated['documents'] ?? [])
            ->pluck('type')
            ->map(fn (string $type) => strtolower($type));
        $relatedDocumentTypes = collect($validated['related_persons'] ?? [])
            ->flatMap(fn (array $person) => collect($person['documents'] ?? [])->pluck('type'))
            ->map(fn (string $type) => strtolower($type));
        $documentTypes = $profileDocumentTypes->merge($relatedDocumentTypes);
        $relatedPersons = collect($validated['related_persons'] ?? []);
        $relationshipTypes = collect($validated['related_persons'] ?? [])
            ->pluck('relationship_type')
            ->map(fn (string $type) => strtolower($type));
        $isBusiness = $validated['applicant_type'] === 'business';
        $hasRelatedPersonDocument = fn (array $relationships): bool => $relatedPersons->contains(function (array $person) use ($relationships): bool {
            $relationshipType = strtolower((string) ($person['relationship_type'] ?? ''));

            return in_array($relationshipType, $relationships, true)
                && collect($person['documents'] ?? [])->pluck('type')->filter()->isNotEmpty();
        });

        $requirements = [
            $this->requirement(
                key: 'profile_information',
                label: 'Profile information',
                category: 'profile',
                type: 'form',
                satisfied: true,
            ),
            $this->requirement(
                key: 'identity_document_front',
                label: 'Identity document front',
                category: 'document',
                type: 'document',
                satisfied: $documentTypes->intersect(['identity_document', 'identity_document_front', 'passport_front', 'national_id_front', 'driver_license_front'])->isNotEmpty(),
            ),
            $this->requirement(
                key: 'identity_document_back',
                label: 'Identity document back',
                category: 'document',
                type: 'document',
                satisfied: $documentTypes->intersect(['identity_document', 'identity_document_back', 'passport_back', 'national_id_back', 'driver_license_back'])->isNotEmpty(),
            ),
            $this->requirement(
                key: 'proof_of_address',
                label: 'Proof of address',
                category: 'document',
                type: 'document',
                satisfied: $documentTypes->intersect(['proof_of_address', 'proof_of_business_address'])->isNotEmpty(),
            ),
            $this->requirement(
                key: 'selfie_liveness',
                label: 'Selfie and liveness check',
                category: 'biometric',
                type: 'document',
                satisfied: $documentTypes->contains('selfie_liveness'),
            ),
        ];

        if ($isBusiness) {
            $requirements[] = $this->requirement(
                key: 'business_registration',
                label: 'Business registration document',
                category: 'business',
                type: 'document',
                satisfied: $profileDocumentTypes->contains('business_registration'),
            );
            $requirements[] = $this->requirement(
                key: 'certificate_of_incorporation',
                label: 'Certificate of incorporation',
                category: 'business',
                type: 'document',
                satisfied: $profileDocumentTypes->contains('certificate_of_incorporation'),
            );
            $requirements[] = $this->requirement(
                key: 'proof_of_business_address',
                label: 'Proof of business address',
                category: 'business',
                type: 'document',
                satisfied: $profileDocumentTypes->contains('proof_of_business_address'),
            );
            $requirements[] = $this->requirement(
                key: 'ownership_structure',
                label: 'Ownership structure or shareholder register',
                category: 'business',
                type: 'document',
                satisfied: $profileDocumentTypes->contains('ownership_structure'),
            );
            $requirements[] = $this->requirement(
                key: 'account_opening_application_form',
                label: 'Hand-held account opening application form',
                category: 'business',
                type: 'document',
                satisfied: $profileDocumentTypes->contains('account_opening_application_form'),
            );
            $requirements[] = $this->requirement(
                key: 'authorized_representative',
                label: 'Authorized representative',
                category: 'person',
                type: 'related_person',
                satisfied: $relationshipTypes->intersect(['authorized_representative', 'director'])->isNotEmpty(),
            );
            $requirements[] = $this->requirement(
                key: 'authorized_representative_identity_document',
                label: 'Authorized representative ID document',
                category: 'person',
                type: 'document',
                satisfied: $hasRelatedPersonDocument(['authorized_representative', 'director'])
                    || $relatedDocumentTypes->contains('authorized_representative_identity_document'),
            );
            $requirements[] = $this->requirement(
                key: 'beneficial_owner',
                label: 'Beneficial owner',
                category: 'person',
                type: 'related_person',
                satisfied: $relationshipTypes->intersect(['beneficial_owner', 'ubo'])->isNotEmpty(),
            );
            $requirements[] = $this->requirement(
                key: 'beneficial_owner_identity_document',
                label: 'UBO ID document',
                category: 'person',
                type: 'document',
                satisfied: $hasRelatedPersonDocument(['beneficial_owner', 'ubo'])
                    || $relatedDocumentTypes->intersect(['beneficial_owner_identity_document', 'ubo_identity_document'])->isNotEmpty(),
            );
        }

        return $requirements;
    }

    /**
     * @return array<string, mixed>
     */
    private function requirement(
        string $key,
        string $label,
        string $category,
        string $type,
        bool $satisfied,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'category' => $category,
            'status' => $satisfied ? 'submitted' : 'required',
            'requirement_type' => $type,
        ];
    }
}
