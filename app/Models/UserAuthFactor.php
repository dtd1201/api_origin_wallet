<?php

namespace App\Models;

class UserAuthFactor extends AuthState
{
    protected function casts(): array
    {
        return ['confirmed_at' => 'immutable_datetime', 'last_accepted_time_step' => 'integer',
            'version' => 'integer', 'recovery_set_version' => 'integer'];
    }
}
