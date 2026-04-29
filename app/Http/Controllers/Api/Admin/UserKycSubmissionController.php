<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\KycProfile;
use App\Models\User;
use App\Services\Aml\AmlScreeningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class UserKycSubmissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['draft', 'submitted', 'under_review', 'needs_more_info', 'verified', 'rejected', 'expired'])],
        ]);

        $profiles = KycProfile::query()
            ->with(['user', 'reviewedBy', 'documents', 'relatedPersons.documents', 'requirements', 'amlScreenings.matches'])
            ->whereHas('user', fn (Builder $query) => $query->nonAdmin())
            ->when(
                isset($validated['status']),
                fn (Builder $query) => $query->where('status', $validated['status'])
            )
            ->latest('submitted_at')
            ->paginate(15);

        return response()->json($profiles);
    }

    public function show(User $user): JsonResponse
    {
        $user = $this->resolveManageableUser($user)
            ->load(
                'kycProfile.documents',
                'kycProfile.relatedPersons.documents',
                'kycProfile.requirements',
                'kycProfile.amlScreenings.matches',
                'kycProfile.reviewedBy',
            );

        return response()->json([
            'user' => $user,
            'kyc_profile' => $user->kycProfile,
            'kyc_submission' => $user->kycProfile,
        ]);
    }

    public function approve(Request $request, User $user, AmlScreeningService $amlScreeningService): JsonResponse
    {
        $user = $this->resolveManageableUser($user);

        $validated = $request->validate([
            'review_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $kycProfile = $this->reviewProfile(
            request: $request,
            user: $user,
            status: 'verified',
            reviewNote: $validated['review_note'] ?? null,
            amlScreeningService: $amlScreeningService,
        );

        return response()->json([
            'message' => 'KYC profile approved.',
            'user' => $user->fresh(),
            'kyc_profile' => $kycProfile,
            'kyc_submission' => $kycProfile,
        ]);
    }

    public function reject(Request $request, User $user): JsonResponse
    {
        $user = $this->resolveManageableUser($user);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
            'review_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'requirements' => ['sometimes', 'array'],
            'requirements.*.key' => ['required_with:requirements', 'string', 'max:100'],
            'requirements.*.status' => ['sometimes', 'string', Rule::in(['rejected', 'needs_more_info'])],
            'requirements.*.rejection_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $kycProfile = $this->reviewProfile(
            request: $request,
            user: $user,
            status: 'rejected',
            reviewNote: $validated['review_note'] ?? null,
            rejectionReason: $validated['rejection_reason'],
            requirementReviews: $validated['requirements'] ?? [],
        );

        return response()->json([
            'message' => 'KYC profile rejected.',
            'user' => $user->fresh(),
            'kyc_profile' => $kycProfile,
            'kyc_submission' => $kycProfile,
        ]);
    }

    private function reviewProfile(
        Request $request,
        User $user,
        string $status,
        ?string $reviewNote,
        ?string $rejectionReason = null,
        array $requirementReviews = [],
        ?AmlScreeningService $amlScreeningService = null,
    ): KycProfile {
        /** @var KycProfile $kycProfile */
        $kycProfile = $user->kycProfile()
            ->with(['documents', 'relatedPersons', 'requirements'])
            ->firstOrFail();

        if ($status === 'verified' && $kycProfile->requirements()->where('status', 'required')->exists()) {
            abort(422, 'All required KYC requirements must be submitted before approval.');
        }

        if ($status === 'verified') {
            try {
                $amlScreeningService?->assertProfileClear($kycProfile);
            } catch (RuntimeException $exception) {
                abort(422, $exception->getMessage());
            }
        }

        return DB::transaction(function () use ($request, $user, $kycProfile, $status, $reviewNote, $rejectionReason, $requirementReviews): KycProfile {
            $oldData = $kycProfile->toArray();
            $reviewedByUserId = $request->user()?->id;

            $kycProfile->update([
                'status' => $status,
                'reviewed_by_user_id' => $reviewedByUserId,
                'reviewed_at' => now(),
                'review_note' => $reviewNote,
                'rejection_reason' => $status === 'rejected' ? $rejectionReason : null,
            ]);

            if ($status === 'verified') {
                $kycProfile->documents()->update(['status' => 'approved']);
                $kycProfile->relatedPersons()->update(['status' => 'approved']);
                $kycProfile->requirements()->update([
                    'status' => 'approved',
                    'review_note' => $reviewNote,
                    'rejection_reason' => null,
                ]);
            } else {
                $this->applyRequirementRejections($kycProfile, $requirementReviews, $rejectionReason);
            }

            $user->update([
                'status' => $status === 'verified' ? 'active' : 'pending',
                'kyc_status' => $status === 'verified' ? 'verified' : 'rejected',
            ]);

            AuditLog::query()->create([
                'user_id' => $reviewedByUserId,
                'action' => $status === 'verified' ? 'kyc.approved' : 'kyc.rejected',
                'entity_type' => 'kyc_profile',
                'entity_id' => (string) $kycProfile->id,
                'old_data' => $oldData,
                'new_data' => [
                    ...$kycProfile->fresh()->toArray(),
                    'target_user_id' => $user->id,
                    'target_user_kyc_status' => $user->fresh()->kyc_status,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ]);

            return $kycProfile->fresh(['user', 'reviewedBy', 'documents', 'relatedPersons.documents', 'requirements', 'amlScreenings.matches']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $requirementReviews
     */
    private function applyRequirementRejections(KycProfile $kycProfile, array $requirementReviews, ?string $defaultReason): void
    {
        if ($requirementReviews === []) {
            $kycProfile->requirements()
                ->whereIn('status', ['required', 'submitted'])
                ->update([
                    'status' => 'needs_more_info',
                    'rejection_reason' => $defaultReason,
                ]);

            return;
        }

        foreach ($requirementReviews as $review) {
            $kycProfile->requirements()
                ->where('key', $review['key'])
                ->update([
                    'status' => $review['status'] ?? 'needs_more_info',
                    'rejection_reason' => $review['rejection_reason'] ?? $defaultReason,
                ]);
        }
    }

    private function resolveManageableUser(User $user): User
    {
        $user->loadMissing('roles');

        abort_if($user->isAdmin(), 404);

        return $user;
    }
}
