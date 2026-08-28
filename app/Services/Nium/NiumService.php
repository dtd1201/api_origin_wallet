<?php

namespace App\Services\Nium;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

class NiumService
{
    public function __construct(
        private readonly NiumProviderAccountStateService $stateService,
    ) {}

    public function get(
        string $path,
        array $query = [],
        ?User $user = null,
        ?string $operation = null,
        ?string $externalReference = null,
    ): Response
    {
        return $this->client($operation, $externalReference)->get($path, $query, $user);
    }

    public function post(
        string $path,
        array $payload = [],
        ?User $user = null,
        ?int $relatedTransferId = null,
        ?string $operation = null,
        ?string $externalReference = null,
    ): Response {
        return $this->client($operation, $externalReference)->post($path, $payload, $user, $relatedTransferId);
    }

    public function postWithRequestId(
        string $path,
        array $payload,
        User $user,
        string $requestId,
        ?string $operation = null,
        ?string $externalReference = null,
    ): Response {
        if (! Str::isUuid($requestId)) {
            throw new RuntimeException('Nium x-request-id must be a UUID.');
        }

        return $this->client($operation, $externalReference, ['x-request-id' => $requestId])
            ->post($path, $payload, $user);
    }

    public function postOnboardingSimulation(
        string $path,
        array $payload,
        User $user,
        string $externalReference,
        string $clientName,
    ): Response {
        return $this->client(
            'onboarding_simulation_submit_kyc',
            $externalReference,
            ['x-client-name' => $clientName],
        )->post($path, $payload, $user);
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
        $providerAccount = $this->stateService->assertEligible($user);

        if (filled($providerAccount?->external_customer_id)) {
            return (string) $providerAccount->external_customer_id;
        }

        throw new RuntimeException('Nium customer id is not available from an authenticated Nium response.');
    }

    public function walletId(User $user): string
    {
        $providerAccount = $this->stateService->assertEligible($user);

        if (filled($providerAccount?->external_account_id)) {
            return (string) $providerAccount->external_account_id;
        }

        throw new RuntimeException('Nium wallet id is not available from an authenticated Nium response.');
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
        $template = trim($template);

        if ($template === '' || ! str_starts_with($template, '/') || str_starts_with($template, '//')) {
            throw new RuntimeException('Nium endpoint must be a configured relative path.');
        }

        if (preg_match('/[\x00-\x20]/', $template) === 1 || preg_match('#^https?://#i', $template) === 1) {
            throw new RuntimeException('Nium endpoint contains an invalid scheme or whitespace.');
        }

        $replacements = $this->withOfficialPathAliases($replacements);
        $path = $template;

        foreach ($replacements as $key => $value) {
            if (! is_scalar($value) || trim((string) $value) === '') {
                throw new RuntimeException("Nium endpoint replacement [{$key}] is empty.");
            }

            $path = str_replace('{'.$key.'}', urlencode((string) $value), $path);
        }

        if (preg_match('/\{[^}]+\}/', $path) === 1) {
            throw new RuntimeException('Nium endpoint contains an unresolved placeholder.');
        }

        return $path;
    }

    private function withOfficialPathAliases(array $replacements): array
    {
        $aliases = [
            'client' => 'clientHashId',
            'customer' => 'customerHashId',
            'wallet' => 'walletHashId',
            'beneficiary' => 'beneficiaryHashId',
            'transfer' => 'systemReferenceNumber',
        ];

        foreach ($aliases as $legacy => $official) {
            if (array_key_exists($legacy, $replacements) && ! array_key_exists($official, $replacements)) {
                $replacements[$official] = $replacements[$legacy];
            }
        }

        return $replacements;
    }

    private function client(
        ?string $operation = null,
        ?string $externalReference = null,
        array $additionalHeaders = [],
    ): ProviderHttpClient
    {
        $provider = $this->provider();

        if (! $provider->isConfigured()) {
            throw new RuntimeException('Nium integration configuration is incomplete or unsafe.');
        }

        return new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'nium',
            headers: [
                'x-request-id' => (string) Str::uuid(),
                ...$additionalHeaders,
            ],
            operationalContext: array_filter([
                'operation' => $operation,
                'client_hash_id' => config('services.nium.client_id'),
                'external_reference' => $externalReference,
            ], static fn ($value): bool => $value !== null && $value !== ''),
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
}
