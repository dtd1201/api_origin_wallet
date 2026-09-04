<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\User;
use App\Services\Aml\AmlScreeningService;
use App\Services\Compliance\ComplianceEvidenceService;
use App\Services\Integrations\ProviderOnboardingEligibilityException;
use App\Services\Integrations\ProviderOnboardingReadinessService;
use App\Services\Nium\NiumCustomerOnboardingService;
use App\Services\Nium\NiumProviderRequestException;
use App\Support\PrimaryProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class UserKycSubmissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['draft', 'submitted', 'under_review', 'needs_more_info', 'verified', 'rejected', 'expired'])],
        ]);

        $profiles = KycProfile::query()
            ->with([
                'user',
                'reviewedBy',
                'documents',
                'relatedPersons.documents',
                'requirements',
                'amlScreenings' => fn ($query) => $query->whereNull('superseded_at'),
                'amlScreenings.matches',
            ])
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
            ->load([
                'kycProfile.documents',
                'kycProfile.relatedPersons.documents',
                'kycProfile.requirements',
                'kycProfile.amlScreenings' => fn ($query) => $query->whereNull('superseded_at'),
                'kycProfile.amlScreenings.matches',
                'kycProfile.reviewedBy',
            ]);

        return response()->json([
            'user' => $user,
            'kyc_profile' => $user->kycProfile,
            'kyc_submission' => $user->kycProfile,
        ]);
    }

    public function approve(
        Request $request,
        User $user,
        AmlScreeningService $amlScreeningService,
        ComplianceEvidenceService $complianceEvidenceService,
        ProviderOnboardingReadinessService $readinessService,
        NiumCustomerOnboardingService $onboardingService,
    ): JsonResponse {
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
            complianceEvidenceService: $complianceEvidenceService,
        );

        $provider = IntegrationProvider::query()
            ->where('code', PrimaryProvider::code())
            ->where('status', 'active')
            ->first();

        if ($provider === null) {
            return response()->json([
                'message' => 'KYC was approved, but Nium onboarding is not configured.',
            ], 422);
        }

        try {
            $readyUser = $user->fresh()->load('profile', 'providerAccounts.provider');
            $submission = $readinessService->assertReady($provider, $readyUser);
            $providerAccount = $onboardingService->syncUser($provider, $readyUser);
            $complianceEvidenceService->markNiumSubmissionSubmitted($submission, $providerAccount->id);
        } catch (ProviderOnboardingEligibilityException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                ...$exception->context(),
            ], 422);
        } catch (NiumProviderRequestException $exception) {
            $complianceEvidenceService->markNiumSubmissionFailed(
                $submission,
                $exception->providerCode ?? 'nium_request_failed',
            );

            return response()->json(array_filter([
                'message' => $exception->getMessage(),
                'code' => $exception->providerCode,
                'field' => $exception->providerField,
                'path' => $exception->providerPath,
            ], static fn ($value): bool => $value !== null), 422);
        } catch (RuntimeException $exception) {
            $complianceEvidenceService->markNiumSubmissionFailed($submission, 'nium_onboarding_failed');

            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable) {
            $complianceEvidenceService->markNiumSubmissionFailed($submission, 'nium_onboarding_failed');

            return response()->json([
                'message' => 'KYC was approved, but Nium onboarding could not be completed. The submission can be retried safely.',
            ], 422);
        }

        return response()->json([
            'message' => 'KYC profile approved and Nium onboarding submitted.',
            'user' => $user->fresh(),
            'kyc_profile' => $kycProfile,
            'kyc_submission' => $kycProfile,
            'provider_account' => $providerAccount,
        ]);
    }

    public function reject(Request $request, User $user, ComplianceEvidenceService $complianceEvidenceService): JsonResponse
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
            complianceEvidenceService: $complianceEvidenceService,
        );

        return response()->json([
            'message' => 'KYC profile rejected.',
            'user' => $user->fresh(),
            'kyc_profile' => $kycProfile,
            'kyc_submission' => $kycProfile,
        ]);
    }

    public function requestUpdate(
        Request $request,
        User $user,
        ComplianceEvidenceService $complianceEvidenceService,
    ): JsonResponse {
        $user = $this->resolveManageableUser($user);

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:100'],
            'label' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:50'],
            'requirement_type' => ['required', 'string', 'max:50'],
            'subject_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'subject_id' => ['sometimes', 'nullable', 'integer'],
            'reason' => ['required', 'string', 'max:2000'],
            'review_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'metadata' => ['sometimes', 'array'],
        ]);

        /** @var KycProfile $kycProfile */
        $kycProfile = $user->kycProfile()
            ->with(['documents', 'relatedPersons', 'requirements'])
            ->firstOrFail();

        $kycProfile = DB::transaction(function () use ($request, $user, $kycProfile, $validated, $complianceEvidenceService): KycProfile {
            $oldData = $kycProfile->toArray();
            $reviewedByUserId = $request->user()?->id;

            $kycProfile->update([
                'status' => 'needs_more_info',
                'reviewed_by_user_id' => $reviewedByUserId,
                'reviewed_at' => now(),
                'review_note' => $validated['review_note'] ?? null,
                'rejection_reason' => null,
            ]);

            $requirement = $kycProfile->requirements()->updateOrCreate(
                ['key' => $validated['key']],
                [
                    'label' => $validated['label'],
                    'category' => $validated['category'],
                    'status' => 'needs_more_info',
                    'requirement_type' => $validated['requirement_type'],
                    'subject_type' => $validated['subject_type'] ?? null,
                    'subject_id' => $validated['subject_id'] ?? null,
                    'review_note' => $validated['review_note'] ?? null,
                    'rejection_reason' => $validated['reason'],
                    'metadata' => [
                        ...($validated['metadata'] ?? []),
                        'requested_by_user_id' => $reviewedByUserId,
                        'requested_at' => now()->toISOString(),
                    ],
                ]
            );

            if (($validated['subject_type'] ?? null) === 'document' && isset($validated['subject_id'])) {
                $kycProfile->documents()
                    ->whereKey($validated['subject_id'])
                    ->update(['status' => 'needs_more_info']);
            }

            if (($validated['subject_type'] ?? null) === 'related_person' && isset($validated['subject_id'])) {
                $kycProfile->relatedPersons()
                    ->whereKey($validated['subject_id'])
                    ->update(['status' => 'needs_more_info']);
            }

            $user->update([
                'status' => 'pending',
                'kyc_status' => 'needs_more_info',
            ]);

            $complianceEvidenceService->invalidateNiumSubmission(
                profile: $kycProfile->fresh(),
                reason: 'kyc_update_requested',
                actorUserId: $reviewedByUserId,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );

            AuditLog::query()->create([
                'user_id' => $reviewedByUserId,
                'action' => 'kyc.update_requested',
                'entity_type' => 'kyc_profile',
                'entity_id' => (string) $kycProfile->id,
                'old_data' => $oldData,
                'new_data' => [
                    ...$kycProfile->fresh()->toArray(),
                    'target_user_id' => $user->id,
                    'target_user_kyc_status' => $user->fresh()->kyc_status,
                    'requested_requirement' => $requirement->fresh()?->toArray(),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ]);

            return $kycProfile->fresh([
                'user',
                'reviewedBy',
                'documents',
                'relatedPersons.documents',
                'requirements',
                'amlScreenings' => fn ($query) => $query->whereNull('superseded_at'),
                'amlScreenings.matches',
            ]);
        });

        return response()->json([
            'message' => 'KYC update requested.',
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
        ?ComplianceEvidenceService $complianceEvidenceService = null,
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
                $complianceEvidenceService?->assertNoBlockingNiumCompliance($user->id);
            } catch (RuntimeException $exception) {
                abort(422, $exception->getMessage());
            }
        }

        return DB::transaction(function () use ($request, $user, $kycProfile, $status, $reviewNote, $rejectionReason, $requirementReviews, $complianceEvidenceService): KycProfile {
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

            if ($status === 'verified') {
                $complianceEvidenceService?->prepareNiumSubmission(
                    profile: $kycProfile->fresh(),
                    reviewerUserId: $reviewedByUserId,
                    reviewNote: $reviewNote,
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent(),
                );
            } else {
                $complianceEvidenceService?->invalidateNiumSubmission(
                    profile: $kycProfile->fresh(),
                    reason: 'internal_kyc_rejected',
                    actorUserId: $reviewedByUserId,
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent(),
                );
            }

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

            return $kycProfile->fresh([
                'user',
                'reviewedBy',
                'documents',
                'relatedPersons.documents',
                'requirements',
                'amlScreenings' => fn ($query) => $query->whereNull('superseded_at'),
                'amlScreenings.matches',
            ]);
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
