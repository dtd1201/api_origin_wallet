<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AmlScreening extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'kyc_profile_id',
        'subject_type',
        'subject_id',
        'subject_name',
        'subject_role',
        'screening_provider',
        'status',
        'risk_level',
        'risk_score',
        'screened_at',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_note',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'screened_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'raw_data' => 'array',
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

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(AmlMatch::class);
    }
}
