<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'kyc_profile_id',
        'kyc_related_person_id',
        'type',
        'status',
        'file_url',
        'file_hash',
        'side',
        'document_number',
        'issuing_country_code',
        'issued_at',
        'expires_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
            'metadata' => 'array',
        ];
    }

    public function kycProfile(): BelongsTo
    {
        return $this->belongsTo(KycProfile::class);
    }

    public function relatedPerson(): BelongsTo
    {
        return $this->belongsTo(KycRelatedPerson::class, 'kyc_related_person_id');
    }
}
