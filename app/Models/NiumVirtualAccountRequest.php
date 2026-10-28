<?php

namespace App\Models;

use App\Services\Nium\NiumVirtualAccountRequestIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class NiumVirtualAccountRequest extends Model
{
    protected $table = 'nium_va_requests';

    protected $primaryKey = 'operation_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'state_version' => 'integer', 'outcome' => 'array'];
    }

    public function identity(): NiumVirtualAccountRequestIdentity
    {
        return NiumVirtualAccountRequestIdentity::fromSnapshot($this->snapshot);
    }

    public function matchesIdentity(NiumVirtualAccountRequestIdentity $identity): bool
    {
        return hash_equals($this->payload_sha256, $identity->payloadHash())
            && $this->identity()->matches($identity);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(NiumVirtualAccountOperation::class, 'operation_id');
    }

    public function dispatchAttempt(): HasOne
    {
        return $this->hasOne(NiumVirtualAccountDispatchAttempt::class, 'operation_id', 'operation_id');
    }

    public function assignmentScope(): BelongsTo
    {
        return $this->belongsTo(NiumVirtualAccountAssignmentScope::class, 'scope_key', 'scope_key');
    }
}
