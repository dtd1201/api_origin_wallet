<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedExchangeRate extends Model
{
    use HasFactory;

    public const TYPE_PROVIDER = 'provider';
    public const TYPE_BANK = 'bank';

    public const AUDIENCE_PUBLIC = 'public';
    public const AUDIENCE_AUTHENTICATED = 'authenticated';

    protected $fillable = [
        'rate_type',
        'audience',
        'provider_id',
        'source_code',
        'source_name',
        'source_currency',
        'target_currency',
        'buy_rate',
        'sell_rate',
        'mid_rate',
        'fee_amount',
        'status',
        'display_order',
        'notes',
        'published_at',
    ];

    protected $casts = [
        'buy_rate' => 'decimal:8',
        'sell_rate' => 'decimal:8',
        'mid_rate' => 'decimal:8',
        'fee_amount' => 'decimal:8',
        'display_order' => 'integer',
        'published_at' => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'provider_id');
    }
}
