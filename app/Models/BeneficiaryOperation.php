<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BeneficiaryOperation extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['receipt', 'create_key', 'baseline_hash', 'request_hash', 'applied_hash'];

    protected function casts(): array
    {
        return ['receipt' => 'encrypted:array', 'new_beneficiary' => 'boolean', 'dispatch_steps' => 'array'];
    }
}
