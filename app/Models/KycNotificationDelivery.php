<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class KycNotificationDelivery extends Model
{
    protected $fillable = [
        'kyc_notification_event_id', 'recipient_type', 'email', 'normalized_email',
        'status', 'attempts', 'last_attempted_at', 'delivered_at', 'last_error',
    ];

    protected function casts(): array
    {
        return ['last_attempted_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(KycNotificationEvent::class, 'kyc_notification_event_id');
    }
}
