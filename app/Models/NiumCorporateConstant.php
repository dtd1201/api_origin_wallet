<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NiumCorporateConstant extends Model
{
    protected $fillable = [
        'region',
        'customer_type',
        'country_code',
        'constant_type',
        'values',
        'fetched_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'values' => 'array',
            'fetched_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
