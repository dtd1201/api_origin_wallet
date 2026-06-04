<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FxOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_no',
        'user_id',
        'provider_id',
        'source_currency',
        'target_currency',
        'source_amount',
        'target_amount',
        'fx_rate',
        'fee_amount',
        'fee_currency',
        'status',
        'customer_snapshot',
        'raw_data',
        'admin_note',
        'confirmed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'source_amount' => 'decimal:8',
            'target_amount' => 'decimal:8',
            'fx_rate' => 'decimal:10',
            'fee_amount' => 'decimal:8',
            'customer_snapshot' => 'array',
            'raw_data' => 'array',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
