<?php

namespace App\Services\Quotes;

use Illuminate\Support\Facades\Cache;

class PublicProviderRateCache
{
    private const VERSION_KEY = 'public_provider_rates:version';

    public function version(): int
    {
        return max(1, (int) Cache::get(self::VERSION_KEY, 1));
    }

    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, $this->version() + 1);
    }
}
