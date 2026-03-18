<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_no',
        'user_id',
        'provider_id',
        'source_bank_account_id',
        'beneficiary_id',
        'external_transfer_id',
        'external_payment_id',
        'transfer_type',
        'source_currency',
        'target_currency',
        'source_amount',
        'target_amount',
        'fx_rate',
        'fee_amount',
        'fee_currency',
        'purpose_code',
        'reference_text',
        'client_reference',
        'status',
        'failure_code',
        'failure_reason',
        'submitted_at',
        'completed_at',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'source_amount' => 'decimal:8',
            'target_amount' => 'decimal:8',
            'fx_rate' => 'decimal:10',
            'fee_amount' => 'decimal:8',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'raw_data' => 'array',
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

    public function sourceBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'source_bank_account_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function apiRequestLogs(): HasMany
    {
        return $this->hasMany(ApiRequestLog::class, 'related_transfer_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(TransferApproval::class);
    }
}
