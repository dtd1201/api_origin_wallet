<?php

namespace App\Services\Auth;

use App\Models\AuthChallenge;
use App\Models\AuthEnrollmentGrant;
use App\Models\AuthRegistrationEvidence;
use App\Models\PendingLogin;
use App\Models\User;
use App\Models\UserAuthFactor;
use Illuminate\Support\Facades\DB;

class AuthRevocationService
{
    public function __construct(private readonly AuthSecurity $security) {}

    /** Caller must hold User, then factor locks; no factor or replay deletion. */
    public function revokeLocked(User $user, string $reason, ?AuthChallenge $preserve = null): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Auth revocation requires an account transaction.');
        }
        $user->forceFill(['auth_epoch' => $user->auth_epoch + 1])->save();
        AuthChallenge::where('user_id', $user->id)->when($preserve, fn ($query) => $query->where('id', '!=', $preserve->id))->whereNull('revoked_at')->update([
            'revoked_at' => now(), 'pending_secret_ciphertext' => null, 'identity_proof_hash' => null,
        ]);
        AuthEnrollmentGrant::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        AuthRegistrationEvidence::where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        $user->apiTokens()->delete();
        PendingLogin::where('user_id', $user->id)->delete();
        if ($preserve !== null) {
            $preserve->forceFill(['auth_epoch' => $user->auth_epoch])->save();
        }
        $this->security->event('authentication_tokens_revoked', $user, ['reason' => $reason]);
    }

    /** For credential changes; use before any reset-token row is locked. */
    public function lockFactor(User $user): ?UserAuthFactor
    {
        return UserAuthFactor::where('user_id', $user->id)->where('type', 'totp')->lockForUpdate()->first();
    }
}
