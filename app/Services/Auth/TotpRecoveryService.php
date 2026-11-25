<?php

namespace App\Services\Auth;

use App\Models\ApiToken;
use App\Models\AuthChallenge;
use App\Models\AuthRecoveryCode;
use App\Models\User;
use App\Models\UserAuthFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TotpRecoveryService
{
    public function __construct(private readonly AuthChallengeService $challenges, private readonly TotpService $totp,
        private readonly AuthRevocationService $revocation, private readonly RecoveryCodeService $codes,
        private readonly EnrollmentGrantService $grants, private readonly EnrollmentTrustPolicy $trust,
        private readonly AuthTokenEligibility $eligibility, private readonly AuthSecurity $security) {}

    public function start(string $token, string $audience, string $binding): array
    {
        return $this->challenges->run($token, $audience, $binding, ['login_totp'],
            fn (User $user, $factor, AuthChallenge $challenge) => $this->challenges->transition($user, $factor, $challenge, 'login_recovery'));
    }

    public function verify(string $token, string $audience, string $binding, ?string $code, ?string $grantToken): array
    {
        return $this->challenges->run($token, $audience, $binding, ['login_recovery'], function (User $user, ?UserAuthFactor $factor, AuthChallenge $challenge) use ($code, $grantToken): array {
            if ($factor === null || $challenge->credential_verified_at->lt(now()->subMinutes(5))) {
                return $this->challenges->reject($user, $challenge, 'fresh_password_required');
            }
            $grant = null;
            if ($grantToken !== null) {
                $grant = $this->grants->find($grantToken);
                $valid = $grant !== null && $this->trust->validGrant($grant, $user, $challenge->audience, 'replacement');
                if ($valid) {
                    $grant->forceFill(['consumed_at' => now()])->save();
                }
            } else {
                // Account/factor locks serialize consumers; delay locking the
                // recovery row until all challenge/grant transitions are made.
                $valid = $code !== null && $this->codes->valid($factor, $code) !== null;
            }
            if (! $valid) {
                return $this->challenges->reject($user, $challenge, 'recovery_invalid');
            }
            $this->revocation->revokeLocked($user, 'recovery_authorized', $challenge);
            $this->security->event($grant ? 'assisted_recovery_authorized' : 'recovery_code_consumed', $user);
            $this->security->notice($user, 'authenticator recovery authorized');

            $result = $this->challenges->transition($user, $factor, $challenge, 'replacement_setup', [
                'trusted_identity_method' => $grant ? 'assisted_recovery' : 'recovery_code',
                'trusted_identity_verified_at' => now(), 'grant_id' => $grant?->id,
                'ceremony_deadline' => $grant ? $challenge->ceremony_deadline->min($grant->expires_at) : $challenge->ceremony_deadline,
            ]);
            if ($grant === null && ! $this->codes->consume($factor, $code)) {
                // Invariant failure rolls the success transition back; a normal
                // invalid code already returned a committed failure above.
                throw new \RuntimeException('Recovery consumption conflict.');
            }

            return $result;
        });
    }

    public function intake(string $email): array
    {
        $this->security->limit('assisted_intake', $email);
        $user = User::where('email', $email)->first();
        $this->security->event('assisted_recovery_requested', $user);

        return ['message' => 'Recovery request recorded. Contact your established Origin Wallet support channel.'];
    }

    public function status(User $user): array
    {
        $factor = UserAuthFactor::where('user_id', $user->id)->where('type', 'totp')->firstOrFail();

        return ['mandatory' => true, 'enrolled' => true, 'confirmed_at' => $factor->confirmed_at->toIso8601String(),
            'recovery_codes_remaining' => AuthRecoveryCode::where('factor_id', $factor->id)
                ->where('set_version', $factor->recovery_set_version)->whereNull('consumed_at')->count()];
    }

    public function stepUp(ApiToken $token, string $password, string $binding, string $intent): array
    {
        $this->security->limit('credentials', (string) $token->user_id);
        $result = DB::transaction(function () use ($token, $password, $binding, $intent): array {
            $user = User::whereKey($token->user_id)->lockForUpdate()->firstOrFail();
            $factor = $this->challenges->lockFactor($user);
            $current = ApiToken::find($token->id);
            if ($current === null || ! $this->eligibility->allows($current, $user, $token->audience)
                || ! Hash::check($password, $user->password_hash)
                || ! in_array($intent, ['replace_authenticator', 'regenerate_recovery_codes'], true)) {
                $this->security->event('authentication_step_up_failed', $user);

                return ['_auth_error' => true];
            }

            return $this->challenges->create($user, $factor, $token->audience, 'step_up', hash('sha256', $binding),
                now(), now()->addMinutes(5), ['intent' => $intent, 'initiating_token_id' => $token->id]);
        });
        abort_if(isset($result['_auth_error']), 422, 'Authentication could not be completed.');

        return $result;
    }

    public function stepUpVerify(ApiToken $initiator, string $token, string $binding, string $code): array
    {
        return $this->challenges->run($token, $initiator->audience, $binding, ['step_up'],
            function (User $user, ?UserAuthFactor $factor, AuthChallenge $challenge) use ($initiator, $code): array {
                if ($factor === null || ! $this->initiatorValid($user, $challenge, $initiator)) {
                    return $this->challenges->reject($user, $challenge, 'step_up_binding');
                }
                $counter = $this->totp->matchingCounter($this->totp->decrypt($factor->secret_ciphertext, $factor->encryption_key_version), $code);
                if ($counter === null || $counter <= $factor->last_accepted_time_step) {
                    return $this->challenges->reject($user, $challenge, $counter === null ? 'totp_invalid' : 'totp_replay');
                }
                $factor->forceFill(['last_accepted_time_step' => $counter])->save();
                if ($challenge->intent === 'replace_authenticator') {
                    return $this->challenges->transition($user, $factor, $challenge, 'replacement_setup', [
                        'trusted_identity_method' => 'totp_step_up', 'trusted_identity_verified_at' => now(),
                        'initiating_token_id' => $initiator->id, 'intent' => $challenge->intent,
                    ]);
                }

                return $this->challenges->transition($user, $factor, $challenge, 'step_up_grant', [
                    'intent' => $challenge->intent, 'initiating_token_id' => $initiator->id,
                    'trusted_identity_method' => 'totp_step_up', 'trusted_identity_verified_at' => now(),
                ]);
            });
    }

    public function regenerate(ApiToken $initiator, string $token, string $binding): array
    {
        return $this->challenges->run($token, $initiator->audience, $binding, ['step_up_grant'],
            function (User $user, UserAuthFactor $factor, AuthChallenge $challenge) use ($initiator): array {
                if ($challenge->intent !== 'regenerate_recovery_codes' || ! $this->initiatorValid($user, $challenge, $initiator)) {
                    return $this->challenges->reject($user, $challenge, 'step_up_binding');
                }
                $this->challenges->consume($challenge);
                $factor->forceFill(['recovery_set_version' => $factor->recovery_set_version + 1])->save();
                $codes = $this->codes->rotate($factor);
                $this->security->event('recovery_codes_regenerated', $user, ['count' => 10]);
                $this->security->notice($user, 'recovery codes regenerated');

                return ['recovery_codes' => $codes];
            });
    }

    private function initiatorValid(User $user, AuthChallenge $challenge, ApiToken $initiator): bool
    {
        $current = ApiToken::find($initiator->id);

        return $current !== null && (int) $challenge->initiating_token_id === $current->id
            && $this->eligibility->allows($current, $user, $challenge->audience)
            && $challenge->credential_verified_at->gte(now()->subMinutes(5));
    }
}
