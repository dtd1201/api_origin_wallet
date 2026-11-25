<?php

namespace App\Services\Auth;

use App\Models\ApiToken;
use App\Models\User;
use App\Models\UserAuthFactor;

class AuthTokenEligibility
{
    public function allows(ApiToken $token, User $user, string $audience): bool
    {
        $factor = UserAuthFactor::query()->where('user_id', $user->id)->where('type', 'totp')->first();

        return $factor !== null && $factor->confirmed_at !== null && $factor->confirmed_at->lte(now())
            && $token->user_id === $user->id && $token->audience === $audience
            && in_array($audience, ['admin', 'user'], true)
            && ($audience !== 'admin' || $user->isAdmin())
            && $token->expires_at !== null && $token->expires_at->gt(now())
            && $token->mfa_verified_at !== null && $token->mfa_verified_at->lte(now())
            && $token->mfa_verified_at->gte($factor->confirmed_at)
            && $token->auth_epoch !== null && $token->auth_epoch === $user->auth_epoch
            && (int) $token->factor_id === $factor->id && $token->factor_version === $factor->version;
    }
}
