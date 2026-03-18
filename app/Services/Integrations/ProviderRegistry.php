<?php

namespace App\Services\Integrations;

use App\Models\IntegrationProvider;
use App\Services\Integrations\Contracts\BeneficiaryProvider;
use App\Services\Integrations\Contracts\OnboardingProvider;
use App\Services\Integrations\Contracts\DataSyncProvider;
use App\Services\Integrations\Contracts\QuoteProvider;
use App\Services\Integrations\Contracts\TransferProvider;
use App\Services\Integrations\Contracts\WebhookProvider;
use InvalidArgumentException;

class ProviderRegistry
{
    public function resolveOnboardingProvider(IntegrationProvider $provider): OnboardingProvider
    {
        $className = config('integrations.providers.'.strtolower($provider->code).'.onboarding');

        if (! is_string($className) || ! is_a($className, OnboardingProvider::class, true)) {
            throw new InvalidArgumentException("Unsupported onboarding provider [{$provider->code}].");
        }

        return app($className);
    }

    public function resolveWebhookProvider(IntegrationProvider $provider): WebhookProvider
    {
        $className = config('integrations.providers.'.strtolower($provider->code).'.webhook');

        if (! is_string($className) || ! is_a($className, WebhookProvider::class, true)) {
            throw new InvalidArgumentException("Unsupported webhook provider [{$provider->code}].");
        }

        return app($className);
    }

    public function resolveDataSyncProvider(IntegrationProvider $provider): DataSyncProvider
    {
        $className = config('integrations.providers.'.strtolower($provider->code).'.data_sync');

        if (! is_string($className) || ! is_a($className, DataSyncProvider::class, true)) {
            throw new InvalidArgumentException("Unsupported data sync provider [{$provider->code}].");
        }

        return app($className);
    }

    public function resolveTransferProvider(IntegrationProvider $provider): TransferProvider
    {
        $className = config('integrations.providers.'.strtolower($provider->code).'.transfer');

        if (! is_string($className) || ! is_a($className, TransferProvider::class, true)) {
            throw new InvalidArgumentException("Unsupported transfer provider [{$provider->code}].");
        }

        return app($className);
    }

    public function resolveQuoteProvider(IntegrationProvider $provider): QuoteProvider
    {
        $className = config('integrations.providers.'.strtolower($provider->code).'.quote');

        if (! is_string($className) || ! is_a($className, QuoteProvider::class, true)) {
            throw new InvalidArgumentException("Unsupported quote provider [{$provider->code}].");
        }

        return app($className);
    }

    public function resolveBeneficiaryProvider(IntegrationProvider $provider): BeneficiaryProvider
    {
        $className = config('integrations.providers.'.strtolower($provider->code).'.beneficiary');

        if (! is_string($className) || ! is_a($className, BeneficiaryProvider::class, true)) {
            throw new InvalidArgumentException("Unsupported beneficiary provider [{$provider->code}].");
        }

        return app($className);
    }
}
