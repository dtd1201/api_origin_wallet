<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\IntegrationProvider;
use App\Models\NiumVirtualAccountDetailEnrichment;
use App\Models\NiumVirtualAccountOperation;
use App\Models\User;
use App\Models\UserIntegrationRequest;
use App\Models\UserProviderAccount;
use App\Services\Integrations\IntegrationProviderCatalog;
use App\Services\Integrations\ProviderOnboardingEligibilityException;
use App\Services\Integrations\ProviderOnboardingManager;
use App\Services\Nium\NiumPaymentIdService;
use App\Services\Nium\NiumProviderAccountStateService;
use App\Services\Nium\NiumProviderRequestException;
use App\Services\Nium\NiumVirtualAccountOperationProjection;
use App\Support\PrimaryProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProviderAccountController extends Controller
{
    public function index(
        User $user,
        IntegrationProviderCatalog $providerCatalog,
        NiumProviderAccountStateService $accountStateService,
    ): JsonResponse {
        $user->load([
            'providerAccounts.provider',
            'integrationLinks.provider',
            'integrationRequests.provider',
            'kycProviderSubmissions.provider',
        ]);

        $providers = $providerCatalog->activeProviders();

        return response()->json([
            'data' => $providers
                ->filter(fn (IntegrationProvider $provider) => $provider->supportsOnboarding() || PrimaryProvider::isPrimary($provider))
                ->map(function (IntegrationProvider $provider) use ($user, $accountStateService): array {
                    $integrationLink = $user->integrationLinks->firstWhere('provider_id', $provider->id);
                    $integrationRequest = $user->integrationRequests->firstWhere('provider_id', $provider->id);
                    $kycProviderSubmission = $user->kycProviderSubmissions->firstWhere('provider_id', $provider->id);
                    $providerAccount = $user->providerAccounts
                        ->where('provider_id', $provider->id)
                        ->sortByDesc('id')
                        ->first();
                    $linkAvailable = $provider->isAvailableForOnboarding()
                        && (
                            strtolower((string) $provider->code) === 'nium' ||
                            ($integrationLink !== null && $integrationLink->is_active && filled($integrationLink->link_url))
                        );
                    $internalKycVerified = in_array(strtolower((string) $user->kyc_status), ['verified', 'approved'], true);
                    $providerSubmissionReady = in_array($kycProviderSubmission?->status, ['pending', 'submitted'], true);
                    $virtualAccountEligibility = PrimaryProvider::isPrimary($provider)
                        ? $accountStateService->virtualAccountEligibility($user)
                        : [
                            'can_request_virtual_account' => false,
                            'reason' => 'unsupported_provider',
                        ];

                    return [
                        'provider' => $provider->summaryPayload(),
                        'provider_account' => $providerAccount,
                        'integration_link' => $integrationLink,
                        'integration_request' => $integrationRequest,
                        'kyc_provider_submission' => $kycProviderSubmission,
                        'internal_kyc_verified' => $internalKycVerified,
                        'provider_submission_ready' => $providerSubmissionReady,
                        'link_available' => $linkAvailable,
                        'can_connect' => $linkAvailable && $internalKycVerified && $providerSubmissionReady,
                        'can_request_connect' => ! $linkAvailable,
                        'request_pending' => $integrationRequest?->status === 'pending',
                        'can_request_virtual_account' => $virtualAccountEligibility['can_request_virtual_account'],
                        'virtual_account_eligibility_reason' => $virtualAccountEligibility['reason'],
                    ];
                })->values()->all(),
        ]);
    }

    public function show(
        Request $request,
        User $user,
        IntegrationProvider $provider,
        NiumVirtualAccountOperationProjection $operations,
    ): JsonResponse {
        abort_unless(PrimaryProvider::isPrimary($provider), 404);

        $filters = $request->validate([
            'operations_before_id' => ['sometimes', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'operations_unresolved_only' => ['sometimes', 'boolean'],
            'provider_account_id' => ['sometimes', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'operation_id' => ['sometimes', 'string', 'max:255'],
        ]);
        $operationDiscovery = $operations->discover($user, $provider, $filters);

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
            && (
                strtolower((string) $provider->code) === 'nium' ||
                ($integrationLink !== null && $integrationLink->is_active && filled($integrationLink->link_url))
            );
        $internalKycVerified = in_array(strtolower((string) $user->kyc_status), ['verified', 'approved'], true);
        $providerSubmissionReady = in_array($kycProviderSubmission?->status, ['pending', 'submitted'], true);

        if ($providerAccount === null) {
            return response()->json([
                'provider' => $provider->summaryPayload(),
                'provider_account' => null,
                ...$operationDiscovery,
                'integration_link' => $integrationLink,
                'integration_request' => $integrationRequest,
                'kyc_provider_submission' => $kycProviderSubmission,
                'internal_kyc_verified' => $internalKycVerified,
                'provider_submission_ready' => $providerSubmissionReady,
                'link_available' => $linkAvailable,
                'can_connect' => $linkAvailable && $internalKycVerified && $providerSubmissionReady,
                'can_request_connect' => ! $linkAvailable,
                'request_pending' => $integrationRequest?->status === 'pending',
            ]);
        }

        return response()->json([
            'provider' => $provider->summaryPayload(),
            'provider_account' => $providerAccount,
            ...$operationDiscovery,
            'integration_link' => $integrationLink,
            'integration_request' => $integrationRequest,
            'kyc_provider_submission' => $kycProviderSubmission,
            'internal_kyc_verified' => $internalKycVerified,
            'provider_submission_ready' => $providerSubmissionReady,
            'link_available' => $linkAvailable,
            'can_connect' => $linkAvailable && $internalKycVerified && $providerSubmissionReady,
            'can_request_connect' => ! $linkAvailable,
            'request_pending' => $integrationRequest?->status === 'pending',
        ]);
    }

    public function requestConnect(Request $request, User $user, IntegrationProvider $provider): JsonResponse
    {
        abort_unless(PrimaryProvider::isPrimary($provider), 404);

        $validated = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $user->loadMissing(['integrationLinks', 'integrationRequests']);

        $integrationLink = $user->integrationLinks->firstWhere('provider_id', $provider->id);
        $linkAvailable = $provider->isAvailableForOnboarding()
            && (
                strtolower((string) $provider->code) === 'nium' ||
                ($integrationLink !== null && $integrationLink->is_active && filled($integrationLink->link_url))
            );

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
            'message' => 'Nium account setup request submitted successfully.',
            'provider' => $provider->summaryPayload(),
            'integration_request' => $integrationRequest,
            'request_pending' => true,
        ], 202);
    }

    public function virtualAccounts(Request $request, User $user, IntegrationProvider $provider): JsonResponse
    {
        abort_unless(PrimaryProvider::isPrimary($provider), 404);

        // An explicit account ID must still belong to this user/provider. The schema
        // permits one row per pair; this filter does not add account-history support.
        $filters = $request->validate([
            'provider_account_id' => ['sometimes', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
        ]);
        $account = UserProviderAccount::query()
            ->where('user_id', $user->id)
            ->where('provider_id', $provider->id)
            ->when(isset($filters['provider_account_id']), fn ($q) => $q->whereKey($filters['provider_account_id']))
            ->latest('id')
            ->first();

        $virtualAccounts = $account?->niumVirtualAccounts()->with('detail')->latest('id')->get() ?? collect();
        // Select only safe task fields. Never serialize the enrollment/claim model.
        $enrichments = NiumVirtualAccountDetailEnrichment::query()
            ->whereIn('nium_virtual_account_id', $virtualAccounts->pluck('id')->all())
            ->get(['nium_virtual_account_id', 'state', 'available_at', 'updated_at'])
            ->keyBy('nium_virtual_account_id');

        return response()->json([
            'data' => $virtualAccounts->map(function ($va) use ($enrichments): array {
                $task = $enrichments->get($va->id);

                return [
                    ...$va->toArray(),
                    'enrichment' => [
                        'status' => $task?->state,
                        // Running work has no scheduled next attempt; terminal work has none.
                        'next_attempt_at' => $task?->state === 'pending' ? $task->available_at?->toISOString() : null,
                        'updated_at' => $task?->updated_at?->toISOString(),
                    ],
                ];
            })->values()->all(),
        ]);
    }

    public function assignVirtualAccount(
        Request $request,
        User $user,
        IntegrationProvider $provider,
        NiumPaymentIdService $service,
        NiumVirtualAccountOperationProjection $operations,
    ): JsonResponse {
        abort_unless(PrimaryProvider::isPrimary($provider), 404);

        $validated = $request->validate([
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'account_category' => ['required', 'string', 'in:SELF_FUNDING_ACCOUNT,COLLECTION_ACCOUNT,SELF_FUNDING_AND_COLLECTION_ACCOUNT'],
            'account_type' => ['required', 'string', 'in:LOCAL,WIRES,LOCAL_AND_WIRES'],
            'expected_provider_account_id' => ['sometimes', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
        ]);
        $operationId = trim((string) $request->header('Idempotency-Key'));
        if ($operationId === '') {
            return response()->json([
                'message' => 'The Idempotency-Key header is required.',
            ], 422);
        }
        if (strlen($operationId) > 100 || preg_match('/^[A-Za-z0-9._:-]+$/', $operationId) !== 1) {
            return response()->json([
                'message' => 'The Idempotency-Key header format is invalid.',
            ], 422);
        }

        $account = UserProviderAccount::query()
            ->where('user_id', $user->id)
            ->where('provider_id', $provider->id)
            ->latest('id')
            ->firstOrFail();

        if (isset($validated['expected_provider_account_id'])
            && (int) $validated['expected_provider_account_id'] !== (int) $account->id) {
            return response()->json([
                'message' => 'The selected provider account has changed. Refresh the account and operation status before continuing.',
                'code' => 'PROVIDER_ACCOUNT_MISMATCH',
            ], 409);
        }

        try {
            $virtualAccount = $service->assign(
                $account,
                $validated['currency'],
                $validated['account_category'],
                $validated['account_type'],
                null,
                $operationId,
                autoSelectBank: true,
            );
        } catch (NiumProviderRequestException $exception) {
            return $this->safeNiumErrorResponse($exception);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        if ($virtualAccount instanceof NiumVirtualAccountOperation) {
            return response()->json([
                'message' => 'Virtual account assignment remains unresolved.',
                'operation_id' => $operationId,
                'operation' => $operations->operation($virtualAccount),
                'virtual_account' => null,
            ], 202);
        }

        // Resolve by both account and caller key, never by a VA/payment ID or latest key.
        // Legacy INITIALIZED may still return 201: the stored operation remains pending.
        $operation = NiumVirtualAccountOperation::query()
            ->where('user_provider_account_id', $account->id)
            ->where('operation_id', $operationId)
            ->first();

        return response()->json([
            'message' => 'Virtual account assigned successfully.',
            'operation_id' => $operationId,
            'virtual_account' => $virtualAccount,
            ...($operation === null ? [] : ['operation' => $operations->operation($operation)]),
        ], 201);
    }

    public function link(
        Request $request,
        User $user,
        IntegrationProvider $provider,
        ProviderOnboardingManager $manager,
    ): JsonResponse {
        abort_unless(PrimaryProvider::isPrimary($provider), 404);

        try {
            $provider->assertSupportsCapability('onboarding');

            $onboarding = $manager->linkUser(
                provider: $provider,
                user: $user->load('profile', 'providerAccounts.provider'),
                force: (bool) $request->boolean('force', false),
            );
        } catch (ProviderOnboardingEligibilityException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                ...$exception->context(),
            ], 422);
        } catch (NiumProviderRequestException $exception) {
            return $this->safeNiumErrorResponse($exception);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => $onboarding->message,
            'provider' => $provider->summaryPayload(),
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
        abort_unless(PrimaryProvider::isPrimary($provider), 404);

        try {
            $provider->assertSupportsCapability('onboarding');

            $onboarding = $manager->completeUserOnboarding(
                provider: $provider,
                user: $user->load('profile', 'providerAccounts.provider'),
                payload: $request->all(),
            );
        } catch (ProviderOnboardingEligibilityException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                ...$exception->context(),
            ], 422);
        } catch (NiumProviderRequestException $exception) {
            return $this->safeNiumErrorResponse($exception);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => $onboarding->message,
            'provider' => $provider->summaryPayload(),
            'provider_account' => $onboarding->providerAccount,
            'onboarding' => $onboarding->toArray(),
        ]);
    }

    private function safeNiumErrorResponse(NiumProviderRequestException $exception): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $exception->getMessage(),
            'code' => $exception->providerCode,
            'field' => $exception->providerField,
            'path' => $exception->providerPath,
        ], static fn ($value): bool => $value !== null), 422);
    }
}
