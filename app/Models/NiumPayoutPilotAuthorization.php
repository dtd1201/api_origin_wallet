<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class NiumPayoutPilotAuthorization extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    // No customer/admin HTTP mass-assignment surface. Provisioned by the local command only.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['binding' => 'array', 'verification' => 'array', 'charge_ceiling' => 'decimal:8',
            'reserve_remaining' => 'decimal:8', 'expires_at' => 'datetime', 'consumed_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
