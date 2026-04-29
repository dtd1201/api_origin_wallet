<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\KycProfile;
use App\Models\User;
use App\Services\Aml\AmlScreeningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

    public function submit(Request $request, User $user, AmlScreeningService $amlScreeningService): JsonResponse
    {
        $validated = $request->validate($this->rules());

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
        $documentTypes = collect($validated['documents'] ?? [])
            ->pluck('type')
            ->map(fn (string $type) => strtolower($type));
        $relationshipTypes = collect($validated['related_persons'] ?? [])
            ->pluck('relationship_type')
            ->map(fn (string $type) => strtolower($type));
        $isBusiness = $validated['applicant_type'] === 'business';

        $requirements = [
            $this->requirement(
                key: 'profile_information',
                label: 'Profile information',
                category: 'profile',
                type: 'form',
                satisfied: true,
            ),
            $this->requirement(
                key: 'identity_document',
                label: 'Identity document',
                category: 'document',
                type: 'document',
                satisfied: $documentTypes->intersect(['passport', 'national_id', 'driver_license', 'identity_document'])->isNotEmpty(),
            ),
            $this->requirement(
                key: 'proof_of_address',
                label: 'Proof of address',
                category: 'document',
                type: 'document',
                satisfied: $documentTypes->contains('proof_of_address'),
            ),
        ];

        if ($isBusiness) {
            $requirements[] = $this->requirement(
                key: 'business_registration',
                label: 'Business registration document',
                category: 'business',
                type: 'document',
                satisfied: $documentTypes->contains('business_registration'),
            );
            $requirements[] = $this->requirement(
                key: 'authorized_representative',
                label: 'Authorized representative',
                category: 'person',
                type: 'related_person',
                satisfied: $relationshipTypes->intersect(['authorized_representative', 'director'])->isNotEmpty(),
            );
            $requirements[] = $this->requirement(
                key: 'beneficial_owner',
                label: 'Beneficial owner',
                category: 'person',
                type: 'related_person',
                satisfied: $relationshipTypes->intersect(['beneficial_owner', 'ubo'])->isNotEmpty(),
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
