<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycProviderSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'kyc_profile_id',
        'provider_id',
        'provider_account_id',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'approved_at',
        'submitted_at',
        'rejected_at',
        'review_note',
        'rejection_reason',
        'failure_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'submitted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'metadata' => 'array',
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

    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'provider_id');
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(UserProviderAccount::class, 'provider_account_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
