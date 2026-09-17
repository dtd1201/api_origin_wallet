<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycCompanyDirectorDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'kyc_company_director_id',
        'type',
        'status',
        'file_url',
        'storage_disk',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
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

    public function companyDirector(): BelongsTo
    {
        return $this->belongsTo(
            KycCompanyDirector::class,
            'kyc_company_director_id'
        );
    }
}
