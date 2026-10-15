<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NiumRfiCaseEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['nium_rfi_case_id', 'actor_user_id', 'event_type', 'from_state', 'to_state', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function rfiCase(): BelongsTo
    {
        return $this->belongsTo(NiumRfiCase::class, 'nium_rfi_case_id');
    }
}
