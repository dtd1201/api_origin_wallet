<?php

namespace App\Services\Integrations;

use App\Models\IntegrationProvider;
use App\Support\PrimaryProvider;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;

class IntegrationProviderCatalog
{
    private const ACTIVE_PROVIDERS_CACHE_KEY = 'integration_provider_catalog:active:nium:v1';

    /**
     * @return EloquentCollection<int, IntegrationProvider>
     */
    public function activeProviders(): EloquentCollection
    {
        return Cache::remember(
            self::ACTIVE_PROVIDERS_CACHE_KEY,
            now()->addSeconds($this->cacheTtlSeconds()),
            fn (): EloquentCollection => IntegrationProvider::query()
                ->where('status', 'active')
                ->where('code', PrimaryProvider::code())
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'logo_url', 'status'])
        );
    }

    public function primaryProvider(): IntegrationProvider
    {
        return PrimaryProvider::resolve();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activePublicPayloads(): array
    {
        return $this->activeProviders()
            ->map(fn (IntegrationProvider $provider): array => $provider->publicPayload())
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeSummaryPayloads(): array
    {
        return $this->activeProviders()
            ->map(fn (IntegrationProvider $provider): array => $provider->summaryPayload())
            ->values()
            ->all();
    }

    public function flush(): void
    {
        Cache::forget(self::ACTIVE_PROVIDERS_CACHE_KEY);
    }

    private function cacheTtlSeconds(): int
    {
        return max(5, min(300, (int) config('services.provider_catalog.cache_ttl_seconds', 60)));
    }
}
