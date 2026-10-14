<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NiumManualKycContinuation extends Model
{
    public const FILE_PROCESSING = 'FILE_PROCESSING';
    public const SUBMITTING = 'SUBMITTING';
    public const ACCEPTED = 'ACCEPTED';
    public const HOLD = 'HOLD';
    public const UNKNOWN = 'UNKNOWN';
    public const COMPLIANCE_REVIEW = 'COMPLIANCE_REVIEW';

    protected $guarded = [];

    protected $hidden = ['claim_token', 'hold_message'];

    protected function casts(): array
    {
        return [
            'correction_version' => 'integer',
            'poll_attempts' => 'integer',
            'claimed_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'deadline_at' => 'datetime',
            'submit_started_at' => 'datetime',
            'submit_claim_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(KycProfileCorrection::class, 'kyc_profile_correction_id');
    }

    public function resubmissionAuthorization(): BelongsTo
    {
        return $this->belongsTo(NiumManualKycResubmissionAuthorization::class, 'resubmission_authorization_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KycDocument::class, 'kyc_document_id');
    }

    public function proofOfAddressDocument(): BelongsTo
    {
        return $this->belongsTo(KycDocument::class, 'proof_of_address_document_id');
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(UserProviderAccount::class, 'user_provider_account_id');
    }
}
