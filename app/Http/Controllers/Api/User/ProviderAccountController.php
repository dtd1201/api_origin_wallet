<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserIntegrationRequest;
use App\Services\Integrations\ProviderOnboardingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProviderAccountController extends Controller
{
    public function index(User $user): JsonResponse
    {
        $user->load([
            'providerAccounts.provider',
            'integrationLinks.provider',
            'integrationRequests.provider',
            'kycProviderSubmissions.provider',
        ]);

        $providers = IntegrationProvider::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'status']);

        return response()->json([
            'data' => $providers
                ->filter(fn (IntegrationProvider $provider) => $provider->supportsOnboarding())
                ->map(function (IntegrationProvider $provider) use ($user): array {
                    $integrationLink = $user->integrationLinks->firstWhere('provider_id', $provider->id);
                    $integrationRequest = $user->integrationRequests->firstWhere('provider_id', $provider->id);
                    $kycProviderSubmission = $user->kycProviderSubmissions->firstWhere('provider_id', $provider->id);
                    $providerAccount = $user->providerAccounts
                        ->where('provider_id', $provider->id)
                        ->sortByDesc('id')
                        ->first();
                    $linkAvailable = $provider->isAvailableForOnboarding()
                        && $integrationLink !== null
                        && $integrationLink->is_active
                        && filled($integrationLink->link_url);
                    $internalKycVerified = in_array(strtolower((string) $user->kyc_status), ['verified', 'approved'], true);
                    $providerSubmissionApproved = in_array($kycProviderSubmission?->status, ['approved', 'submitted'], true);

                    return [
                        'provider' => $provider->only(['id', 'code', 'name', 'status']),
                        'provider_account' => $providerAccount,
                        'integration_link' => $integrationLink,
                        'integration_request' => $integrationRequest,
                        'kyc_provider_submission' => $kycProviderSubmission,
                        'internal_kyc_verified' => $internalKycVerified,
                        'provider_submission_approved' => $providerSubmissionApproved,
                        'link_available' => $linkAvailable,
                        'can_connect' => $linkAvailable && $internalKycVerified && $providerSubmissionApproved,
                        'can_request_connect' => ! $linkAvailable,
                        'request_pending' => $integrationRequest?->status === 'pending',
                    ];
                })->values()->all(),
        ]);
    }

    public function show(User $user, IntegrationProvider $provider): JsonResponse
    {
        if (! $provider->supportsOnboarding()) {
            abort(404);
        }

        $user->loadMissing([
            'integrationLinks.provider',
            'providerAccounts.provider',
            'integrationRequests.provider',
            'kycProviderSubmissions.provider',
        ]);

        $providerAccount = $user->providerAccounts()
            ->with('provider')
            ->where('provider_id', $provider->id)
            ->latest('id')
            ->first();
        $integrationLink = $user->integrationLinks->firstWhere('provider_id', $provider->id);
        $integrationRequest = $user->integrationRequests->firstWhere('provider_id', $provider->id);
        $kycProviderSubmission = $user->kycProviderSubmissions->firstWhere('provider_id', $provider->id);
        $linkAvailable = $provider->isAvailableForOnboarding()
            && $integrationLink !== null
            && $integrationLink->is_active
            && filled($integrationLink->link_url);
        $internalKycVerified = in_array(strtolower((string) $user->kyc_status), ['verified', 'approved'], true);
        $providerSubmissionApproved = in_array($kycProviderSubmission?->status, ['approved', 'submitted'], true);

        if ($providerAccount === null) {
            return response()->json([
                'provider' => $provider->only(['id', 'code', 'name', 'status']),
                'provider_account' => null,
                'integration_link' => $integrationLink,
                'integration_request' => $integrationRequest,
                'kyc_provider_submission' => $kycProviderSubmission,
                'internal_kyc_verified' => $internalKycVerified,
                'provider_submission_approved' => $providerSubmissionApproved,
                'link_available' => $linkAvailable,
                'can_connect' => $linkAvailable && $internalKycVerified && $providerSubmissionApproved,
                'can_request_connect' => ! $linkAvailable,
                'request_pending' => $integrationRequest?->status === 'pending',
            ]);
        }

        return response()->json([
            'provider' => $provider->only(['id', 'code', 'name', 'status']),
            'provider_account' => $providerAccount,
            'integration_link' => $integrationLink,
            'integration_request' => $integrationRequest,
            'kyc_provider_submission' => $kycProviderSubmission,
            'internal_kyc_verified' => $internalKycVerified,
            'provider_submission_approved' => $providerSubmissionApproved,
            'link_available' => $linkAvailable,
            'can_connect' => $linkAvailable && $internalKycVerified && $providerSubmissionApproved,
            'can_request_connect' => ! $linkAvailable,
            'request_pending' => $integrationRequest?->status === 'pending',
        ]);
    }

    public function requestConnect(Request $request, User $user, IntegrationProvider $provider): JsonResponse
    {
        if (! $provider->supportsOnboarding()) {
            return response()->json([
                'message' => 'This provider is not available for onboarding yet.',
            ], 422);
        }

        $validated = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $user->loadMissing(['integrationLinks', 'integrationRequests']);

        $integrationLink = $user->integrationLinks->firstWhere('provider_id', $provider->id);
        $linkAvailable = $provider->isAvailableForOnboarding()
            && $integrationLink !== null
            && $integrationLink->is_active
            && filled($integrationLink->link_url);

        if ($linkAvailable) {
            return response()->json([
                'message' => 'This provider is already available for connection.',
            ], 422);
        }

        $integrationRequest = DB::transaction(function () use ($user, $provider, $validated): UserIntegrationRequest {
            return UserIntegrationRequest::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'provider_id' => $provider->id,
                ],
                [
                    'status' => 'pending',
                    'note' => $validated['note'] ?? null,
                    'requested_at' => now(),
                    'resolved_at' => null,
                ]
            );
        });

        return response()->json([
            'message' => 'Provider connection request submitted successfully.',
            'provider' => $provider->only(['id', 'code', 'name', 'status']),
            'integration_request' => $integrationRequest,
            'request_pending' => true,
        ], 202);
    }

    public function link(
        Request $request,
        User $user,
        IntegrationProvider $provider,
        ProviderOnboardingManager $manager,
    ): JsonResponse {
        try {
            $provider->assertSupportsCapability('onboarding');

            $onboarding = $manager->linkUser(
                provider: $provider,
                user: $user->load('profile', 'providerAccounts.provider'),
                force: (bool) $request->boolean('force', false),
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => $onboarding->message,
            'provider' => $provider->only(['id', 'code', 'name', 'status']),
            'provider_account' => $onboarding->providerAccount,
            'onboarding' => $onboarding->toArray(),
        ]);
    }

    public function complete(
        Request $request,
        User $user,
        IntegrationProvider $provider,
        ProviderOnboardingManager $manager,
    ): JsonResponse {
        try {
            $provider->assertSupportsCapability('onboarding');

            $onboarding = $manager->completeUserOnboarding(
                provider: $provider,
                user: $user->load('profile', 'providerAccounts.provider'),
                payload: $request->all(),
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => $onboarding->message,
            'provider' => $provider->only(['id', 'code', 'name', 'status']),
            'provider_account' => $onboarding->providerAccount,
            'onboarding' => $onboarding->toArray(),
        ]);
    }
}
