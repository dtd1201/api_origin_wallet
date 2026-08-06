<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiRequestLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'provider_id',
        'user_id',
        'related_transfer_id',
        'operation',
        'client_hash_id',
        'external_reference',
        'request_method',
        'request_url',
        'endpoint_path',
        'request_headers',
        'request_body',
        'response_status',
        'response_headers',
        'response_body',
        'request_started_at',
        'request_finished_at',
        'content_type',
        'transport_outcome',
        'duration_ms',
        'is_success',
    ];

    protected function casts(): array
    {
        return [
            'request_headers' => 'array',
            'request_body' => 'array',
            'response_headers' => 'array',
            'response_body' => 'array',
            'is_success' => 'boolean',
            'created_at' => 'datetime',
            'request_started_at' => 'datetime',
            'request_finished_at' => 'datetime',
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

    public function relatedTransfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class, 'related_transfer_id');
    }
}
