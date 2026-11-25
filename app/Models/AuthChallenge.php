<?php

namespace App\Models;

class AuthChallenge extends AuthState
{
    protected function casts(): array
    {
        return ['auth_epoch' => 'integer', 'factor_version' => 'integer', 'attempts' => 'integer',
            'max_attempts' => 'integer', 'proof_send_count' => 'integer', 'verified_counter' => 'integer',
            'credential_verified_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
            'ceremony_deadline' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime', 'last_attempt_at' => 'immutable_datetime',
            'proof_sent_at' => 'immutable_datetime', 'trusted_identity_verified_at' => 'immutable_datetime',
            'mfa_verified_at' => 'immutable_datetime', 'token_issued_at' => 'immutable_datetime'];
    }
}
