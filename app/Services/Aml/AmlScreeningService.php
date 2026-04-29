<?php

namespace App\Services\Aml;

use App\Models\AmlScreening;
use App\Models\KycProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

class AmlScreeningService
{
    /**
     * @return Collection<int, AmlScreening>
     */
    public function prepareProfile(KycProfile $kycProfile): Collection
    {
        $kycProfile->loadMissing(['user', 'relatedPersons']);

        $kycProfile->amlScreenings()
            ->where('status', '!=', 'superseded')
            ->update(['status' => 'superseded']);

        $screenings = collect([
            $this->createScreening(
                kycProfile: $kycProfile,
                subjectType: 'kyc_profile',
                subjectId: $kycProfile->id,
                subjectName: $kycProfile->business_name ?: $kycProfile->legal_name,
                subjectRole: $kycProfile->applicant_type,
                rawData: [
                    'applicant_type' => $kycProfile->applicant_type,
                    'country_code' => $kycProfile->country_code,
                    'metadata' => $kycProfile->metadata ?? [],
                ],
            ),
        ]);

        foreach ($kycProfile->relatedPersons as $relatedPerson) {
            $screenings->push(
                $this->createScreening(
                    kycProfile: $kycProfile,
                    subjectType: 'kyc_related_person',
                    subjectId: $relatedPerson->id,
                    subjectName: $relatedPerson->legal_name,
                    subjectRole: $relatedPerson->relationship_type,
                    rawData: [
                        'relationship_type' => $relatedPerson->relationship_type,
                        'country_code' => $relatedPerson->country_code,
                        'nationality_country_code' => $relatedPerson->nationality_country_code,
                        'metadata' => $relatedPerson->metadata ?? [],
                    ],
                )
            );
        }

        return $screenings;
    }

    /**
     * @return Collection<int, AmlScreening>
     */
    public function runProfile(KycProfile $kycProfile): Collection
    {
        $kycProfile->loadMissing('amlScreenings.matches');

        $screenings = $kycProfile->amlScreenings()
            ->whereIn('status', ['pending', 'failed'])
            ->get();

        if ($screenings->isEmpty()) {
            $screenings = $this->prepareProfile($kycProfile);
        }

        return $screenings
            ->map(fn (AmlScreening $screening) => $this->runScreening($screening))
            ->values();
    }

    public function runScreening(AmlScreening $screening): AmlScreening
    {
        $screening->matches()->delete();

        $configuredMatches = (array) data_get($screening->raw_data ?? [], 'metadata.aml.matches', []);

        foreach ($configuredMatches as $match) {
            $screening->matches()->create([
                'list_type' => $match['list_type'] ?? 'internal_watchlist',
                'source' => $match['source'] ?? 'internal',
                'matched_name' => $match['matched_name'] ?? $screening->subject_name,
                'score' => $match['score'] ?? 95,
                'country_code' => $match['country_code'] ?? null,
                'date_of_birth' => $match['date_of_birth'] ?? null,
                'external_reference' => $match['external_reference'] ?? null,
                'status' => 'open',
                'raw_data' => $match,
            ]);
        }

        $hasMatches = $screening->matches()->where('status', 'open')->exists();

        $screening->update([
            'status' => $hasMatches ? 'potential_match' : 'clear',
            'risk_level' => $hasMatches ? 'high' : 'low',
            'risk_score' => $hasMatches ? 85 : 5,
            'screened_at' => now(),
            'raw_data' => array_merge($screening->raw_data ?? [], [
                'screening_provider' => 'internal',
                'screened_at' => now()->toISOString(),
            ]),
        ]);

        return $screening->fresh(['matches', 'reviewedBy']);
    }

    public function manualClear(AmlScreening $screening, User $reviewer, ?string $reviewNote): AmlScreening
    {
        $screening->matches()
            ->where('status', 'open')
            ->update([
                'status' => 'cleared',
                'resolved_by_user_id' => $reviewer->id,
                'resolved_at' => now(),
                'resolution_note' => $reviewNote,
            ]);

        $screening->update([
            'status' => 'manual_clear',
            'risk_level' => $screening->risk_level === 'unknown' ? 'medium' : $screening->risk_level,
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $reviewNote,
        ]);

        return $screening->fresh(['matches', 'reviewedBy']);
    }

    public function confirmMatch(AmlScreening $screening, User $reviewer, ?string $reviewNote): AmlScreening
    {
        $screening->matches()
            ->where('status', 'open')
            ->update([
                'status' => 'confirmed_match',
                'resolved_by_user_id' => $reviewer->id,
                'resolved_at' => now(),
                'resolution_note' => $reviewNote,
            ]);

        $screening->update([
            'status' => 'confirmed_match',
            'risk_level' => 'critical',
            'risk_score' => 100,
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $reviewNote,
        ]);

        return $screening->fresh(['matches', 'reviewedBy']);
    }

    public function assertProfileClear(KycProfile $kycProfile): void
    {
        $activeScreenings = $kycProfile->amlScreenings()
            ->where('status', '!=', 'superseded')
            ->get();

        if ($activeScreenings->isEmpty()) {
            throw new RuntimeException('AML screening must be run before KYC/KYB approval.');
        }

        $blockedScreening = $activeScreenings
            ->first(fn (AmlScreening $screening) => ! in_array($screening->status, ['clear', 'manual_clear'], true));

        if ($blockedScreening !== null) {
            throw new RuntimeException('All AML screenings must be clear or manually cleared before KYC/KYB approval.');
        }
    }

    /**
     * @param  array<string, mixed>  $rawData
     */
    private function createScreening(
        KycProfile $kycProfile,
        string $subjectType,
        int $subjectId,
        string $subjectName,
        string $subjectRole,
        array $rawData,
    ): AmlScreening {
        return $kycProfile->amlScreenings()->create([
            'user_id' => $kycProfile->user_id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_name' => $subjectName,
            'subject_role' => $subjectRole,
            'screening_provider' => 'internal',
            'status' => 'pending',
            'risk_level' => 'unknown',
            'raw_data' => $rawData,
        ]);
    }
}
