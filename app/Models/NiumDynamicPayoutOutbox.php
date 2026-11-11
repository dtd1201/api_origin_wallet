<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class NiumDynamicPayoutOutbox extends Model
{
    protected $table = 'nium_dynamic_payout_outbox';

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    // Preserve the actual claim/deadline instant in non-UTC PostgreSQL sessions.
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $hidden = ['claim_token', 'request_json', 'financial_evidence', 'reconciliation_token'];

    protected function casts(): array
    {
        return ['dispatchable' => 'boolean', 'attempts' => 'integer', 'request_json' => 'encrypted',
            'financial_evidence' => 'encrypted:array', 'reconciliation_attempts' => 'integer',
            'claimed_at' => 'immutable_datetime', 'submission_started_at' => 'immutable_datetime',
            'next_reconciliation_at' => 'immutable_datetime', 'reconciliation_claimed_at' => 'immutable_datetime'];
    }

    public function isDispatchable(): bool
    {
        return false;
    }
}
