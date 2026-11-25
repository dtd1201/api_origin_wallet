<?php

namespace App\Models;

class AuthEnrollmentGrant extends AuthState
{
    protected function casts(): array
    {
        return ['auth_epoch' => 'integer', 'approver_ids' => 'array', 'approved_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime',
            'proof_sent_at' => 'immutable_datetime', 'proof_send_count' => 'integer'];
    }
}
