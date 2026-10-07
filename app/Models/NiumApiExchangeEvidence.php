<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NiumApiExchangeEvidence extends Model
{
    protected $table = 'nium_api_exchange_evidence';

    protected $fillable = [
        'api_request_log_id',
        'request_method',
        'request_url',
        'request_content_type',
        'response_content_type',
        'raw_request_body',
        'raw_response_body',
        'request_headers',
        'response_headers',
        'request_body_sha256',
        'response_body_sha256',
        'request_headers_sha256',
        'response_headers_sha256',
        'request_byte_length',
        'response_byte_length',
        'capture_version',
        'response_received',
    ];

    protected $hidden = [
        'raw_request_body',
        'raw_response_body',
        'request_headers',
        'response_headers',
    ];

    protected function casts(): array
    {
        return [
            'raw_request_body' => 'encrypted',
            'raw_response_body' => 'encrypted',
            'request_headers' => 'encrypted:array',
            'response_headers' => 'encrypted:array',
            'request_byte_length' => 'integer',
            'response_byte_length' => 'integer',
            'capture_version' => 'integer',
            'response_received' => 'boolean',
        ];
    }

    public function apiRequestLog(): BelongsTo
    {
        return $this->belongsTo(ApiRequestLog::class);
    }
}
