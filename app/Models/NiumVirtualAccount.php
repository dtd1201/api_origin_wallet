<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NiumVirtualAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_provider_account_id',
        'provider_payment_id',
        'virtual_account_reference',
        'currency',
        'account_category',
        'account_type',
        'status',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(UserProviderAccount::class, 'user_provider_account_id');
    }
}
