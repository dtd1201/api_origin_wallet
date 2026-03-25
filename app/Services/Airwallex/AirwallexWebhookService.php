<?php

namespace App\Services\Airwallex;

use App\Models\IntegrationProvider;
use App\Services\Integrations\Contracts\WebhookProvider;
use Illuminate\Http\Request;
use RuntimeException;

class AirwallexWebhookService implements WebhookProvider
{
    public function handleWebhook(IntegrationProvider $provider, Request $request): array
    {
        throw new RuntimeException('Airwallex webhook handling is not configured yet.');
    }
}
