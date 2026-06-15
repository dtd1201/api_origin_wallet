<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\KycProfile;
use App\Models\User;
use App\Services\Aml\AmlScreeningService;
use App\Services\Kyc\BusinessRegistryVerificationService;
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
    ): JsonResponse {
        $validated = $request->validate($this->rules());
        $validated = $this->attachBusinessRegistryVerification($validated, $businessRegistryVerificationService);

        $kycProfile = DB::transaction(function () use ($user, $validated, $amlScreeningService): KycProfile {
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
                $kycProfile->documents()->create([
                    ...Arr::only($document, $this->documentFields()),
                    'status' => 'submitted',
                ]);
            }

            foreach ($validated['related_persons'] ?? [] as $relatedPerson) {
                $relatedPersonDocuments = $relatedPerson['documents'] ?? [];
                $createdRelatedPerson = $kycProfile->relatedPersons()->create([
                    ...Arr::only($relatedPerson, $this->relatedPersonFields()),
                    'status' => 'submitted',
                ]);

                foreach ($relatedPersonDocuments as $document) {
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
    private function rules(): array
    {
        return [
            'applicant_type' => ['required', 'string', Rule::in(['individual', 'business'])],
            'legal_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required_if:applicant_type,individual', 'nullable', 'date', 'before:today'],
            'nationality_country_code' => ['nullable', 'string', 'size:2'],
            'residence_country_code' => ['nullable', 'string', 'size:2'],
            'business_name' => ['required_if:applicant_type,business', 'nullable', 'string', 'max:255'],
            'business_registration_number' => ['nullable', 'string', 'max:100'],
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
            'related_persons.*.state' => ['nullable', 'string', 'max:100'],
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
        ];
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
