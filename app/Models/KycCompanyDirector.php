<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycCompanyDirector extends Model
{
    use HasFactory;

    protected $fillable = [
        'kyc_profile_id',
        'legal_name',
        'date_of_birth',
        'nationality_country_code',
        'residence_country_code',
        'position',
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
            'metadata' => 'array',
        ];
    }

    public function kycProfile(): BelongsTo
    {
        return $this->belongsTo(KycProfile::class);
    }
}
