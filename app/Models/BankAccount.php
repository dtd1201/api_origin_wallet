<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider_id',
        'external_account_id',
        'account_type',
        'currency',
        'country_code',
        'bank_name',
        'bank_code',
        'branch_code',
        'account_name',
        'account_number',
        'iban',
        'swift_bic',
        'routing_number',
        'status',
        'is_default',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
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

    public function balances(): HasMany
    {
        return $this->hasMany(Balance::class);
    }

    public function sourceTransfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'source_bank_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
