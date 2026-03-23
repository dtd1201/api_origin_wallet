<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\LoginVerificationCodeMail;
use App\Mail\PasswordResetCodeMail;
use App\Mail\RegistrationVerificationCodeMail;
use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\PendingLogin;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Services\Auth\GoogleTokenVerifier;
use App\Services\Integrations\ProviderOnboardingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc,dns', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $verificationCode = DB::transaction(function () use ($validated): string {
            $verificationCode = $this->generateVerificationCode();
            $activationUrl = URL::temporarySignedRoute(
                'auth.register.activate',
                now()->addMinutes(15),
                [
                    'email' => $validated['email'],
                    'code' => $verificationCode,
                ]
            );

            PendingRegistration::query()->updateOrCreate(
                ['email' => $validated['email']],
                [
                    'phone' => $validated['phone'] ?? null,
                    'full_name' => $validated['full_name'] ?? null,
                    'password_hash' => Hash::make($validated['password']),
                    'verification_code' => $verificationCode,
                    'expires_at' => now()->addMinutes(15),
                    'verified_at' => null,
                ]
            );

            Mail::to($validated['email'])->send(
                new RegistrationVerificationCodeMail(
                    fullName: (string) ($validated['full_name'] ?? ''),
                    verificationCode: $verificationCode,
                    expiresInMinutes: 15,
                    activationUrl: $activationUrl,
                )
            );
            return $verificationCode;
        });

        $response = [
            'message' => 'Verification link sent to email. Please verify to complete registration.',
            'email' => $validated['email'],
            'expires_in_minutes' => 15,
        ];

        if ((bool) config('mail.expose_verification_code', false)) {
            $response['verification_code'] = $verificationCode;
        }

        return response()->json($response, 202);
    }

    public function verifyRegistration(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'verification_code' => ['required', 'digits:6'],
        ]);

        [$user, $payload] = DB::transaction(function () use ($validated, $request): array {
            $pendingRegistration = PendingRegistration::query()
                ->where('email', $validated['email'])
                ->lockForUpdate()
                ->first();

            abort_if($pendingRegistration === null, 422, 'No pending registration found for this email.');
            abort_if($pendingRegistration->expires_at->isPast(), 422, 'Verification code has expired.');
            abort_if($pendingRegistration->verification_code !== $validated['verification_code'], 422, 'Invalid verification code.');

            $user = User::create([
                'email' => $pendingRegistration->email,
                'phone' => $pendingRegistration->phone,
                'full_name' => $pendingRegistration->full_name,
                'password_hash' => $pendingRegistration->password_hash,
                'status' => 'pending',
                'kyc_status' => 'pending',
            ]);

            $token = $this->issueToken($user, (string) $request->userAgent());
            $pendingRegistration->delete();

            return [
                $user,
                $this->buildAuthPayload($user->fresh('profile', 'providerAccounts.provider'), $token),
            ];
        });

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

        [$user, $payload] = DB::transaction(function () use ($validated, $request): array {
            $pendingRegistration = PendingRegistration::query()
                ->where('email', $validated['email'])
                ->lockForUpdate()
                ->first();

            abort_if($pendingRegistration === null, 422, 'No pending registration found for this email.');
            abort_if($pendingRegistration->expires_at->isPast(), 422, 'Verification link has expired.');
            abort_if($pendingRegistration->verification_code !== $validated['code'], 422, 'Verification link is invalid.');

            $user = User::create([
                'email' => $pendingRegistration->email,
                'phone' => $pendingRegistration->phone,
                'full_name' => $pendingRegistration->full_name,
                'password_hash' => $pendingRegistration->password_hash,
                'status' => 'pending',
                'kyc_status' => 'pending',
            ]);

            $token = $this->issueToken($user, (string) $request->userAgent());
            $pendingRegistration->delete();

            return [
                $user,
                $this->buildAuthPayload($user->fresh('profile', 'providerAccounts.provider'), $token),
            ];
        });

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
            ->with(['profile', 'providerAccounts.provider'])
            ->where('email', $validated['email'])
            ->first();

        if ($user === null || ! Hash::check($validated['password'], $user->password_hash)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 422);
        }

        $verificationCode = DB::transaction(function () use ($user): string {
            $verificationCode = $this->generateVerificationCode();

            PendingLogin::query()->updateOrCreate(
                ['email' => $user->email],
                [
                    'user_id' => $user->id,
                    'verification_code' => $verificationCode,
                    'expires_at' => now()->addMinutes(15),
                ]
            );

            Mail::to($user->email)->send(
                new LoginVerificationCodeMail(
                    fullName: (string) ($user->full_name ?? ''),
                    verificationCode: $verificationCode,
                    expiresInMinutes: 15,
                )
            );

            return $verificationCode;
        });

        $response = [
            'message' => 'Verification code sent to email. Please verify to complete login.',
            'email' => $user->email,
            'expires_in_minutes' => 15,
        ];

        if ((bool) config('mail.expose_verification_code', false)) {
            $response['verification_code'] = $verificationCode;
        }

        return response()->json($response, 202);
    }

    public function verifyLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'verification_code' => ['required', 'digits:6'],
        ]);

        [$user, $payload] = DB::transaction(function () use ($validated, $request): array {
            $pendingLogin = PendingLogin::query()
                ->where('email', $validated['email'])
                ->lockForUpdate()
                ->first();

            abort_if($pendingLogin === null, 422, 'No pending login found for this email.');
            abort_if($pendingLogin->expires_at->isPast(), 422, 'Verification code has expired.');
            abort_if($pendingLogin->verification_code !== $validated['verification_code'], 422, 'Invalid verification code.');

            $user = User::query()
                ->with(['profile', 'providerAccounts.provider'])
                ->find($pendingLogin->user_id);

            abort_if($user === null, 422, 'User account no longer exists.');

            $token = $this->issueToken($user, (string) $request->userAgent());
            $pendingLogin->delete();

            return [
                $user,
                $this->buildAuthPayload($user, $token),
            ];
        });

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

        $user = User::query()
            ->where('email', $validated['email'])
            ->first();

        $response = [
            'message' => 'If the email exists in our system, a password reset code has been sent.',
            'email' => $validated['email'],
            'expires_in_minutes' => $this->passwordResetExpiryMinutes(),
        ];

        if ($user === null) {
            return response()->json($response, 202);
        }

        $verificationCode = DB::transaction(function () use ($user): string {
            $passwordReset = DB::table('password_reset_tokens')
                ->where('email', $user->email)
                ->lockForUpdate()
                ->first();

            if ($this->passwordResetRequestIsThrottled($passwordReset)) {
                abort(429, 'Please wait before requesting another password reset code.');
            }

            $verificationCode = $this->generateVerificationCode();

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => Hash::make($verificationCode),
                    'created_at' => now(),
                ]
            );

            Mail::to($user->email)->send(
                new PasswordResetCodeMail(
                    fullName: (string) ($user->full_name ?? ''),
                    verificationCode: $verificationCode,
                    expiresInMinutes: $this->passwordResetExpiryMinutes(),
                )
            );

            return $verificationCode;
        });

        if ((bool) config('mail.expose_verification_code', false)) {
            $response['verification_code'] = $verificationCode;
        }

        return response()->json($response, 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'verification_code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        DB::transaction(function () use ($validated): void {
            /** @var User|null $user */
            $user = User::query()
                ->where('email', $validated['email'])
                ->lockForUpdate()
                ->first();

            abort_if($user === null, 422, 'No user found for this email.');

            $passwordReset = DB::table('password_reset_tokens')
                ->where('email', $validated['email'])
                ->lockForUpdate()
                ->first();

            abort_if($passwordReset === null, 422, 'No pending password reset found for this email.');
            abort_if($this->passwordResetTokenIsExpired($passwordReset), 422, 'Password reset code has expired.');
            abort_if(! Hash::check($validated['verification_code'], $passwordReset->token), 422, 'Invalid password reset code.');

            $user->forceFill([
                'password_hash' => Hash::make($validated['password']),
            ])->save();

            $user->apiTokens()->delete();

            DB::table('password_reset_tokens')
                ->where('email', $validated['email'])
                ->delete();
        });

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
                ->with(['profile', 'providerAccounts.provider'])
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

                $user->load(['profile', 'providerAccounts.provider']);
            } elseif (
                blank($user->full_name)
                && $googleUser['full_name'] !== ''
            ) {
                $user->forceFill([
                    'full_name' => $googleUser['full_name'],
                ])->save();

                $user->load(['profile', 'providerAccounts.provider']);
            }

            $token = $this->issueToken($user, (string) $request->userAgent());

            return [
                $user,
                $this->buildAuthPayload($user, $token),
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
        $user = $request->user()->load(['profile', 'providerAccounts.provider']);

        return response()->json($this->buildAuthPayload($user));
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var ApiToken|null $token */
        $token = $request->attributes->get('apiToken');

        DB::transaction(function () use ($token): void {
            if ($token !== null) {
                $token->delete();
            }
        });

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function updateProfile(Request $request, ProviderOnboardingManager $onboardingManager): JsonResponse
    {
        /** @var User $user */
        $user = $request->user()->load(['profile', 'providerAccounts.provider']);

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

            return $user->fresh(['profile', 'providerAccounts.provider']);
        });

        $providerSyncMessage = null;

        if ($selectedProvider !== null) {
            try {
                $onboardingManager->linkUser($selectedProvider, $user->fresh(['profile', 'providerAccounts.provider']), true);
                $providerSyncMessage = "Profile submitted and {$selectedProvider->name} onboarding request sent successfully.";
            } catch (Throwable $exception) {
                report($exception);
                $providerSyncMessage = $exception instanceof \RuntimeException
                    ? $exception->getMessage()
                    : "Profile submitted successfully, but {$selectedProvider->name} onboarding could not be started yet.";
            }
        }

        $payload = $this->buildAuthPayload($user->fresh(['profile', 'providerAccounts.provider']));

        return response()->json([
            'message' => $providerSyncMessage ?? 'Profile submitted successfully.',
            ...$payload,
        ]);
    }

    private function issueToken(User $user, string $tokenName): string
    {
        $plainToken = Str::random(80);

        $user->apiTokens()->create([
            'name' => Str::limit($tokenName, 255, ''),
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDays(30),
        ]);

        return $plainToken;
    }

    private function buildAuthPayload(User $user, ?string $plainToken = null): array
    {
        $user->loadMissing(['profile', 'providerAccounts.provider']);

        $profileCompleted = $user->profile !== null && filled($user->profile->user_type);
        $providerAccounts = $user->providerAccounts
            ->filter(fn ($account) => $account->provider !== null)
            ->values();
        $latestProviderAccount = $providerAccounts->sortByDesc('id')->first();
        $providerStatuses = $providerAccounts
            ->mapWithKeys(fn ($account) => [
                (string) $account->provider->code => [
                    'provider_id' => $account->provider_id,
                    'provider_name' => $account->provider->name,
                    'status' => $account->status,
                    'external_account_id' => $account->external_account_id,
                ],
            ])
            ->all();
        $selectedProviderCode = $latestProviderAccount?->provider?->code;
        $selectedProviderStatus = $latestProviderAccount?->status ?? ($profileCompleted ? 'awaiting_provider_selection' : 'not_started');

        return array_filter([
            'token' => $plainToken,
            'token_type' => $plainToken !== null ? 'Bearer' : null,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'phone' => $user->phone,
                'full_name' => $user->full_name,
                'status' => $user->status,
                'kyc_status' => $user->kyc_status,
                'profile' => $user->profile,
            ],
            'onboarding' => [
                'profile_completed' => $profileCompleted,
                'selected_provider_code' => $selectedProviderCode,
                'selected_provider_account_status' => $selectedProviderStatus,
                'provider_account_statuses' => $providerStatuses,
                'next_action' => ! $profileCompleted
                    ? 'complete_user_profile'
                    : ($selectedProviderCode === null ? 'select_provider' : null),
                'message' => $profileCompleted
                    ? ($selectedProviderCode !== null
                        ? 'Profile received. Account and KYC status remain pending until provider onboarding is completed.'
                        : 'Profile received. Select a provider to start account onboarding.')
                    : 'Login successful. User must complete profile before using wallet features.',
            ],
            'providers' => IntegrationProvider::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'status'])
                ->map(fn (IntegrationProvider $provider) => $this->transformProvider($provider))
                ->values()
                ->all(),
        ], static fn ($value) => $value !== null);
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

    private function transformProvider(IntegrationProvider $provider): array
    {
        $providerConfig = (array) config('integrations.providers.'.strtolower($provider->code), []);

        $supportsOnboarding = isset($providerConfig['onboarding']);
        $supportsBeneficiaries = isset($providerConfig['beneficiary']);
        $supportsDataSync = isset($providerConfig['data_sync']);
        $supportsQuotes = isset($providerConfig['quote']);
        $supportsTransfers = isset($providerConfig['transfer']);
        $supportsWebhooks = isset($providerConfig['webhook']);

        return [
            'id' => $provider->id,
            'code' => $provider->code,
            'name' => $provider->name,
            'status' => $provider->status,
            'is_available_for_onboarding' => $provider->status === 'active' && $supportsOnboarding,
            'supports_beneficiaries' => $supportsBeneficiaries,
            'supports_data_sync' => $supportsDataSync,
            'supports_quotes' => $supportsQuotes,
            'supports_transfers' => $supportsTransfers,
            'supports_webhooks' => $supportsWebhooks,
        ];
    }

    private function generateVerificationCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function passwordResetExpiryMinutes(): int
    {
        return (int) config('auth.passwords.users.expire', 60);
    }

    private function passwordResetThrottleSeconds(): int
    {
        return (int) config('auth.passwords.users.throttle', 60);
    }

    private function passwordResetTokenIsExpired(object $passwordReset): bool
    {
        return Carbon::parse($passwordReset->created_at)
            ->addMinutes($this->passwordResetExpiryMinutes())
            ->isPast();
    }

    private function passwordResetRequestIsThrottled(?object $passwordReset): bool
    {
        if ($passwordReset === null) {
            return false;
        }

        return Carbon::parse($passwordReset->created_at)
            ->addSeconds($this->passwordResetThrottleSeconds())
            ->isFuture();
    }
}
