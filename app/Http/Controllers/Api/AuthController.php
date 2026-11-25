<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Auth\ApiAuthService;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\RegistrationService;
use App\Services\Integrations\ProviderOnboardingManager;
use App\Support\PrimaryProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        private readonly ApiAuthService $apiAuthService,
        private readonly PasswordResetService $passwordResetService,
        private readonly RegistrationService $registrationService,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'client_binding' => ['required', 'string', 'regex:/\\A[0-9a-f]{64}\\z/D'],
        ]);

        return response()->json($this->registrationService->startRegistration($validated), 202);
    }

    public function verifyRegistration(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'verification_code' => ['required', 'string', 'digits:6'],
            'registration_version' => ['required', 'uuid'],
            'client_binding' => ['required', 'string', 'regex:/\\A[0-9a-f]{64}\\z/D'],
        ]);
        $this->registrationService->completeRegistrationByCode($validated['email'], $validated['verification_code'],
            $validated['registration_version'], $validated['client_binding']);

        return response()->json(['message' => 'Email verified. Sign in with your password to enroll your authenticator.',
            'next_action' => 'credentials_required'], 201);
    }

    public function activateRegistration(Request $request): JsonResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'Verification link is invalid or has expired.');
        $validated = $request->validate(['email' => ['required', 'email'],
            'code' => ['required', 'string', 'digits:6'], 'registration_version' => ['required', 'uuid']]);
        $this->registrationService->completeRegistrationByActivationLink(
            $validated['email'], $validated['code'], $validated['registration_version']);

        return response()->json(['message' => 'Email verified. Sign in with your password and retain your email verification code for authenticator enrollment.',
            'next_action' => 'credentials_required'], 201);
    }

    public function login(Request $request): JsonResponse
    {
        return app(MandatoryAuthController::class)->login($request);
    }

    public function verifyLogin(Request $request): JsonResponse
    {
        return app(MandatoryAuthController::class)->verify($request);
    }

    public function resendLogin(Request $request): JsonResponse
    {
        return app(MandatoryAuthController::class)->retired($request);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        return response()->json(
            $this->passwordResetService->sendResetCode($validated['email']),
            202
        );
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'verification_code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $this->passwordResetService->resetPassword(
            $validated,
            $request->ip(),
            (string) $request->userAgent(),
        );

        return response()->json([
            'message' => 'Password reset successful. Please log in again with your new password.',
        ]);
    }

    public function googleLogin(Request $request): JsonResponse
    {
        // No ID-token parsing, provider calls, account creation or enrollment authority.
        return response()->json(['message' => 'Use email and password, then your authenticator, to sign in.',
            'next_action' => 'credentials_required'], 410);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user()->load(['profile', 'providerAccounts.provider', 'roles']);

        return response()->json($this->apiAuthService->buildAuthPayload($user));
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var ApiToken|null $token */
        $token = $request->attributes->get('apiToken');

        $this->apiAuthService->logout($token);

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function updateProfile(Request $request, ProviderOnboardingManager $onboardingManager): JsonResponse
    {
        /** @var User $user */
        $user = $request->user()->load(['profile', 'providerAccounts.provider', 'roles']);

        $validated = $request->validate([
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'provider_code' => ['sometimes', 'string', Rule::in([PrimaryProvider::code()]), 'exists:integration_providers,code'],
            'profile.user_type' => ['required', 'string', 'max:20'],
            'profile.country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'profile.company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.company_reg_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.tax_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.postal_code' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        $selectedProvider = $this->resolveRequestedProvider($validated['provider_code'] ?? null);

        $user = DB::transaction(function () use ($user, $validated, $selectedProvider): User {
            $user->fill(collect($validated)->except(['profile', 'provider_code'])->all());
            $user->save();

            $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                $validated['profile']
            );

            if ($selectedProvider !== null) {
                $user->providerAccounts()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'provider_id' => $selectedProvider->id,
                        'external_account_id' => null,
                    ],
                    [
                        'status' => 'pending',
                        'metadata' => [
                            'integration_status' => 'awaiting_provider_details',
                            'selected_provider_code' => $selectedProvider->code,
                            'profile_submitted_at' => now()->toISOString(),
                        ],
                    ]
                );
            }

            return $user->fresh(['profile', 'providerAccounts.provider', 'roles']);
        });

        $providerSyncMessage = null;

        if ($selectedProvider !== null && ! PrimaryProvider::isPrimary($selectedProvider)) {
            try {
                $onboarding = $onboardingManager->linkUser($selectedProvider, $user->fresh(['profile', 'providerAccounts.provider', 'roles']), true);
                $providerSyncMessage = "Profile submitted. {$onboarding->message}";
            } catch (Throwable $exception) {
                report($exception);
                $providerSyncMessage = $exception instanceof \RuntimeException
                    ? $exception->getMessage()
                    : "Profile submitted successfully, but {$selectedProvider->name} onboarding could not be started yet.";
            }
        }

        $payload = $this->apiAuthService->buildAuthPayload($user->fresh(['profile', 'providerAccounts.provider', 'roles']));

        return response()->json([
            'message' => $providerSyncMessage ?? 'Profile submitted successfully.',
            ...$payload,
        ]);
    }

    private function resolveRequestedProvider(?string $providerCode): ?IntegrationProvider
    {
        if ($providerCode === null || $providerCode === '') {
            return null;
        }

        return IntegrationProvider::query()
            ->where('code', PrimaryProvider::code())
            ->first();
    }
}
