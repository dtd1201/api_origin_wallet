<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NiumComplianceEvent extends Model
{
    use HasFactory;

    public const CREATED_AT = 'received_at';

    public const UPDATED_AT = null;

    protected $fillable = [
        'provider_id',
        'user_id',
        'transfer_id',
        'transaction_id',
        'event_id',
        'request_id',
        'reference',
        'customer_reference',
        'event_type',
        'compliance_status',
        'match_status',
        'review_status',
        'requires_action',
        'processing_status',
        'payload',
        'duplicate_count',
        'last_received_at',
        'processed_at',
        'reviewed_by',
        'reviewed_at',
        'resolution_note',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'requires_action' => 'boolean',
            'payload' => 'array',
            'received_at' => 'datetime',
            'last_received_at' => 'datetime',
            'processed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'provider_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
