<?php

namespace App\Services\Auth;

use App\Models\AuthChallenge;
use App\Models\AuthEnrollmentGrant;
use App\Models\AuthRegistrationEvidence;
use App\Models\User;
use Carbon\CarbonImmutable;

class EnrollmentTrustPolicy
{
    public function newRegistration(User $user): ?AuthRegistrationEvidence
    {
        $evidence = AuthRegistrationEvidence::where('user_id', $user->id)->first();
        if ($user->isAdmin() || $user->email_verified_at === null || $evidence === null
            || $evidence->consumed_at !== null || $evidence->expires_at->lte(now()) || $evidence->verification_attempts >= 5
            || $evidence->auth_epoch !== $user->auth_epoch
            || ! hash_equals($evidence->email_hash, hash('sha256', $user->email))
            || ! hash_equals($evidence->password_fingerprint, hash('sha256', $user->password_hash))) {
            return null;
        }

        return $evidence;
    }

    public function migration(User $user): ?AuthEnrollmentGrant
    {
        if (! config('totp.migration.enabled') || ! config('totp.migration.policy_reference')
            || ! config('totp.migration.deadline') || $user->isAdmin()) {
            return null;
        }
        $deadline = CarbonImmutable::parse(config('totp.migration.deadline'));
        if ($deadline->lte(now()) || ($user->credentials_changed_at !== null
            && $user->credentials_changed_at->addHours(max(72, (int) config('totp.migration.reset_hold_hours')))->gt(now()))) {
            return null;
        }

        return AuthEnrollmentGrant::where('user_id', $user->id)->where('purpose', 'migration')
            ->where('audience', 'user')->where('auth_epoch', $user->auth_epoch)
            ->where('email_hash', hash('sha256', $user->email))
            ->where('policy_reference', config('totp.migration.policy_reference'))
            ->whereNull('revoked_at')->whereNull('consumed_at')->where('expires_at', '>', now())
            ->where('expires_at', '<=', $deadline)->first();
    }

    public function validGrant(AuthEnrollmentGrant $grant, User $user, string $audience, string $purpose): bool
    {
        $policy = $purpose === 'migration' ? 'migration' : 'assisted';

        return (bool) config('totp.'.$policy.'.enabled')
            && filled(config('totp.'.$policy.'.policy_reference'))
            && hash_equals((string) config('totp.'.$policy.'.policy_reference'), $grant->policy_reference)
            && $grant->user_id === $user->id && $grant->audience === $audience && $grant->purpose === $purpose
            && $grant->auth_epoch === $user->auth_epoch && $grant->expires_at->gt(now())
            && $grant->consumed_at === null && $grant->revoked_at === null
            && hash_equals($grant->email_hash, hash('sha256', $user->email))
            && ($purpose !== 'migration' || $this->migration($user)?->id === $grant->id);
    }

    public function trusted(User $user, AuthChallenge $challenge): bool
    {
        if ($challenge->trusted_identity_verified_at === null) {
            return false;
        }
        if ($challenge->trusted_identity_method === 'new_registration') {
            return $challenge->audience === 'user' && $this->newRegistration($user)?->id === $challenge->registration_evidence_id;
        }
        if ($challenge->trusted_identity_method === 'totp_step_up') {
            $token = $challenge->initiating_token_id ? \App\Models\ApiToken::find($challenge->initiating_token_id) : null;

            return $challenge->purpose === 'replacement_setup' && $token !== null
                && app(AuthTokenEligibility::class)->allows($token, $user, $challenge->audience);
        }
        if ($challenge->trusted_identity_method === 'recovery_code') {
            return $challenge->purpose === 'replacement_setup';
        }
        if ($challenge->trusted_identity_method === 'assisted_recovery') {
            $grant = AuthEnrollmentGrant::whereKey($challenge->grant_id)->lockForUpdate()->first();

            return $challenge->purpose === 'replacement_setup' && config('totp.assisted.enabled')
                && $grant !== null && $grant->user_id === $user->id && $grant->audience === $challenge->audience
                && $grant->purpose === 'replacement' && $grant->consumed_at !== null
                && $grant->expires_at->gt(now()) && $grant->auth_epoch === $user->auth_epoch - 1
                && hash_equals($grant->email_hash, hash('sha256', $user->email))
                && $grant->policy_reference === config('totp.assisted.policy_reference');
        }
        $grant = $challenge->grant_id ? AuthEnrollmentGrant::whereKey($challenge->grant_id)->lockForUpdate()->first() : null;
        $purpose = $challenge->trusted_identity_method === 'migration_email' ? 'migration'
            : ($challenge->purpose === 'replacement_setup' ? 'replacement' : 'initial_enrollment');

        return $grant !== null && $this->validGrant($grant, $user, $challenge->audience, $purpose);
    }
}
