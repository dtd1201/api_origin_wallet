<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationProvider extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'code',
        'name',
        'status',
    ];

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function userAccounts(): HasMany
    {
        return $this->hasMany(UserProviderAccount::class, 'provider_id');
    }

    public function userIntegrationLinks(): HasMany
    {
        return $this->hasMany(UserIntegrationLink::class, 'provider_id');
    }

    public function userIntegrationRequests(): HasMany
    {
        return $this->hasMany(UserIntegrationRequest::class, 'provider_id');
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class, 'provider_id');
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class, 'provider_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(Balance::class, 'provider_id');
    }

    public function fxQuotes(): HasMany
    {
        return $this->hasMany(FxQuote::class, 'provider_id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'provider_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'provider_id');
    }

    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class, 'provider_id');
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class, 'provider_id');
    }

    public function apiRequestLogs(): HasMany
    {
        return $this->hasMany(ApiRequestLog::class, 'provider_id');
    }
}
