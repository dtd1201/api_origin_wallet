<?php

namespace App\Services\PingPong;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Http\Client\Response;

class PingPongService
{
    public function get(string $path, array $query = [], ?User $user = null, ?string $onBehalfOf = null): Response
    {
        return $this->client($this->resolveOnBehalfOf($user, $onBehalfOf))->get($path, $query, $user);
    }

    public function post(string $path, array $payload = [], ?User $user = null, ?string $onBehalfOf = null): Response
    {
        return $this->client($this->resolveOnBehalfOf($user, $onBehalfOf))->post($path, $payload, $user);
    }

    public function createRecipient(array $payload, ?User $user = null, ?string $onBehalfOf = null): Response
    {
        return $this->post(
            path: (string) config('services.pingpong.recipient_create_endpoint'),
            payload: $payload,
            user: $user,
            onBehalfOf: $onBehalfOf,
        );
    }

    public function updateRecipient(array $payload, ?User $user = null, ?string $onBehalfOf = null): Response
    {
        return $this->post(
            path: (string) config('services.pingpong.recipient_update_endpoint'),
            payload: $payload,
            user: $user,
            onBehalfOf: $onBehalfOf,
        );
    }

    public function deleteRecipient(string $bizId, ?User $user = null, ?string $onBehalfOf = null): Response
    {
        return $this->post(
            path: (string) config('services.pingpong.recipient_delete_endpoint'),
            payload: ['biz_id' => $bizId],
            user: $user,
            onBehalfOf: $onBehalfOf,
        );
    }

    public function createPayment(array $payload, ?User $user = null, ?string $onBehalfOf = null): Response
    {
        return $this->post(
            path: (string) config('services.pingpong.payment_create_endpoint'),
            payload: $payload,
            user: $user,
            onBehalfOf: $onBehalfOf,
        );
    }

    public function queryPayment(array $query, ?User $user = null, ?string $onBehalfOf = null): Response
    {
        return $this->get(
            path: (string) config('services.pingpong.payment_query_endpoint'),
            query: $query,
            user: $user,
            onBehalfOf: $onBehalfOf,
        );
    }

    private function client(?string $onBehalfOf = null): ProviderHttpClient
    {
        return new ProviderHttpClient(
            provider: $this->provider(),
            serviceConfigKey: 'pingpong',
            headers: array_filter([
                'on-behalf-of' => $onBehalfOf,
            ], static fn (?string $value) => filled($value)),
        );
    }

    private function provider(): IntegrationProvider
    {
        return IntegrationProvider::query()->firstOrCreate(
            ['code' => 'pingpong'],
            [
                'name' => 'PingPong',
                'status' => 'active',
            ]
        );
    }

    private function resolveOnBehalfOf(?User $user, ?string $onBehalfOf): ?string
    {
        if (filled($onBehalfOf)) {
            return $onBehalfOf;
        }

        if ($user === null) {
            return null;
        }

        $providerAccount = $user->relationLoaded('providerAccounts')
            ? $user->providerAccounts->firstWhere('provider_id', $this->provider()->id)
            : $user->providerAccounts()->where('provider_id', $this->provider()->id)->latest('id')->first();

        if (! $providerAccount instanceof UserProviderAccount) {
            return null;
        }

        $metadata = (array) ($providerAccount->metadata ?? []);

        foreach ([
            'client_id',
            'managed_client_id',
            'on_behalf_of',
            'pingpong_client_id',
        ] as $key) {
            if (filled($metadata[$key] ?? null)) {
                return (string) $metadata[$key];
            }
        }

        return filled($providerAccount->external_customer_id)
            ? (string) $providerAccount->external_customer_id
            : null;
    }
}
