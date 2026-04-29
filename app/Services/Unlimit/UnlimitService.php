<?php

namespace App\Services\Unlimit;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Http\Client\Response;

class UnlimitService
{
    public function get(string $path, array $query = [], ?User $user = null): Response
    {
        return $this->client()->get($path, $query, $user);
    }

    public function post(string $path, array $payload = [], ?User $user = null, ?int $relatedTransferId = null): Response
    {
        return $this->client()->post($path, $payload, $user, $relatedTransferId);
    }

    public function patch(string $path, array $payload = [], ?User $user = null, ?int $relatedTransferId = null): Response
    {
        return $this->client()->patch($path, $payload, $user, $relatedTransferId);
    }

    public function delete(string $path, array $payload = [], ?User $user = null): Response
    {
        return $this->client()->delete($path, $payload, $user);
    }

    public function paymentMethods(array $query = [], ?User $user = null): Response
    {
        return $this->get(
            path: (string) config('services.unlimit.payment_methods_endpoint'),
            query: $query,
            user: $user,
        );
    }

    public function path(string $template, array $replacements = []): string
    {
        $path = $template;

        foreach ($replacements as $key => $value) {
            $path = str_replace('{'.$key.'}', urlencode((string) $value), $path);
        }

        return $path;
    }

    private function client(): ProviderHttpClient
    {
        return new ProviderHttpClient(
            provider: $this->provider(),
            serviceConfigKey: 'unlimit',
        );
    }

    private function provider(): IntegrationProvider
    {
        return IntegrationProvider::query()->firstOrCreate(
            ['code' => 'unlimit'],
            [
                'name' => 'Unlimit',
                'status' => 'active',
            ]
        );
    }
}
