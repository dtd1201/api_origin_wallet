<?php

namespace App\Services\Currenxie;

use App\Models\IntegrationProvider;
use App\Services\Integrations\Contracts\ProviderPayloadMapper;
use App\Services\Integrations\Support\AbstractApiOnboardingProvider;

class CurrenxieOnboardingService extends AbstractApiOnboardingProvider
{
    protected function serviceConfigKey(): string
    {
        return 'currenxie';
    }

    protected function requestHeaders(): array
    {
        if (strtolower((string) config('services.currenxie.auth.mode', 'static_headers')) !== 'static_headers') {
            return [];
        }

        return [
            'X-API-KEY' => (string) config('services.currenxie.api_key'),
            'X-API-SECRET' => (string) config('services.currenxie.api_secret'),
        ];
    }

    protected function customerEndpoint(): string
    {
        return (string) config('services.currenxie.customer_endpoint');
    }

    protected function accountEndpoint(): string
    {
        return (string) config('services.currenxie.account_endpoint');
    }

    protected function payloadMapper(): ProviderPayloadMapper
    {
        return app(CurrenxiePayloadMapper::class);
    }
}
