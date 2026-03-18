<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider_id',
        'bank_account_id',
        'transfer_id',
        'external_transaction_id',
        'transaction_type',
        'direction',
        'currency',
        'amount',
        'fee_amount',
        'description',
        'reference_text',
        'status',
        'booked_at',
        'value_date',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'fee_amount' => 'decimal:8',
            'booked_at' => 'datetime',
            'value_date' => 'date',
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

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }
}
