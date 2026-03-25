<?php

namespace App\Services\Integrations\DataObjects;

use App\Models\UserProviderAccount;

class ProviderOnboardingResult
{
    public function __construct(
        public readonly ?UserProviderAccount $providerAccount,
        public readonly string $status,
        public readonly string $nextAction,
        public readonly string $message,
        public readonly ?string $redirectUrl = null,
        public readonly string $actionType = 'direct_api',
        public readonly array $metadata = [],
    ) {
    }

    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'next_action' => $this->nextAction,
            'message' => $this->message,
            'redirect_url' => $this->redirectUrl,
            'action_type' => $this->actionType,
            'metadata' => $this->metadata,
        ], static fn ($value) => $value !== null);
    }
}
