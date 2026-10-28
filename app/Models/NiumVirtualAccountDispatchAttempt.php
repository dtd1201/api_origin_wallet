<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NiumVirtualAccountDispatchAttempt extends Model
{
    protected $table = 'nium_va_dispatch_attempts';

    protected $primaryKey = 'operation_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['consumed_at' => 'immutable_datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(NiumVirtualAccountRequest::class, 'operation_id', 'operation_id');
    }
}
