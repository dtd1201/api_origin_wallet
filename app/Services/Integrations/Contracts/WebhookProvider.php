<?php

namespace App\Services\Integrations\Contracts;

use App\Models\IntegrationProvider;
use Illuminate\Http\Request;

interface WebhookProvider
{
    public function handleWebhook(IntegrationProvider $provider, Request $request): array;
}
