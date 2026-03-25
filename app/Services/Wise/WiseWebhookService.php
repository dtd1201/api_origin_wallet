<?php

namespace App\Services\Wise;

use App\Models\IntegrationProvider;
use App\Services\Integrations\Contracts\WebhookProvider;
use Illuminate\Http\Request;
use RuntimeException;

class WiseWebhookService implements WebhookProvider
{
    public function handleWebhook(IntegrationProvider $provider, Request $request): array
    {
        throw new RuntimeException('Wise webhook handling is not configured yet.');
    }
}
