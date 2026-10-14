<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class KycNotificationEvent extends Model
{
    protected $fillable = [
        'user_provider_account_id', 'event_type', 'idempotency_key', 'payload',
        'dispatched_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'dispatched_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(UserProviderAccount::class, 'user_provider_account_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(KycNotificationDelivery::class);
    }
}
