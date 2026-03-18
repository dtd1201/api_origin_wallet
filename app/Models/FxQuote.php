<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FxQuote extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'provider_id',
        'quote_ref',
        'source_currency',
        'target_currency',
        'source_amount',
        'target_amount',
        'mid_rate',
        'net_rate',
        'fee_amount',
        'expires_at',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'source_amount' => 'decimal:8',
            'target_amount' => 'decimal:8',
            'mid_rate' => 'decimal:10',
            'net_rate' => 'decimal:10',
            'fee_amount' => 'decimal:8',
            'expires_at' => 'datetime',
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
}
