<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NiumVirtualAccountDetail extends Model
{
    protected $fillable = [
        'nium_virtual_account_id',
        'account_name',
        'bank_name',
        'bank_address',
        'routing_code_type',
        'routing_code_value',
    ];

    public function virtualAccount(): BelongsTo
    {
        return $this->belongsTo(NiumVirtualAccount::class, 'nium_virtual_account_id');
    }
}
