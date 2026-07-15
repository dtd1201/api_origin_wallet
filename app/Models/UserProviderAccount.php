<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProviderAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider_id',
        'external_customer_id',
        'external_account_id',
        'external_reference',
        'account_name',
        'status',
        'provider_status',
        'provider_sub_status',
        'compliance_status',
        'rfi_status',
        'odd_status',
        'customer_id_verified_at',
        'wallet_id_verified_at',
        'provider_ids_verified_at',
        'provider_status_updated_at',
        'security_conflict_at',
        'security_conflict_reason',
        'reconciliation_status',
        'reconciliation_error',
        'reconciliation_requested_at',
        'reconciled_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'customer_id_verified_at' => 'datetime',
            'wallet_id_verified_at' => 'datetime',
            'provider_ids_verified_at' => 'datetime',
            'provider_status_updated_at' => 'datetime',
            'security_conflict_at' => 'datetime',
            'reconciliation_requested_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'provider_id');
    }
}
