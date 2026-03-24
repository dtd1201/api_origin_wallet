<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Auth\ApiAuthService;
use App\Services\Auth\GoogleTokenVerifier;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\RegistrationService;
use App\Services\Integrations\ProviderOnboardingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        private readonly ApiAuthService $apiAuthService,
        private readonly PasswordResetService $passwordResetService,
        private readonly RegistrationService $registrationService,
    ) {
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc,dns', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        return response()->json($this->registrationService->startRegistration($validated), 202);
    }

    public function verifyRegistration(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'verification_code' => ['required', 'digits:6'],
        ]);

        $user = $this->registrationService->completeRegistrationByCode(
            $validated['email'],
            $validated['verification_code']
        );
        $token = $this->apiAuthService->issueToken($user, (string) $request->userAgent());
        $payload = $this->apiAuthService->buildAuthPayload(
            $user->fresh(['profile', 'providerAccounts.provider', 'roles']),
            $token
        );

        return response()->json([
            'message' => 'Email verified successfully. Registration completed and user logged in. Account status remains pending until provider onboarding is completed.',
            ...$payload,
        ], 201);
    }

    public function activateRegistration(Request $request): JsonResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'Verification link is invalid or has expired.');

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]);

        $user = $this->registrationService->completeRegistrationByActivationLink(
            $validated['email'],
            $validated['code']
        );
        $token = $this->apiAuthService->issueToken($user, (string) $request->userAgent());
        $payload = $this->apiAuthService->buildAuthPayload(
            $user->fresh(['profile', 'providerAccounts.provider', 'roles']),
            $token
        );

        return response()->json([
            'message' => 'Email verified successfully. Registration completed and user logged in. Account status remains pending until provider onboarding is completed.',
            ...$payload,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->with(['profile', 'providerAccounts.provider', 'roles'])
            ->where('email', $validated['email'])
            ->first();

        if ($user === null || ! Hash::check($validated['password'], $user->password_hash)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 422);
        }

        return response()->json($this->apiAuthService->startLogin($user), 202);
    }

    public function verifyLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'verification_code' => ['required', 'digits:6'],
        ]);

        [, $payload] = $this->apiAuthService->completeLogin(
            $validated['email'],
            $validated['verification_code'],
            (string) $request->userAgent(),
        );

        return response()->json([
            'message' => 'Login successful.',
            ...$payload,
        ]);
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

        $this->passwordResetService->resetPassword($validated);

        return response()->json([
            'message' => 'Password reset successful. Please log in again with your new password.',
        ]);
    }

    public function googleLogin(Request $request, GoogleTokenVerifier $googleTokenVerifier): JsonResponse
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string'],
        ]);

        try {
            $googleUser = $googleTokenVerifier->verify($validated['id_token']);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        [$user, $payload, $isNewUser] = DB::transaction(function () use ($googleUser, $request): array {
            $user = User::query()
                ->with(['profile', 'providerAccounts.provider', 'roles'])
                ->where('email', $googleUser['email'])
                ->lockForUpdate()
                ->first();

            $isNewUser = false;

            if ($user === null) {
                $isNewUser = true;

                $user = User::create([
                    'email' => $googleUser['email'],
                    'full_name' => $googleUser['full_name'] !== '' ? $googleUser['full_name'] : null,
                    'password_hash' => Hash::make(Str::random(40)),
                    'status' => 'pending',
                    'kyc_status' => 'pending',
                ]);

                $user->load(['profile', 'providerAccounts.provider', 'roles']);
            } elseif (blank($user->full_name) && $googleUser['full_name'] !== '') {
                $user->forceFill([
                    'full_name' => $googleUser['full_name'],
                ])->save();

                $user->load(['profile', 'providerAccounts.provider', 'roles']);
            }

            $token = $this->apiAuthService->issueToken($user, (string) $request->userAgent());

            return [
                $user,
                $this->apiAuthService->buildAuthPayload($user, $token),
                $isNewUser,
            ];
        });

        return response()->json([
            'message' => $isNewUser
                ? 'Google login successful. New account created.'
                : 'Google login successful.',
            ...$payload,
        ], $isNewUser ? 201 : 200);
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
            'provider_code' => ['sometimes', 'string', 'exists:integration_providers,code'],
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

        if ($selectedProvider !== null) {
            try {
                $onboardingManager->linkUser($selectedProvider, $user->fresh(['profile', 'providerAccounts.provider', 'roles']), true);
                $providerSyncMessage = "Profile submitted and {$selectedProvider->name} onboarding request sent successfully.";
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
            ->where('code', $providerCode)
            ->first();
    }

}
