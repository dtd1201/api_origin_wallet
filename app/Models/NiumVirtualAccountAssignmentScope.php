<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NiumVirtualAccountAssignmentScope extends Model
{
    protected $table = 'nium_va_assignment_scopes';

    protected $primaryKey = 'scope_key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public function activeOperation(): BelongsTo
    {
        return $this->belongsTo(NiumVirtualAccountOperation::class, 'active_operation_id');
    }
}
