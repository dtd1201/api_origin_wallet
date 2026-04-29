<?php

namespace App\Services\Wise;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Http\Client\Response;
use RuntimeException;

class WiseService
{
    public function get(string $path, array $query = [], ?User $user = null, ?int $relatedTransferId = null): Response
    {
        return $this->client($user)->get($path, $query, $user, $relatedTransferId);
    }

    public function post(string $path, array $payload = [], ?User $user = null, ?int $relatedTransferId = null): Response
    {
        return $this->client($user)->post($path, $payload, $user, $relatedTransferId);
    }

    public function put(string $path, array $payload = [], ?User $user = null, ?int $relatedTransferId = null): Response
    {
        return $this->client($user)->put($path, $payload, $user, $relatedTransferId);
    }

    public function delete(string $path, array $payload = [], ?User $user = null, ?int $relatedTransferId = null): Response
    {
        return $this->client($user)->delete($path, $payload, $user, $relatedTransferId);
    }

    public function profileId(User $user): int
    {
        $providerAccount = $this->providerAccount($user);

        foreach ([
            $providerAccount?->external_customer_id,
            $providerAccount?->external_account_id,
            $this->metadataValue($providerAccount, 'profile_id'),
            $this->metadataValue($providerAccount, 'wise_profile_id'),
            $this->metadataValue($providerAccount, 'sender_profile_id'),
        ] as $candidate) {
            if (is_numeric($candidate)) {
                return (int) $candidate;
            }
        }

        throw new RuntimeException('Wise profile id is not configured for this user.');
    }

    public function path(string $template, array $replacements = []): string
    {
        $path = $template;

        foreach ($replacements as $key => $value) {
            $path = str_replace('{'.$key.'}', urlencode((string) $value), $path);
        }

        return $path;
    }

    private function client(?User $user = null): ProviderHttpClient
    {
        return new ProviderHttpClient(
            provider: $this->provider(),
            serviceConfigKey: 'wise',
            headers: array_filter([
                'Authorization' => $this->authorizationHeader($user),
            ], static fn ($value) => filled($value)),
        );
    }

    private function authorizationHeader(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $providerAccount = $this->providerAccount($user);

        foreach ([
            'access_token',
            'wise_access_token',
            'api_token',
            'personal_token',
            'user_token',
        ] as $key) {
            $token = $this->metadataValue($providerAccount, $key);

            if (filled($token)) {
                return 'Bearer '.$token;
            }
        }

        return null;
    }

    private function provider(): IntegrationProvider
    {
        return IntegrationProvider::query()->firstOrCreate(
            ['code' => 'wise'],
            [
                'name' => 'Wise',
                'status' => 'active',
            ]
        );
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

    private function metadataValue(?UserProviderAccount $providerAccount, string $key): mixed
    {
        if (! $providerAccount instanceof UserProviderAccount) {
            return null;
        }

        $metadata = (array) ($providerAccount->metadata ?? []);

        return $metadata[$key]
            ?? $metadata['completion_payload'][$key]
            ?? $metadata['completion_payload']['metadata'][$key]
            ?? null;
    }
}
