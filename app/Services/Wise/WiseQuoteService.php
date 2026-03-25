<?php

namespace App\Services\Wise;

use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\Contracts\QuoteProvider;
use RuntimeException;

class WiseQuoteService implements QuoteProvider
{
    public function createQuote(IntegrationProvider $provider, User $user, array $payload): FxQuote
    {
        throw new RuntimeException('Wise quote API is not configured yet.');
    }
}
