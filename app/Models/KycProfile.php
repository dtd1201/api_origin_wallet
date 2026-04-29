<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KycProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'applicant_type',
        'legal_name',
        'date_of_birth',
        'nationality_country_code',
        'residence_country_code',
        'business_name',
        'business_registration_number',
        'tax_id',
        'registered_country_code',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country_code',
        'metadata',
        'submitted_at',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_note',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'metadata' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(KycDocument::class);
    }

    public function relatedPersons(): HasMany
    {
        return $this->hasMany(KycRelatedPerson::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(KycRequirement::class);
    }

    public function providerSubmissions(): HasMany
    {
        return $this->hasMany(KycProviderSubmission::class);
    }

    public function amlScreenings(): HasMany
    {
        return $this->hasMany(AmlScreening::class);
    }
}
