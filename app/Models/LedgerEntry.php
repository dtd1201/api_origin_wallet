<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'balance_id',
        'user_id',
        'provider_id',
        'reference',
        'entry_type',
        'status',
        'currency',
        'amount',
        'balance_after',
        'source_type',
        'source_id',
        'description',
        'posted_at',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'balance_after' => 'decimal:8',
            'posted_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    public function balance(): BelongsTo
    {
        return $this->belongsTo(Balance::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'provider_id');
    }
}
