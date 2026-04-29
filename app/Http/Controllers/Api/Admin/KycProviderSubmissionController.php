<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\IntegrationProvider;
use App\Models\KycProviderSubmission;
use App\Models\User;
use App\Services\Aml\AmlScreeningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class KycProviderSubmissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['pending', 'approved', 'submitted', 'rejected', 'failed'])],
            'provider_code' => ['sometimes', 'string', 'exists:integration_providers,code'],
        ]);

        $submissions = KycProviderSubmission::query()
            ->with(['user', 'kycProfile', 'provider', 'providerAccount', 'reviewedBy'])
            ->whereHas('user', fn (Builder $query) => $query->nonAdmin())
            ->when(
                isset($validated['status']),
                fn (Builder $query) => $query->where('status', $validated['status'])
            )
            ->when(
                isset($validated['provider_code']),
                fn (Builder $query) => $query->whereHas(
                    'provider',
                    fn (Builder $providerQuery) => $providerQuery->where('code', $validated['provider_code'])
                )
            )
            ->latest('updated_at')
            ->paginate(15);

        return response()->json($submissions);
    }

    public function userIndex(User $user): JsonResponse
    {
        $user = $this->resolveManageableUser($user);

        return response()->json([
            'user' => $user,
            'data' => $user->kycProviderSubmissions()
                ->with(['provider', 'kycProfile', 'providerAccount', 'reviewedBy'])
                ->latest('updated_at')
                ->get(),
        ]);
    }

    public function approve(
        Request $request,
        User $user,
        IntegrationProvider $provider,
        AmlScreeningService $amlScreeningService,
    ): JsonResponse {
        $user = $this->resolveManageableUser($user)->load('kycProfile');

        $validated = $request->validate([
            'review_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'metadata' => ['sometimes', 'array'],
        ]);

        try {
            $provider->assertSupportsCapability('onboarding');
            $this->ensureInternalKycVerified($user);
            $amlScreeningService->assertProfileClear($user->kycProfile);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $submission = DB::transaction(function () use ($request, $user, $provider, $validated): KycProviderSubmission {
            $submission = KycProviderSubmission::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'provider_id' => $provider->id,
                ],
                [
                    'kyc_profile_id' => $user->kycProfile?->id,
                    'status' => 'approved',
                    'reviewed_by_user_id' => $request->user()?->id,
                    'reviewed_at' => now(),
                    'approved_at' => now(),
                    'rejected_at' => null,
                    'review_note' => $validated['review_note'] ?? null,
                    'rejection_reason' => null,
                    'failure_reason' => null,
                    'metadata' => $validated['metadata'] ?? null,
                ]
            );

            $this->audit($request, 'kyc_provider_submission.approved', $submission);

            return $submission->fresh(['user', 'kycProfile', 'provider', 'providerAccount', 'reviewedBy']);
        });

        return response()->json([
            'message' => "{$provider->name} KYC submission approved for internal release.",
            'provider' => $provider->only(['id', 'code', 'name', 'status']),
            'kyc_provider_submission' => $submission,
        ]);
    }

    public function reject(Request $request, User $user, IntegrationProvider $provider): JsonResponse
    {
        $user = $this->resolveManageableUser($user);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
            'review_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $submission = DB::transaction(function () use ($request, $user, $provider, $validated): KycProviderSubmission {
            $submission = KycProviderSubmission::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'provider_id' => $provider->id,
                ],
                [
                    'kyc_profile_id' => $user->kycProfile?->id,
                    'status' => 'rejected',
                    'reviewed_by_user_id' => $request->user()?->id,
                    'reviewed_at' => now(),
                    'approved_at' => null,
                    'rejected_at' => now(),
                    'review_note' => $validated['review_note'] ?? null,
                    'rejection_reason' => $validated['rejection_reason'],
                    'failure_reason' => null,
                ]
            );

            $this->audit($request, 'kyc_provider_submission.rejected', $submission);

            return $submission->fresh(['user', 'kycProfile', 'provider', 'providerAccount', 'reviewedBy']);
        });

        return response()->json([
            'message' => "{$provider->name} KYC submission rejected for internal release.",
            'provider' => $provider->only(['id', 'code', 'name', 'status']),
            'kyc_provider_submission' => $submission,
        ]);
    }

    private function ensureInternalKycVerified(User $user): void
    {
        $normalizedUserStatus = strtolower((string) $user->kyc_status);
        $normalizedProfileStatus = strtolower((string) $user->kycProfile?->status);

        if (
            ! in_array($normalizedUserStatus, ['verified', 'approved'], true) ||
            ! in_array($normalizedProfileStatus, ['verified', 'approved'], true)
        ) {
            throw new RuntimeException('User internal KYC must be verified before approving provider submission.');
        }
    }

    private function audit(Request $request, string $action, KycProviderSubmission $submission): void
    {
        AuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'entity_type' => 'kyc_provider_submission',
            'entity_id' => (string) $submission->id,
            'old_data' => null,
            'new_data' => $submission->fresh()->toArray(),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);
    }

    private function resolveManageableUser(User $user): User
    {
        $user->loadMissing('roles');

        abort_if($user->isAdmin(), 404);

        return $user;
    }
}
