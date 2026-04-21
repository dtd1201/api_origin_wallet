<?php

namespace App\Services\Tazapay;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Http\Client\Response;

class TazapayService
{
    public function get(string $path, array $query = [], ?User $user = null): Response
    {
        return $this->client($user)->get($path, $query, $user);
    }

    public function post(string $path, array $payload = [], ?User $user = null, ?int $relatedTransferId = null): Response
    {
        return $this->client($user)->post($path, $payload, $user, $relatedTransferId);
    }

    public function put(string $path, array $payload = [], ?User $user = null): Response
    {
        return $this->client($user)->put($path, $payload, $user);
    }

    private function client(?User $user = null): ProviderHttpClient
    {
        return new ProviderHttpClient(
            provider: $this->provider(),
            serviceConfigKey: 'tazapay',
            headers: array_filter([
                'tz-account-id' => $this->resolveTzAccountId($user),
            ], static fn ($value) => filled($value)),
        );
    }

    private function provider(): IntegrationProvider
    {
        return IntegrationProvider::query()->firstOrCreate(
            ['code' => 'tazapay'],
            [
                'name' => 'Tazapay',
                'status' => 'active',
            ]
        );
    }

    private function resolveTzAccountId(?User $user): ?string
    {
        $configuredAccountId = config('services.tazapay.tz_account_id');

        if (filled($configuredAccountId)) {
            return (string) $configuredAccountId;
        }

        if ($user === null) {
            return null;
        }

        $providerAccount = $this->providerAccount($user);

        if (! $providerAccount instanceof UserProviderAccount) {
            return null;
        }

        $metadata = (array) ($providerAccount->metadata ?? []);

        foreach (['tz_account_id', 'account_id', 'tazapay_account_id'] as $key) {
            $value = $metadata[$key] ?? null;

            if (filled($value)) {
                return (string) $value;
            }
        }

        return filled($providerAccount->external_account_id)
            ? (string) $providerAccount->external_account_id
            : null;
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
