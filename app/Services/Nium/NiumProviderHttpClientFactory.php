<?php

namespace App\Services\Nium;

use App\Models\IntegrationProvider;
use App\Services\Integrations\ProviderHttpClient;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Str;
use RuntimeException;

final class NiumProviderHttpClientFactory
{
    public function __construct(
        private readonly SensitiveDataSanitizer $sensitiveDataSanitizer,
        private readonly NiumSafeValueProjector $safeValueProjector,
    ) {}

    public function make(IntegrationProvider $provider): ProviderHttpClient
    {
        if (
            (int) $provider->getKey() !== NiumCustomerRetryService::PROVIDER_ID
            || strtolower(trim((string) $provider->code)) !== 'nium'
            || $provider->status !== 'active'
            || ! $provider->isConfigured()
        ) {
            throw new RuntimeException('Nium customer retry provider is unavailable.');
        }

        return new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'nium',
            headers: [
                'x-request-id' => (string) Str::uuid(),
            ],
            sensitiveDataSanitizer: $this->sensitiveDataSanitizer,
            niumSafeValueProjector: $this->safeValueProjector,
        );
    }
}
