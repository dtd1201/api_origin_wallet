<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KycRelatedPerson extends Model
{
    use HasFactory;

    protected $table = 'kyc_related_persons';

    protected $fillable = [
        'kyc_profile_id',
        'relationship_type',
        'status',
        'legal_name',
        'date_of_birth',
        'nationality_country_code',
        'residence_country_code',
        'ownership_percentage',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country_code',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'ownership_percentage' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function kycProfile(): BelongsTo
    {
        return $this->belongsTo(KycProfile::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(KycDocument::class);
    }
}
