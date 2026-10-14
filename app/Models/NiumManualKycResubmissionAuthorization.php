<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NiumManualKycResubmissionAuthorization extends Model
{
    public const PENDING = 'PENDING';
    public const CONSUMED = 'CONSUMED';
    public const INVALIDATED = 'INVALIDATED';
    public const TERMINAL = 'TERMINAL';

    protected $guarded = [];

    protected $hidden = ['reason'];

    protected function casts(): array
    {
        return [
            'correction_version' => 'integer',
            'reason' => 'encrypted',
            'authorized_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'terminal_at' => 'datetime',
        ];
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(UserProviderAccount::class, 'user_provider_account_id');
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(KycProfileCorrection::class, 'kyc_profile_correction_id');
    }
}
