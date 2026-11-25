<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiToken extends Model
{
    protected $hidden = ['token_hash', 'auth_epoch', 'factor_id', 'factor_version'];

    protected $fillable = [
        'user_id',
        'name',
        'token_hash',
        'last_used_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'mfa_verified_at' => 'immutable_datetime',
            'auth_epoch' => 'integer',
            'factor_version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
