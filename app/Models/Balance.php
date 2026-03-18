<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Balance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider_id',
        'bank_account_id',
        'external_account_id',
        'currency',
        'available_balance',
        'ledger_balance',
        'reserved_balance',
        'as_of',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'available_balance' => 'decimal:8',
            'ledger_balance' => 'decimal:8',
            'reserved_balance' => 'decimal:8',
            'as_of' => 'datetime',
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

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
