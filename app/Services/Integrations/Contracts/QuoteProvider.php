<?php

namespace App\Services\Integrations\Contracts;

use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\User;

interface QuoteProvider
{
    public function createQuote(IntegrationProvider $provider, User $user, array $payload): FxQuote;
}
