<?php

namespace App\Services\Integrations\Contracts;

use App\Models\IntegrationProvider;
use App\Models\WebhookEvent;

interface ReprocessesWebhookEvent
{
    public function reprocessWebhookEvent(IntegrationProvider $provider, WebhookEvent $event): WebhookEvent;
}
