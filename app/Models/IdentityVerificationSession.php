<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityVerificationSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'kyc_profile_id',
        'provider',
        'external_session_id',
        'subject_type',
        'status',
        'liveness_score',
        'face_match_score',
        'document_ocr',
        'checks',
        'raw_response',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'liveness_score' => 'decimal:2',
            'face_match_score' => 'decimal:2',
            'document_ocr' => 'array',
            'checks' => 'array',
            'raw_response' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kycProfile(): BelongsTo
    {
        return $this->belongsTo(KycProfile::class);
    }
}
