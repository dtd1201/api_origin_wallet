<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KycProfileCorrection extends Model
{
    protected $fillable = [
        'kyc_profile_id',
        'applicant_related_person_id',
        'user_provider_account_id',
        'correction_version',
        'source_fingerprint',
        'customer_hash_id',
        'applicant_reference_id',
        'applicant_external_id',
        'customer_hash_fingerprint',
        'applicant_reference_fingerprint',
        'applicant_external_fingerprint',
        'before_snapshot',
        'proposed_values',
        'requested_fields',
        'requested_document_types',
        'proposed_documents',
        'request_instruction',
        'request_origin',
        'approved_source_snapshot',
        'approved_source_fingerprint',
        'status',
        'active_slot',
        'created_by_user_id',
        'requested_by_user_id',
        'reviewed_by_user_id',
        'review_note',
        'request_instruction',
        'submitted_at',
        'reviewed_at',
        'resolved_at',
    ];

    protected $hidden = [
        'customer_hash_id',
        'applicant_reference_id',
        'applicant_external_id',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'correction_version' => 'integer',
            'active_slot' => 'integer',
            'customer_hash_id' => 'encrypted',
            'applicant_reference_id' => 'encrypted',
            'applicant_external_id' => 'encrypted',
            'before_snapshot' => 'encrypted:array',
            'proposed_values' => 'encrypted:array',
            'requested_fields' => 'encrypted:array',
            'requested_document_types' => 'encrypted:array',
            'proposed_documents' => 'encrypted:array',
            'request_instruction' => 'encrypted',
            'approved_source_snapshot' => 'encrypted:array',
            'review_note' => 'encrypted',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function kycProfile(): BelongsTo
    {
        return $this->belongsTo(KycProfile::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(KycRelatedPerson::class, 'applicant_related_person_id');
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(UserProviderAccount::class, 'user_provider_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function syncAttempts(): HasMany
    {
        return $this->hasMany(KycCorrectionSyncAttempt::class);
    }
}
