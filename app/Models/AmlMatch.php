<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmlMatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'aml_screening_id',
        'list_type',
        'source',
        'matched_name',
        'score',
        'country_code',
        'date_of_birth',
        'external_reference',
        'status',
        'resolved_by_user_id',
        'resolved_at',
        'resolution_note',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'date_of_birth' => 'date',
            'resolved_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    public function amlScreening(): BelongsTo
    {
        return $this->belongsTo(AmlScreening::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
