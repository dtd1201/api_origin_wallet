<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Beneficiary extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider_id',
        'external_beneficiary_id',
        'beneficiary_type',
        'full_name',
        'company_name',
        'email',
        'phone',
        'country_code',
        'currency',
        'bank_name',
        'bank_code',
        'branch_code',
        'account_number',
        'iban',
        'swift_bic',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'status',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'provider_id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class);
    }
}
