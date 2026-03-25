<?php

namespace App\Services\Auth;

use App\Mail\LoginVerificationCodeMail;
use App\Models\ApiToken;
use App\Models\IntegrationProvider;
use App\Models\PendingLogin;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ApiAuthService
{
    public function issueToken(User $user, string $tokenName): string
    {
        $plainToken = Str::random(80);

        $user->apiTokens()->create([
            'name' => Str::limit($tokenName, 255, ''),
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDays(30),
        ]);

        return $plainToken;
    }

    public function startLogin(User $user): array
    {
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

        return $response;
    }

    public function completeLogin(string $email, string $verificationCode, string $tokenName, ?callable $afterUserResolved = null): array
    {
        return DB::transaction(function () use ($email, $verificationCode, $tokenName, $afterUserResolved): array {
            $pendingLogin = PendingLogin::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            abort_if($pendingLogin === null, 422, 'No pending login found for this email.');
            abort_if($pendingLogin->expires_at->isPast(), 422, 'Verification code has expired.');
            abort_if($pendingLogin->verification_code !== $verificationCode, 422, 'Invalid verification code.');

            $user = User::query()
                ->with(['profile', 'providerAccounts.provider', 'roles'])
                ->find($pendingLogin->user_id);

            abort_if($user === null, 422, 'User account no longer exists.');

            if ($afterUserResolved !== null) {
                $afterUserResolved($user);
            }

            $token = $this->issueToken($user, $tokenName);
            $pendingLogin->delete();

            return [
                $user,
                $this->buildAuthPayload($user, $token),
            ];
        });
    }

    public function buildAuthPayload(User $user, ?string $plainToken = null): array
    {
        $user->loadMissing(['profile', 'providerAccounts.provider', 'roles']);

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
                'roles' => $user->roles->pluck('role_code')->values()->all(),
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

    public function logout(?ApiToken $token): void
    {
        DB::transaction(function () use ($token): void {
            if ($token !== null) {
                $token->delete();
            }
        });
    }

    private function transformProvider(IntegrationProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'code' => $provider->code,
            'name' => $provider->name,
            'status' => $provider->status,
            'is_available_for_onboarding' => $provider->isAvailableForOnboarding(),
            'supports_beneficiaries' => $provider->supportsBeneficiaries(),
            'supports_data_sync' => $provider->supportsDataSync(),
            'supports_quotes' => $provider->supportsQuotes(),
            'supports_transfers' => $provider->supportsTransfers(),
            'supports_webhooks' => $provider->supportsWebhooks(),
            'is_configured' => $provider->isConfigured(),
        ];
    }

    private function generateVerificationCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
