<?php

namespace App\Services\Auth;

use App\Models\ApiToken;
use App\Models\AuthChallenge;
use App\Models\User;
use App\Models\UserAuthFactor;
use App\Services\Integrations\IntegrationProviderCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApiAuthService
{
    public function __construct(
        private readonly IntegrationProviderCatalog $providerCatalog,
        private readonly AuthChallengeService $challenges,
        private readonly TotpService $totp,
        private readonly AuthSecurity $security,
    ) {}

    /**
     * Only persisted, consumed, TOTP-proven completion records can issue once.
     * No caller-provided boolean or synthetic User field conveys MFA assurance.
     * Caller already holds User → factor → challenge inside the same transaction.
     */
    public function issueToken(User $user, AuthChallenge $completion, string $tokenName): string
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('MFA issuance requires a completion transaction.');
        }
        $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        $factor = $this->challenges->lockFactor($lockedUser);
        $proof = AuthChallenge::whereKey($completion->id)->lockForUpdate()->firstOrFail();
        abort_unless($factor !== null && $factor->confirmed_at !== null
            && $proof->user_id === $lockedUser->id && $proof->consumed_at !== null && $proof->revoked_at === null
            && $proof->token_issued_at === null && $proof->expires_at->gt(now()) && $proof->ceremony_deadline->gt(now())
            && in_array($proof->purpose, ['login_totp', 'enrollment_setup', 'replacement_setup'], true)
            && in_array($proof->audience, ['user', 'admin'], true)
            && ($proof->audience !== 'admin' || $lockedUser->isAdmin())
            && $proof->auth_epoch === $lockedUser->auth_epoch && (int) $proof->factor_id === $factor->id
            && $proof->factor_version === $factor->version && $proof->verified_counter !== null
            && $proof->verified_counter === $factor->last_accepted_time_step
            && $proof->mfa_verified_at !== null && $proof->mfa_verified_at->lte(now()), 422, 'Authentication could not be completed.');
        $plainToken = bin2hex(random_bytes(40));
        $row = new ApiToken;
        $row->forceFill(['user_id' => $lockedUser->id, 'name' => Str::limit($tokenName, 255, ''),
            'token_hash' => hash('sha256', $plainToken), 'expires_at' => now()->addDays(30),
            'audience' => $proof->audience, 'auth_epoch' => $lockedUser->auth_epoch,
            'factor_id' => $factor->id, 'factor_version' => $factor->version,
            'mfa_verified_at' => $proof->mfa_verified_at])->save();
        $proof->forceFill(['token_issued_at' => now()])->save();

        return $plainToken;
    }

    public function startLogin(string $email, #[\SensitiveParameter] string $password, string $audience, #[\SensitiveParameter] string $binding): array
    {
        return $this->challenges->credentials($email, $password, $audience, $binding);
    }

    public function completeLogin(string $token, string $code, string $audience, string $binding): array
    {
        return $this->challenges->run($token, $audience, $binding, ['login_totp'],
            function (User $user, ?UserAuthFactor $factor, AuthChallenge $challenge) use ($code): array {
                if ($factor === null) {
                    return $this->challenges->reject($user, $challenge, 'factor_missing');
                }
                $counter = $this->totp->matchingCounter($this->totp->decrypt($factor->secret_ciphertext, $factor->encryption_key_version), $code);
                if ($counter === null || $counter <= $factor->last_accepted_time_step) {
                    return $this->challenges->reject($user, $challenge, $counter === null ? 'totp_invalid' : 'totp_replay');
                }
                $factor->forceFill(['last_accepted_time_step' => $counter])->save();
                $this->challenges->consume($challenge, ['verified_counter' => $counter, 'mfa_verified_at' => now()]);
                $plain = $this->security->tokenIssuance(fn () => $this->issueToken($user, $challenge, 'portal'));
                $this->security->event('authentication_login_succeeded', $user,
                    ['challenge_id' => $challenge->id, 'audience' => $challenge->audience]);

                return ['message' => 'Login successful.', ...$this->buildAuthPayload($user, $plain)];
            });
    }

    public function buildAuthPayload(User $user, ?string $plainToken = null): array
    {
        $user->loadMissing(['profile', 'providerAccounts.provider', 'roles']);

        $internalKycVerified = in_array(strtolower((string) $user->kyc_status), ['verified', 'approved'], true);
        $legacyProfileCompleted = $user->profile !== null && filled($user->profile->user_type);
        $profileCompleted = $legacyProfileCompleted || $internalKycVerified;
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
                'internal_kyc_verified' => $internalKycVerified,
                'selected_provider_code' => $selectedProviderCode,
                'selected_provider_account_status' => $selectedProviderStatus,
                'provider_account_statuses' => $providerStatuses,
                'next_action' => ! $profileCompleted
                    ? 'complete_user_profile'
                    : ($selectedProviderCode === null ? 'select_provider' : null),
                'message' => $profileCompleted
                    ? ($selectedProviderCode !== null
                        ? ($internalKycVerified
                            ? 'Profile verified. Provider onboarding remains pending until provider connection is completed.'
                            : 'Profile received. Account and KYC status remain pending until provider onboarding is completed.')
                        : ($internalKycVerified
                            ? 'Profile verified. Select a provider to start account onboarding.'
                            : 'Profile received. Select a provider to start account onboarding.'))
                    : 'Login successful. User must complete profile before using wallet features.',
            ],
            'providers' => $this->providerCatalog->activePublicPayloads(),
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
}
