<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class NiumRfiCase extends Model
{
    protected $fillable = [
        'provider_id', 'user_provider_account_id', 'transaction_id', 'webhook_event_id',
        'scope', 'origin', 'provider_reference_fingerprint', 'status', 'evidence', 'response_draft',
        'supporting_file_ids', 'contract_gate', 'submission_state', 'approved_by',
        'approved_at', 'claimed_at', 'provider_response_evidence', 'reconciled_at',
        'user_explanation', 'revision_reason', 'sent_to_user_at', 'user_submitted_at', 'admin_reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array', 'response_draft' => 'array', 'supporting_file_ids' => 'array',
            'provider_response_evidence' => 'array', 'approved_at' => 'datetime',
            'claimed_at' => 'datetime', 'reconciled_at' => 'datetime',
            'sent_to_user_at' => 'datetime', 'user_submitted_at' => 'datetime', 'admin_reviewed_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(UserProviderAccount::class, 'user_provider_account_id');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(KycDocument::class, 'nium_rfi_case_documents')
            ->withPivot(['attached_by', 'detached_at'])->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(NiumRfiCaseEvent::class)->latest('id');
    }
}
