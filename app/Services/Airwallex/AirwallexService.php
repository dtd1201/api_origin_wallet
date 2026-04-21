<?php

namespace App\Services\Airwallex;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Http\Client\Response;

class AirwallexService
{
    public function get(string $path, array $query = [], ?User $user = null, ?string $onBehalfOf = null): Response
    {
        return $this->client($user, $onBehalfOf)->get($path, $query, $user);
    }

    public function post(string $path, array $payload = [], ?User $user = null, ?string $onBehalfOf = null, ?int $relatedTransferId = null): Response
    {
        return $this->client($user, $onBehalfOf)->post($path, $payload, $user, $relatedTransferId);
    }

    public function put(string $path, array $payload = [], ?User $user = null, ?string $onBehalfOf = null): Response
    {
        return $this->client($user, $onBehalfOf)->put($path, $payload, $user);
    }

    public function delete(string $path, array $payload = [], ?User $user = null, ?string $onBehalfOf = null): Response
    {
        return $this->client($user, $onBehalfOf)->delete($path, $payload, $user);
    }

    private function client(?User $user = null, ?string $onBehalfOf = null): ProviderHttpClient
    {
        return new ProviderHttpClient(
            provider: $this->provider(),
            serviceConfigKey: 'airwallex',
            headers: array_filter([
                'x-api-version' => config('services.airwallex.api_version'),
                'x-on-behalf-of' => $this->resolveOnBehalfOf($user, $onBehalfOf),
                'x-sca-token' => $this->resolveScaToken($user),
            ], static fn ($value) => filled($value)),
        );
    }

    private function provider(): IntegrationProvider
    {
        return IntegrationProvider::query()->firstOrCreate(
            ['code' => 'airwallex'],
            [
                'name' => 'Airwallex',
                'status' => 'active',
            ]
        );
    }

    private function resolveOnBehalfOf(?User $user, ?string $onBehalfOf): ?string
    {
        if (filled($onBehalfOf)) {
            return $onBehalfOf;
        }

        $providerAccount = $this->providerAccount($user);

        if (! $providerAccount instanceof UserProviderAccount) {
            return null;
        }

        $metadata = (array) ($providerAccount->metadata ?? []);

        foreach ([
            'on_behalf_of',
            'open_id',
            'account_open_id',
            'connected_account_open_id',
            'airwallex_open_id',
        ] as $key) {
            $value = $this->metadataValue($metadata, $key);

            if (filled($value)) {
                return (string) $value;
            }
        }

        if (filled($providerAccount->external_account_id) && str_starts_with((string) $providerAccount->external_account_id, 'acct_')) {
            return (string) $providerAccount->external_account_id;
        }

        return null;
    }

    private function resolveScaToken(?User $user): ?string
    {
        $providerAccount = $this->providerAccount($user);

        if (! $providerAccount instanceof UserProviderAccount) {
            return null;
        }

        $metadata = (array) ($providerAccount->metadata ?? []);

        foreach ([
            'sca_token',
            'airwallex_sca_token',
            'authorization_token',
        ] as $key) {
            $value = $this->metadataValue($metadata, $key);

            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function metadataValue(array $metadata, string $key): mixed
    {
        return $metadata[$key]
            ?? $metadata['completion_payload'][$key]
            ?? $metadata['completion_payload']['metadata'][$key]
            ?? null;
    }

    private function providerAccount(?User $user): ?UserProviderAccount
    {
        if ($user === null) {
            return null;
        }

        return $user->relationLoaded('providerAccounts')
            ? $user->providerAccounts
                ->where('provider_id', $this->provider()->id)
                ->sortByDesc('id')
                ->first()
            : $user->providerAccounts()
                ->where('provider_id', $this->provider()->id)
                ->latest('id')
                ->first();
    }
}
