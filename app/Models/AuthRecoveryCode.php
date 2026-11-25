<?php

namespace App\Models;

class AuthRecoveryCode extends AuthState
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['consumed_at' => 'immutable_datetime'];
    }
}
