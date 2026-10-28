<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NiumVirtualAccountOperation extends Model
{
    public const CLAIMED = 'claimed';

    public const PREFLIGHT_FAILED = 'preflight_failed';

    public const SUBMITTING = 'submitting';

    public const PENDING = 'pending';

    public const ASSIGNED = 'assigned';

    public const UNKNOWN = 'unknown';

    public const FAILED = 'failed';

    public const MANUAL_REVIEW = 'manual_review';

    protected $fillable = [
        'user_provider_account_id',
        'nium_virtual_account_id',
        'api_request_log_id',
        'operation_id',
        'currency',
        'account_category',
        'account_type',
        'bank_name',
        'status',
        'provider_payment_id',
        'baseline_payment_ids',
        'baseline_complete',
        'resolution_code',
        'claimed_at',
        'submitted_at',
        'resolved_at',
        'legacy_target_snapshot',
        'legacy_provider_request_id',
    ];

    protected $hidden = ['legacy_target_snapshot', 'legacy_provider_request_id'];

    protected function casts(): array
    {
        return [
            'baseline_payment_ids' => 'array',
            'baseline_complete' => 'boolean',
            'claimed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
            'legacy_target_snapshot' => 'encrypted:array',
        ];
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(UserProviderAccount::class, 'user_provider_account_id');
    }

    public function virtualAccount(): BelongsTo
    {
        return $this->belongsTo(NiumVirtualAccount::class, 'nium_virtual_account_id');
    }

    public function apiRequestLog(): BelongsTo
    {
        return $this->belongsTo(ApiRequestLog::class);
    }
}
