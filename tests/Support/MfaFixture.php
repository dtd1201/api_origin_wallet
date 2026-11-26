<?php

namespace Tests\Support;

use App\Models\ApiToken;
use App\Models\User;
use App\Models\UserAuthFactor;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Explicit synthetic completed MFA for business tests, never production code. */
final class MfaFixture
{
    public const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public static function factor(User $user, string $secret = self::SECRET): UserAuthFactor
    {
        return DB::transaction(function () use ($user, $secret): UserAuthFactor {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $factor = UserAuthFactor::where('user_id', $user->id)->where('type', 'totp')->lockForUpdate()->first();
            if ($factor !== null) {
                return $factor;
            }
            $factor = new UserAuthFactor;
            $factor->forceFill(['user_id' => $user->id, 'type' => 'totp', 'secret_ciphertext' => Crypt::encryptString($secret),
                'confirmed_at' => now()->subMinute(), 'last_accepted_time_step' => intdiv(now()->getTimestamp(), 30) - 2,
                'version' => 1, 'recovery_set_version' => 1, 'encryption_key_version' => 'app-v1'])->save();

            return $factor;
        });
    }

    public static function token(array $attributes): ApiToken
    {
        $user = User::findOrFail($attributes['user_id']);
        $factor = self::factor($user);
        $token = new ApiToken;
        $token->forceFill(['expires_at' => now()->addDays(30), ...$attributes,
            'audience' => $attributes['audience'] ?? ($user->isAdmin() ? 'admin' : 'user'),
            'mfa_verified_at' => now(), 'auth_epoch' => $user->auth_epoch,
            'factor_id' => $factor->id, 'factor_version' => $factor->version])->save();

        return $token;
    }
}
