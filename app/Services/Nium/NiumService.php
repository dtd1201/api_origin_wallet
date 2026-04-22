<?php

namespace App\Services\Nium;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Http\Client\Response;
use RuntimeException;

class NiumService
{
    public function get(string $path, array $query = [], ?User $user = null): Response
    {
        return $this->client()->get($path, $query, $user);
    }

    public function post(string $path, array $payload = [], ?User $user = null, ?int $relatedTransferId = null): Response
    {
        return $this->client()->post($path, $payload, $user, $relatedTransferId);
    }

    public function put(string $path, array $payload = [], ?User $user = null): Response
    {
        return $this->client()->put($path, $payload, $user);
    }

    public function delete(string $path, array $payload = [], ?User $user = null): Response
    {
        return $this->client()->delete($path, $payload, $user);
    }

    public function customerId(User $user): string
    {
        $providerAccount = $this->providerAccount($user);

        if (filled($providerAccount?->external_customer_id)) {
            return (string) $providerAccount->external_customer_id;
        }

        $metadata = (array) ($providerAccount?->metadata ?? []);

        foreach (['customer_id', 'customer_hash_id', 'nium_customer_id'] as $key) {
            if (filled($metadata[$key] ?? null)) {
                return (string) $metadata[$key];
            }
        }

        throw new RuntimeException('Nium customer id is not configured for this user.');
    }

    public function walletId(User $user): string
    {
        $providerAccount = $this->providerAccount($user);

        if (filled($providerAccount?->external_account_id)) {
            return (string) $providerAccount->external_account_id;
        }

        $metadata = (array) ($providerAccount?->metadata ?? []);

        foreach (['wallet_id', 'wallet_hash_id', 'nium_wallet_id'] as $key) {
            if (filled($metadata[$key] ?? null)) {
                return (string) $metadata[$key];
            }
        }

        throw new RuntimeException('Nium wallet id is not configured for this user.');
    }

    public function clientId(): string
    {
        $clientId = (string) config('services.nium.client_id', '');

        if ($clientId === '') {
            throw new RuntimeException('Nium client id is not configured.');
        }

        return $clientId;
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
            serviceConfigKey: 'nium',
        );
    }

    private function provider(): IntegrationProvider
    {
        return IntegrationProvider::query()->firstOrCreate(
            ['code' => 'nium'],
            [
                'name' => 'Nium',
                'status' => 'active',
            ]
        );
    }

    private function providerAccount(User $user): ?UserProviderAccount
    {
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
