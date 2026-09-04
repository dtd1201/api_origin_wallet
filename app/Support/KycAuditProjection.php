<?php

namespace App\Support;

use App\Models\KycDocument;
use App\Models\KycProfile;
use App\Models\KycProviderSubmission;

final class KycAuditProjection
{
    public static function profile(KycProfile $profile, ?string $previousStatus, array $changedPaths): array
    {
        return [
            'kyc_profile_id' => $profile->id,
            'customer_record_id' => $profile->user_id,
            'status_from' => $previousStatus,
            'status_to' => $profile->status,
            'changed_field_paths' => array_values(array_unique($changedPaths)),
            'document_records' => $profile->documents->map(fn (KycDocument $document): array => [
                'id' => $document->id,
                'type' => $document->type,
                'status' => $document->status,
            ])->values()->all(),
            'related_person_record_ids' => $profile->relatedPersons->pluck('id')->values()->all(),
            'requirement_record_ids' => $profile->requirements->pluck('id')->values()->all(),
            'recorded_at' => now()->toISOString(),
        ];
    }

    public static function providerSubmission(KycProviderSubmission $submission, ?string $previousStatus, string $reason): array
    {
        return [
            'provider_submission_record_id' => $submission->id,
            'kyc_profile_id' => $submission->kyc_profile_id,
            'provider_record_id' => $submission->provider_id,
            'provider_account_record_id' => $submission->provider_account_id,
            'status_from' => $previousStatus,
            'status_to' => $submission->status,
            'reason_code' => $reason,
            'recorded_at' => now()->toISOString(),
        ];
    }
}
