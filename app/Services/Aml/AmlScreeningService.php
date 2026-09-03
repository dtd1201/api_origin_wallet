<?php

namespace App\Services\Aml;

use App\Contracts\Aml\AmlScreeningProvider;
use App\Data\Aml\AmlScreeningRequest;
use App\Models\AmlScreening;
use App\Models\KycProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AmlScreeningService
{
    public function __construct(private readonly AmlScreeningProvider $provider) {}

    /** @return Collection<int, AmlScreening> */
    public function prepareProfile(KycProfile $profile): Collection
    {
        $profile->loadMissing(['user', 'relatedPersons']);
        $profile->amlScreenings()->whereNull('superseded_at')->update(['superseded_at' => now()]);

        $screenings = collect([$this->createScreening(
            $profile,
            'kyc_profile',
            $profile->id,
            $profile->business_name ?: $profile->legal_name,
            $profile->applicant_type,
            ['applicant_type' => $profile->applicant_type, 'country_code' => $profile->country_code],
        )]);

        foreach ($profile->relatedPersons as $person) {
            $screenings->push($this->createScreening(
                $profile,
                'kyc_related_person',
                $person->id,
                $person->legal_name,
                $person->relationship_type,
                [
                    'relationship_type' => $person->relationship_type,
                    'country_code' => $person->country_code,
                    'nationality_country_code' => $person->nationality_country_code,
                ],
            ));
        }

        return $screenings;
    }

    /** @return Collection<int, AmlScreening> */
    public function runProfile(KycProfile $profile): Collection
    {
        $screenings = $profile->amlScreenings()
            ->whereNull('superseded_at')
            ->whereIn('status', ['pending', 'failed'])
            ->get();

        if ($screenings->isEmpty()) {
            $screenings = $this->prepareProfile($profile);
        }

        return $screenings->map(fn (AmlScreening $screening) => $this->runScreening($screening))->values();
    }

    public function runScreening(AmlScreening $screening): AmlScreening
    {
        $screening->update([
            'provider' => $this->provider->name(),
            'screening_provider' => $this->provider->name(),
            'status' => 'running',
            'compliance_decision' => 'pending_review',
            'screened_at' => now(),
            'completed_at' => null,
            'screening_result' => null,
            'result_summary' => null,
        ]);

        try {
            $result = $this->provider->screen(new AmlScreeningRequest(
                userId: $screening->user_id,
                subjectType: $screening->subject_type,
                subjectId: $screening->subject_id,
                subjectName: $screening->subject_name,
                subjectRole: $screening->subject_role,
                attributes: (array) data_get($screening->raw_data, 'subject_attributes', []),
            ));

            return DB::transaction(function () use ($screening, $result): AmlScreening {
                $screening->matches()->delete();

                foreach ($result->matches as $match) {
                    $screening->matches()->create([
                        'list_type' => $match['list_type'] ?? 'other',
                        'source' => $match['source'] ?? $this->provider->name(),
                        'matched_name' => $match['matched_name'] ?? $screening->subject_name,
                        'score' => $match['score'] ?? null,
                        'country_code' => $match['country_code'] ?? null,
                        'date_of_birth' => $match['date_of_birth'] ?? null,
                        'external_reference' => $match['external_reference'] ?? null,
                        'status' => 'open',
                    ]);
                }

                $requiresReview = $result->outcome === 'match';
                $screening->update([
                    'screening_reference' => $result->reference,
                    'screening_result' => $result->outcome,
                    'status' => $requiresReview ? 'manual_review' : 'completed',
                    'compliance_decision' => $requiresReview ? 'pending_review' : 'clear',
                    'risk_level' => $result->riskLevel,
                    'completed_at' => now(),
                    'result_summary' => $this->safeResultSummary($result->summary, $result->outcome, count($result->matches)),
                ]);

                return $screening->fresh(['matches', 'reviewedBy']);
            });
        } catch (Throwable $exception) {
            $screening->update([
                'status' => 'failed',
                'compliance_decision' => 'pending_review',
                'completed_at' => now(),
                'result_summary' => ['error' => 'provider_failure'],
            ]);
            Log::error('AML screening provider execution failed.', [
                'aml_screening_id' => $screening->id,
                'provider' => $this->provider->name(),
                'exception_class' => $exception::class,
            ]);

            return $screening->fresh(['matches', 'reviewedBy']);
        }
    }

    public function manualClear(AmlScreening $screening, User $reviewer, ?string $reviewNote): AmlScreening
    {
        $this->review($screening, $reviewer, $reviewNote, 'clear', 'cleared');

        return $screening->fresh(['matches', 'reviewedBy']);
    }

    public function confirmMatch(AmlScreening $screening, User $reviewer, ?string $reviewNote): AmlScreening
    {
        $this->review($screening, $reviewer, $reviewNote, 'rejected', 'confirmed_match');

        return $screening->fresh(['matches', 'reviewedBy']);
    }

    public function assertProfileClear(KycProfile $profile): void
    {
        $screenings = $profile->amlScreenings()->whereNull('superseded_at')->get();

        if ($screenings->isEmpty()) {
            throw new RuntimeException('AML screening must be run before KYC/KYB approval.');
        }

        if ($screenings->contains(fn (AmlScreening $screening) => $screening->status !== 'completed' || $screening->compliance_decision !== 'clear')) {
            throw new RuntimeException('All AML screenings must be clear or manually cleared before KYC/KYB approval.');
        }
    }

    /** @param array<string, scalar|null> $attributes */
    private function createScreening(KycProfile $profile, string $type, ?int $id, string $name, ?string $role, array $attributes): AmlScreening
    {
        return $profile->amlScreenings()->create([
            'user_id' => $profile->user_id,
            'subject_type' => $type,
            'subject_id' => $id,
            'subject_name' => $name,
            'subject_role' => $role,
            'provider' => $this->provider->name(),
            'screening_provider' => $this->provider->name(),
            'status' => 'pending',
            'screening_result' => null,
            'compliance_decision' => 'pending_review',
            'risk_level' => 'unknown',
            'raw_data' => ['subject_attributes' => $attributes],
        ]);
    }

    private function review(AmlScreening $screening, User $reviewer, ?string $note, string $decision, string $matchStatus): void
    {
        if ($screening->status !== 'manual_review' || $screening->compliance_decision !== 'pending_review') {
            throw new RuntimeException('Only AML screenings pending manual review may be decided.');
        }

        DB::transaction(function () use ($screening, $reviewer, $note, $decision, $matchStatus): void {
            $screening->matches()->where('status', 'open')->update([
                'status' => $matchStatus,
                'resolved_by_user_id' => $reviewer->id,
                'resolved_at' => now(),
                'resolution_note' => $note,
            ]);
            $screening->update([
                'status' => 'completed',
                'compliance_decision' => $decision,
                'risk_level' => $decision === 'rejected' ? 'critical' : $screening->risk_level,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);
        });
    }

    /** @param array<string, mixed> $summary */
    private function safeResultSummary(array $summary, string $outcome, int $matchCount): array
    {
        $categories = collect($summary['categories'] ?? [])
            ->filter(fn (mixed $category) => is_string($category))
            ->map(fn (string $category) => mb_substr($category, 0, 50))
            ->unique()
            ->take(20)
            ->values()
            ->all();

        return [
            'outcome' => $outcome,
            'match_count' => $matchCount,
            'categories' => $categories,
        ];
    }
}
