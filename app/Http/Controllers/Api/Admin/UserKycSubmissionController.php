<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\KycProviderSubmission;
use App\Models\User;
use App\Services\Aml\AmlScreeningService;
use App\Services\Aml\StagingAmlProviderUnavailableBypass;
use App\Services\Compliance\ComplianceEvidenceService;
use App\Services\Integrations\ProviderOnboardingEligibilityException;
use App\Services\Integrations\ProviderOnboardingReadinessService;
use App\Services\Nium\NiumCustomerOnboardingService;
use App\Services\Nium\NiumProviderRequestException;
use App\Support\KycAuditProjection;
use App\Support\PrimaryProvider;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $approvalLock = Cache::lock("kyc-approval:{$user->id}", 120);

        if (! $approvalLock->get()) {
            return response()->json([
                'message' => 'This KYC approval is already being processed. Refresh the review before retrying.',
                'code' => 'kyc_approval_in_progress',
            ], 409);
        }

        try {
            $provider = IntegrationProvider::query()
                ->where('code', PrimaryProvider::code())
                ->where('status', 'active')
                ->first();

            $existingSubmission = $provider === null ? null : KycProviderSubmission::query()
                ->where('user_id', $user->id)
                ->where('provider_id', $provider->id)
                ->where('status', 'submitted')
                ->with('providerAccount')
                ->first();

            if ($existingSubmission !== null) {
                $kycProfile = $user->kycProfile()->with([
                    'user', 'reviewedBy', 'documents', 'relatedPersons.documents', 'requirements',
                    'amlScreenings' => fn ($query) => $query->whereNull('superseded_at'),
                    'amlScreenings.matches',
                ])->firstOrFail();

                return response()->json([
                    'message' => 'KYC profile was already approved and submitted to Nium.',
                    'user' => $user->fresh(),
                    'kyc_profile' => $kycProfile,
                    'kyc_submission' => $kycProfile,
                    'provider_account' => $existingSubmission->providerAccount,
                ]);
            }

            $amlBypassApplied = false;
            $kycProfile = $this->reviewProfile(
                request: $request,
                user: $user,
                status: 'verified',
                reviewNote: $validated['review_note'] ?? null,
                amlScreeningService: $amlScreeningService,
                complianceEvidenceService: $complianceEvidenceService,
                amlBypassApplied: $amlBypassApplied,
            );

            if ($provider === null) {
                return response()->json([
                    'message' => 'KYC was approved, but Nium onboarding is not configured.',
                ], 422);
            }

            $submission = KycProviderSubmission::query()
                ->where('user_id', $user->id)
                ->where('provider_id', $provider->id)
                ->firstOrFail();

            try {
                $readyUser = $user->fresh()->load('profile', 'kycProfile.documents', 'kycProfile.relatedPersons.documents', 'providerAccounts.provider');
                $submission = $readinessService->assertReady($provider, $readyUser);
                $onboarding = $onboardingService->beginOnboarding($provider, $readyUser);
                $providerAccount = $onboarding->providerAccount;

                if ($providerAccount === null) {
                    throw new RuntimeException('Nium onboarding did not return a provider account.');
                }

                if ((int) data_get($onboarding->metadata, 'pending_document_count', 0) > 0) {
                    $complianceEvidenceService->markNiumSubmissionPendingDocuments($submission, $providerAccount->id);

                    return response()->json([
                        'message' => 'KYC profile approved. Nium document processing must complete before customer submission.',
                        'user' => $user->fresh(),
                        'kyc_profile' => $kycProfile,
                        'kyc_submission' => $kycProfile,
                        'provider_account' => $providerAccount,
                    ], 202);
                }

                if (! filled($providerAccount->external_customer_id)) {
                    throw new RuntimeException('Nium onboarding did not confirm a customer account.');
                }

                $complianceEvidenceService->markNiumSubmissionSubmitted($submission, $providerAccount->id);
            } catch (ProviderOnboardingEligibilityException $exception) {
                $complianceEvidenceService->markNiumSubmissionFailed($submission, 'nium_onboarding_validation_failed');

                return response()->json([
                    'message' => 'KYC was approved, but the persisted profile is not ready for Nium onboarding.',
                    'code' => 'nium_onboarding_validation_failed',
                ], 422);
            } catch (NiumProviderRequestException $exception) {
                $complianceEvidenceService->markNiumSubmissionFailed($submission, 'nium_onboarding_failed');
                $this->logNiumOnboardingFailure($exception, $user, $submission);

                return response()->json([
                    'message' => $exception->getMessage(),
                    'code' => 'nium_onboarding_failed',
                ], 422);
            } catch (RuntimeException $exception) {
                $complianceEvidenceService->markNiumSubmissionFailed($submission, 'nium_onboarding_failed');
                $this->logNiumOnboardingFailure($exception, $user, $submission);

                return response()->json([
                    'message' => 'KYC was approved, but Nium onboarding could not be completed. The submission can be retried safely.',
                    'code' => 'nium_onboarding_failed',
                ], 422);
            } catch (Throwable $exception) {
                $complianceEvidenceService->markNiumSubmissionFailed($submission, 'nium_onboarding_failed');
                $this->logNiumOnboardingFailure($exception, $user, $submission);

                return response()->json([
                    'message' => 'KYC was approved, but Nium onboarding could not be completed. The submission can be retried safely.',
                    'code' => 'nium_onboarding_failed',
                ], 422);
            }

            return response()->json([
                'message' => $amlBypassApplied
                    ? 'AML provider unavailable. Staging bypass applied.'
                    : 'KYC profile approved and Nium onboarding submitted.',
                'aml_bypass_reason' => $amlBypassApplied ? StagingAmlProviderUnavailableBypass::REASON : null,
                'user' => $user->fresh(),
                'kyc_profile' => $kycProfile,
                'kyc_submission' => $kycProfile,
                'provider_account' => $providerAccount,
            ]);
        } finally {
            $approvalLock->release();
        }
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
            $previousStatus = $kycProfile->status;
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
                'old_data' => null,
                'new_data' => KycAuditProjection::profile(
                    $kycProfile->fresh(['documents', 'relatedPersons', 'requirements']),
                    $previousStatus,
                    ['status', 'reviewed_by_user_id', 'reviewed_at', 'review_note', 'requirements.'.$requirement->id],
                ),
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
        ?bool &$amlBypassApplied = null,
    ): KycProfile {
        /** @var KycProfile $kycProfile */
        $kycProfile = $user->kycProfile()
            ->with(['documents', 'relatedPersons', 'requirements'])
            ->firstOrFail();

        if ($status === 'verified' && $this->hasBlockingRequiredRequirements($kycProfile)) {
            abort(422, 'All required KYC requirements must be submitted before approval.');
        }

        if ($status === 'verified') {
            try {
                $amlBypassApplied = $amlScreeningService?->assertProfileClear($kycProfile) ?? false;
                $complianceEvidenceService?->assertNoBlockingNiumCompliance($user->id);
            } catch (RuntimeException $exception) {
                abort(422, $exception->getMessage());
            }
        }

        return DB::transaction(function () use ($request, $user, $kycProfile, $status, $reviewNote, $rejectionReason, $requirementReviews, $complianceEvidenceService, $amlBypassApplied): KycProfile {
            $previousStatus = $kycProfile->status;
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
                'old_data' => null,
                'new_data' => KycAuditProjection::profile(
                    $kycProfile->fresh(['documents', 'relatedPersons', 'requirements']),
                    $previousStatus,
                    ['status', 'reviewed_by_user_id', 'reviewed_at', 'review_note', 'rejection_reason', 'documents.*.status', 'related_persons.*.status', 'requirements.*.status'],
                ),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ]);

            if ($status === 'verified' && $amlBypassApplied === true) {
                AuditLog::query()->create([
                    'user_id' => $reviewedByUserId,
                    'action' => 'kyc.aml_bypass_applied',
                    'entity_type' => 'kyc_profile',
                    'entity_id' => (string) $kycProfile->id,
                    'old_data' => null,
                    'new_data' => ['reason' => StagingAmlProviderUnavailableBypass::REASON],
                    'ip_address' => $request->ip(),
                    'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
                ]);
            }

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

    private function hasBlockingRequiredRequirements(KycProfile $profile): bool
    {
        $requiredKeys = $profile->requirements
            ->where('status', 'required')
            ->pluck('key');

        $isHkCorporateFull = $profile->applicant_type === 'business'
            && strtoupper((string) data_get($profile->metadata, 'nium_region')) === 'HK'
            && strtolower((string) data_get($profile->metadata, 'nium_kyc_type')) === 'full';

        if (! $isHkCorporateFull) {
            return $requiredKeys->isNotEmpty();
        }

        $documentTypes = $profile->documents
            ->filter(fn ($document): bool => in_array(
                strtolower((string) $document->status),
                ['submitted', 'approved', 'verified'],
                true,
            ))
            ->map(fn ($document): string => strtolower((string) $document->type));
        $missingBusinessRegistration = $documentTypes
            ->intersect(['business_registration', 'certificate_of_incorporation'])
            ->isEmpty();
        $blockingKeys = [
            'authorized_representative',
            'authorized_representative_identity_document',
            'beneficial_owner',
            'beneficial_owner_identity_document',
        ];

        if (data_get($profile->metadata, 'nium_v5_fields.isMultiLayeredCompany') === true) {
            $blockingKeys[] = 'ownership_structure';
        }

        return $missingBusinessRegistration || $requiredKeys->intersect($blockingKeys)->isNotEmpty();
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

    private function logNiumOnboardingFailure(
        Throwable $exception,
        User $user,
        KycProviderSubmission $submission,
    ): void {
        $httpStatus = null;
        $providerCode = null;
        $providerField = null;
        $providerPath = null;

        if ($exception instanceof NiumProviderRequestException) {
            $httpStatus = $exception->httpStatus;
            $providerCode = $exception->providerCode;
            $providerField = $exception->providerField;
            $providerPath = $exception->providerPath;
        } elseif ($exception instanceof RequestException) {
            $httpStatus = $exception->response->status();
        }

        $sanitizer = app(SensitiveDataSanitizer::class);

        Log::error('Direct Nium onboarding failed after KYC approval.', [
            'exception_class' => $exception::class,
            'exception_message' => $sanitizer->sanitize($exception->getMessage()),
            'http_status' => $httpStatus,
            'provider_code' => $providerCode,
            'provider_field' => $providerField,
            'provider_path' => $providerPath,
            'user_id' => $user->id,
            'kyc_submission_id' => $submission->id,
        ]);
    }
}
