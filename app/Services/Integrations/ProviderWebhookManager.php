<?php

namespace App\Services\Integrations;

use App\Models\IntegrationProvider;
use Illuminate\Http\Request;

class ProviderWebhookManager
{
    public function __construct(
        private readonly ProviderRegistry $registry,
    ) {
    }

    public function handle(IntegrationProvider $provider, Request $request): array
    {
        return $this->registry
            ->resolveWebhookProvider($provider)
            ->handleWebhook($provider, $request);
    }
}
