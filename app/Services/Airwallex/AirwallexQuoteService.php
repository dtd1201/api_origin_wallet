<?php

namespace App\Services\Airwallex;

use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\Contracts\QuoteProvider;
use RuntimeException;

class AirwallexQuoteService implements QuoteProvider
{
    public function createQuote(IntegrationProvider $provider, User $user, array $payload): FxQuote
    {
        throw new RuntimeException('Airwallex quote API is not configured yet.');
    }
}
