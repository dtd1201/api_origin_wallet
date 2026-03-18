<?php

namespace App\Services\Integrations;

use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\User;

class ProviderQuoteManager
{
    public function __construct(
        private readonly ProviderRegistry $registry,
    ) {
    }

    public function createQuote(IntegrationProvider $provider, User $user, array $payload): FxQuote
    {
        return $this->registry->resolveQuoteProvider($provider)->createQuote($provider, $user, $payload);
    }
}
