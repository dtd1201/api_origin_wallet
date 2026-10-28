<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'provider_bank_name',
        'provider_account_type',
        'provider_account_category',
        'routing_code_type_2',
        'routing_code_value_2',
    ];

    protected $hidden = ['enrichment_version'];

    protected function casts(): array
    {
        return ['enrichment_version' => 'integer'];
    }

    protected function bankName(): Attribute
    {
        // Keep the original display fallback without storing the bank identifier twice.
        return Attribute::get(fn ($value, array $attributes) => $value ?? ($attributes['provider_bank_name'] ?? null));
    }

    public function virtualAccount(): BelongsTo
    {
        return $this->belongsTo(NiumVirtualAccount::class, 'nium_virtual_account_id');
    }
}
