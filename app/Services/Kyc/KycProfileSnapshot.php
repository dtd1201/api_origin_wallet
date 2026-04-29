<?php

namespace App\Services\Kyc;

use App\Models\User;

class KycProfileSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $user->loadMissing([
            'profile',
            'kycProfile.documents',
            'kycProfile.relatedPersons.documents',
            'kycProfile.amlScreenings.matches',
            'kycProfile.providerSubmissions.provider',
            'kycProfile.requirements',
        ]);
        $kycProfile = $user->kycProfile;
        $documents = [];
        $relatedPersons = [];
        $amlScreenings = [];
        $providerSubmissions = [];
        $requirements = [];

        if ($kycProfile !== null) {
            $documents = $kycProfile->documents
                ->whereNull('kyc_related_person_id')
                ->map(fn ($document) => $this->documentSnapshot($document))
                ->values()
                ->all();
            $relatedPersons = $kycProfile->relatedPersons
                ->map(fn ($relatedPerson) => [
                    'id' => $relatedPerson->id,
                    'relationship_type' => $relatedPerson->relationship_type,
                    'status' => $relatedPerson->status,
                    'legal_name' => $relatedPerson->legal_name,
                    'date_of_birth' => $relatedPerson->date_of_birth?->toDateString(),
                    'nationality_country_code' => $relatedPerson->nationality_country_code,
                    'residence_country_code' => $relatedPerson->residence_country_code,
                    'ownership_percentage' => $relatedPerson->ownership_percentage,
                    'address' => [
                        'line1' => $relatedPerson->address_line1,
                        'line2' => $relatedPerson->address_line2,
                        'city' => $relatedPerson->city,
                        'state' => $relatedPerson->state,
                        'postal_code' => $relatedPerson->postal_code,
                        'country_code' => $relatedPerson->country_code,
                    ],
                    'metadata' => $relatedPerson->metadata ?? [],
                    'documents' => $relatedPerson->documents
                        ->map(fn ($document) => $this->documentSnapshot($document))
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all();
            $providerSubmissions = $kycProfile->providerSubmissions
                ->map(fn ($submission) => [
                    'provider_code' => $submission->provider?->code,
                    'provider_name' => $submission->provider?->name,
                    'status' => $submission->status,
                    'approved_at' => $submission->approved_at?->toISOString(),
                    'submitted_at' => $submission->submitted_at?->toISOString(),
                    'rejected_at' => $submission->rejected_at?->toISOString(),
                ])
                ->values()
                ->all();
            $amlScreenings = $kycProfile->amlScreenings
                ->where('status', '!=', 'superseded')
                ->map(fn ($screening) => [
                    'subject_type' => $screening->subject_type,
                    'subject_id' => $screening->subject_id,
                    'subject_name' => $screening->subject_name,
                    'subject_role' => $screening->subject_role,
                    'screening_provider' => $screening->screening_provider,
                    'status' => $screening->status,
                    'risk_level' => $screening->risk_level,
                    'risk_score' => $screening->risk_score,
                    'screened_at' => $screening->screened_at?->toISOString(),
                    'reviewed_at' => $screening->reviewed_at?->toISOString(),
                    'matches' => $screening->matches
                        ->map(fn ($match) => [
                            'list_type' => $match->list_type,
                            'source' => $match->source,
                            'matched_name' => $match->matched_name,
                            'score' => $match->score,
                            'status' => $match->status,
                            'resolved_at' => $match->resolved_at?->toISOString(),
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all();
            $requirements = $kycProfile->requirements
                ->map(fn ($requirement) => [
                    'key' => $requirement->key,
                    'label' => $requirement->label,
                    'category' => $requirement->category,
                    'status' => $requirement->status,
                    'requirement_type' => $requirement->requirement_type,
                    'subject_type' => $requirement->subject_type,
                    'subject_id' => $requirement->subject_id,
                    'review_note' => $requirement->review_note,
                    'rejection_reason' => $requirement->rejection_reason,
                    'metadata' => $requirement->metadata ?? [],
                ])
                ->values()
                ->all();
        }

        return [
            'status' => $user->kyc_status,
            'wallet_profile' => [
                'user_type' => $user->profile?->user_type,
                'country_code' => $user->profile?->country_code,
                'company_name' => $user->profile?->company_name,
                'company_reg_no' => $user->profile?->company_reg_no,
                'tax_id' => $user->profile?->tax_id,
                'address_line1' => $user->profile?->address_line1,
                'address_line2' => $user->profile?->address_line2,
                'city' => $user->profile?->city,
                'state' => $user->profile?->state,
                'postal_code' => $user->profile?->postal_code,
            ],
            'profile' => $kycProfile !== null
                ? [
                    'id' => $kycProfile->id,
                    'status' => $kycProfile->status,
                    'applicant_type' => $kycProfile->applicant_type,
                    'legal_name' => $kycProfile->legal_name,
                    'date_of_birth' => $kycProfile->date_of_birth?->toDateString(),
                    'nationality_country_code' => $kycProfile->nationality_country_code,
                    'residence_country_code' => $kycProfile->residence_country_code,
                    'business_name' => $kycProfile->business_name,
                    'business_registration_number' => $kycProfile->business_registration_number,
                    'tax_id' => $kycProfile->tax_id,
                    'registered_country_code' => $kycProfile->registered_country_code,
                    'address' => [
                        'line1' => $kycProfile->address_line1,
                        'line2' => $kycProfile->address_line2,
                        'city' => $kycProfile->city,
                        'state' => $kycProfile->state,
                        'postal_code' => $kycProfile->postal_code,
                        'country_code' => $kycProfile->country_code,
                    ],
                    'metadata' => $kycProfile->metadata ?? [],
                    'submitted_at' => $kycProfile->submitted_at?->toISOString(),
                    'reviewed_at' => $kycProfile->reviewed_at?->toISOString(),
                ]
                : null,
            'documents' => $documents,
            'related_persons' => $relatedPersons,
            'aml_screenings' => $amlScreenings,
            'provider_submissions' => $providerSubmissions,
            'requirements' => $requirements,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentSnapshot(mixed $document): array
    {
        return [
            'id' => $document->id,
            'type' => $document->type,
            'status' => $document->status,
            'file_url' => $document->file_url,
            'file_hash' => $document->file_hash,
            'side' => $document->side,
            'document_number' => $document->document_number,
            'issuing_country_code' => $document->issuing_country_code,
            'issued_at' => $document->issued_at?->toDateString(),
            'expires_at' => $document->expires_at?->toDateString(),
            'metadata' => $document->metadata ?? [],
        ];
    }
}
