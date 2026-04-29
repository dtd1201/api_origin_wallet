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

    public function integrationConfig(): array
    {
        return (array) config('integrations.providers.'.strtolower($this->code), []);
    }

    public function serviceConfig(): array
    {
        return (array) config('services.'.strtolower($this->code), []);
    }

    public function supportsOnboarding(): bool
    {
        return isset($this->integrationConfig()['onboarding']);
    }

    public function supportsBeneficiaries(): bool
    {
        return isset($this->integrationConfig()['beneficiary']);
    }

    public function supportsDataSync(): bool
    {
        return isset($this->integrationConfig()['data_sync']);
    }

    public function supportsQuotes(): bool
    {
        return isset($this->integrationConfig()['quote']);
    }

    public function supportsTransfers(): bool
    {
        return isset($this->integrationConfig()['transfer']);
    }

    public function supportsWebhooks(): bool
    {
        return isset($this->integrationConfig()['webhook']);
    }

    public function isConfigured(): bool
    {
        return filled($this->serviceConfig()['base_url'] ?? null);
    }

    public function isAvailableForOnboarding(): bool
    {
        return $this->status === 'active'
            && $this->supportsOnboarding();
    }

    public function assertSupportsCapability(string $capability): void
    {
        $message = match ($capability) {
            'onboarding' => 'This provider is not available for onboarding yet.',
            'beneficiary' => 'This provider does not support beneficiaries yet.',
            'data_sync' => 'This provider does not support account sync yet.',
            'quote' => 'This provider does not support FX quotes yet.',
            'transfer' => 'This provider does not support transfers yet.',
            'webhook' => 'This provider does not support webhooks yet.',
            default => 'This provider capability is not available.',
        };

        $supported = match ($capability) {
            'onboarding' => $this->supportsOnboarding() && $this->status === 'active',
            'beneficiary' => $this->supportsBeneficiaries() && $this->isConfigured(),
            'data_sync' => $this->supportsDataSync() && $this->isConfigured(),
            'quote' => $this->supportsQuotes() && $this->isConfigured(),
            'transfer' => $this->supportsTransfers() && $this->isConfigured(),
            'webhook' => $this->supportsWebhooks() && $this->isConfigured(),
            default => false,
        };

        if (! $supported) {
            throw new \RuntimeException($message);
        }
    }

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

    public function kycProviderSubmissions(): HasMany
    {
        return $this->hasMany(KycProviderSubmission::class, 'provider_id');
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
