<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'kyc_profile_id',
        'key',
        'label',
        'category',
        'status',
        'requirement_type',
        'subject_type',
        'subject_id',
        'review_note',
        'rejection_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function kycProfile(): BelongsTo
    {
        return $this->belongsTo(KycProfile::class);
    }
}
