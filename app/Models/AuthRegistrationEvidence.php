<?php

namespace App\Models;

class AuthRegistrationEvidence extends AuthState
{
    protected $table = 'auth_registration_evidence';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['auth_epoch' => 'integer', 'verification_attempts' => 'integer', 'verified_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }
}
