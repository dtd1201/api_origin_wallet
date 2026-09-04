<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycProviderSubmission;
use App\Models\User;
use App\Support\PrimaryProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KycProviderSubmissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['pending', 'approved', 'submitted', 'rejected', 'failed'])],
            'provider_code' => ['sometimes', 'string', Rule::in([PrimaryProvider::code()]), 'exists:integration_providers,code'],
        ]);

        $submissions = KycProviderSubmission::query()
            ->with(['user', 'kycProfile', 'provider', 'providerAccount', 'reviewedBy'])
            ->whereHas('user', fn (Builder $query) => $query->nonAdmin())
            ->whereHas('provider', fn (Builder $query) => $query->where('code', PrimaryProvider::code()))
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
                ->whereHas('provider', fn (Builder $query) => $query->where('code', PrimaryProvider::code()))
                ->latest('updated_at')
                ->get(),
        ]);
    }

    private function resolveManageableUser(User $user): User
    {
        $user->loadMissing('roles');

        abort_if($user->isAdmin(), 404);

        return $user;
    }
}
