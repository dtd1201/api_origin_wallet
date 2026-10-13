<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KycDocumentUpload extends Model
{
    protected $fillable = [
        'user_id', 'kyc_profile_correction_id', 'storage_disk', 'file_path', 'file_hash',
        'mime_type', 'file_size', 'status', 'expires_at', 'referenced_at', 'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer', 'expires_at' => 'datetime', 'referenced_at' => 'datetime', 'deleted_at' => 'datetime',
        ];
    }
}
