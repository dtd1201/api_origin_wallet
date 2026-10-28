<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NiumVirtualAccountDetailEnrichment extends Model
{
    protected $table = 'nium_va_detail_enrichments';

    protected $guarded = [];

    protected $hidden = ['claim_token'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer', 'operation_id' => 'integer', 'nium_virtual_account_id' => 'integer',
            'available_at' => 'datetime', 'lease_until' => 'datetime', 'delivery_until' => 'datetime',
        ];
    }
}
