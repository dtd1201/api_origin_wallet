<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NiumRfiCase extends Model
{
    protected $fillable = [
        'provider_id', 'user_provider_account_id', 'transaction_id', 'webhook_event_id',
        'scope', 'provider_reference_fingerprint', 'status', 'evidence', 'response_draft',
        'supporting_file_ids', 'contract_gate', 'submission_state', 'approved_by',
        'approved_at', 'claimed_at', 'provider_response_evidence', 'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array', 'response_draft' => 'array', 'supporting_file_ids' => 'array',
            'provider_response_evidence' => 'array', 'approved_at' => 'datetime',
            'claimed_at' => 'datetime', 'reconciled_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
