<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycCorrectionSyncAttempt extends Model
{
    protected $fillable = [
        'kyc_profile_correction_id', 'correction_version', 'request_id', 'request_fingerprint',
        'status', 'active_slot', 'http_status', 'verification_result', 'failure_code',
        'started_by_user_id', 'started_at', 'put_completed_at', 'verified_at', 'resolved_at',
    ];

    protected $hidden = ['verification_result'];

    protected function casts(): array
    {
        return [
            'correction_version' => 'integer', 'active_slot' => 'integer', 'http_status' => 'integer',
            'verification_result' => 'encrypted:array', 'started_at' => 'datetime',
            'put_completed_at' => 'datetime', 'verified_at' => 'datetime', 'resolved_at' => 'datetime',
        ];
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(KycProfileCorrection::class, 'kyc_profile_correction_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }
}
