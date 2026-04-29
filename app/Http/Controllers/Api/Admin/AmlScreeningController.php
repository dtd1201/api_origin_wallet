<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AmlScreening;
use App\Models\User;
use App\Services\Aml\AmlScreeningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AmlScreeningController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['pending', 'clear', 'potential_match', 'manual_clear', 'confirmed_match', 'failed', 'superseded'])],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
        ]);

        $screenings = AmlScreening::query()
            ->with(['user', 'kycProfile', 'matches', 'reviewedBy'])
            ->whereHas('user', fn (Builder $query) => $query->nonAdmin())
            ->when(
                isset($validated['status']),
                fn (Builder $query) => $query->where('status', $validated['status'])
            )
            ->when(
                isset($validated['user_id']),
                fn (Builder $query) => $query->where('user_id', $validated['user_id'])
            )
            ->latest('updated_at')
            ->paginate(15);

        return response()->json($screenings);
    }

    public function show(AmlScreening $amlScreening): JsonResponse
    {
        $amlScreening->load(['user.roles', 'kycProfile', 'matches.resolvedBy', 'reviewedBy']);

        abort_if($amlScreening->user?->isAdmin(), 404);

        return response()->json($amlScreening);
    }

    public function runForUser(User $user, AmlScreeningService $amlScreeningService): JsonResponse
    {
        $user = $this->resolveManageableUser($user)->load('kycProfile.relatedPersons');
        $kycProfile = $user->kycProfile;

        abort_if($kycProfile === null, 404, 'No KYC/KYB profile found for this user.');

        $screenings = $amlScreeningService->runProfile($kycProfile);

        return response()->json([
            'message' => 'AML screening completed.',
            'user' => $user->fresh(),
            'aml_screenings' => $screenings,
        ]);
    }

    public function clear(Request $request, AmlScreening $amlScreening, AmlScreeningService $amlScreeningService): JsonResponse
    {
        $this->abortIfAdminSubject($amlScreening);

        $validated = $request->validate([
            'review_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $screening = $amlScreeningService->manualClear(
            screening: $amlScreening,
            reviewer: $request->user(),
            reviewNote: $validated['review_note'] ?? null,
        );

        return response()->json([
            'message' => 'AML screening manually cleared.',
            'aml_screening' => $screening,
        ]);
    }

    public function confirmMatch(Request $request, AmlScreening $amlScreening, AmlScreeningService $amlScreeningService): JsonResponse
    {
        $this->abortIfAdminSubject($amlScreening);

        $validated = $request->validate([
            'review_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $screening = $amlScreeningService->confirmMatch(
            screening: $amlScreening,
            reviewer: $request->user(),
            reviewNote: $validated['review_note'] ?? null,
        );

        return response()->json([
            'message' => 'AML screening match confirmed.',
            'aml_screening' => $screening,
        ]);
    }

    private function resolveManageableUser(User $user): User
    {
        $user->loadMissing('roles');

        abort_if($user->isAdmin(), 404);

        return $user;
    }

    private function abortIfAdminSubject(AmlScreening $amlScreening): void
    {
        $amlScreening->loadMissing('user.roles');

        abort_if($amlScreening->user?->isAdmin(), 404);
    }
}
