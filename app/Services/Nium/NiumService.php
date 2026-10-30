<?php

namespace App\Services\Nium;

use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
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
    ): Response {
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
        if ($operation === 'transfer_money') {
            if (DB::connection()->transactionLevel() !== 0) {
                throw new RuntimeException('Remittance dispatch requires committed authorization outside transactions.');
            }
            $requestId = $externalReference === null ? '' : substr($externalReference, 5);
            if (! str_starts_with((string) $externalReference, 'nium-') || ! Str::isUuid($requestId)) {
                throw new RuntimeException('Remittance requires a durable NIUM operation UUID.');
            }

            DB::transaction(function () use ($relatedTransferId, $externalReference, $user, $requestId, $payload, $path): void {
                $locked = Transfer::query()->lockForUpdate()->findOrFail($relatedTransferId);
                $execution = $locked->raw_data['nium_execution'] ?? [];
                if ($user === null || $locked->user_id !== $user->id
                    || strtolower((string) $locked->provider?->code) !== 'nium'
                    || $locked->provider_operation_key !== $externalReference || $locked->status !== 'submitting'
                    || ($execution['operation_key'] ?? null) !== $externalReference
                    || ($execution['request_uuid'] ?? null) !== $requestId
                    || isset($execution['dispatch_started_at'])) {
                    throw new RuntimeException('Remittance dispatch authorization has already been consumed or does not match.');
                }
                $hold = LedgerEntry::query()->where('reference', "transfer:{$locked->id}:hold")->first();
                if (($hold?->raw_data['operation_key'] ?? null) !== $externalReference
                    || LedgerEntry::query()->whereIn('reference', ["transfer:{$locked->id}:release", "transfer:{$locked->id}:debit"])->exists()) {
                    throw new RuntimeException('Remittance dispatch requires its live owned reservation.');
                }
                if ($locked->target_currency === 'SGD') {
                    app(NiumSgdLocalPayoutAuthorization::class)->assertDispatchSnapshot($locked);
                    $approved = $locked->raw_data['sgd_local_approval'];
                    $reservationAmount = $approved['financial_terms']['reservation_amount'] ?? $approved['financial_terms']['total_wallet_debit'];
                    $balance = Balance::query()->whereKey($hold->balance_id)->where('user_id', $locked->user_id)
                        ->where('provider_id', $locked->provider_id)->where('currency', $locked->source_currency)
                        ->where('external_account_id', $approved['provider_context']['wallet'])->lockForUpdate()->first();
                    if ($balance === null || bccomp($balance->reserved_balance, $reservationAmount, 8) < 0) {
                        throw new RuntimeException('SGD dispatch requires sufficient reserved funds in its approved source wallet.');
                    }
                    $expectedPath = $this->path((string) config('services.nium.transfer_endpoint'), [
                        'client' => $approved['provider_context']['client'],
                        'customer' => $approved['provider_context']['customer'],
                        'wallet' => $approved['provider_context']['wallet'],
                    ]);
                    $expected = NiumSgdLocalRemittancePayload::fromSnapshot($approved);
                    if ($path !== $expectedPath || $payload !== $expected
                        || ! NiumPayoutFxLockContract::sameBinding($hold->raw_data['financial_authorization'] ?? null, $approved['financial_terms'])
                        || bccomp((string) $hold->amount, '-'.$reservationAmount, 8) !== 0) {
                        throw new RuntimeException('SGD wire intent or funding no longer matches the approved operation.');
                    }
                }
                $execution['dispatch_started_at'] = now()->toISOString();
                $locked->update(['raw_data' => array_merge($locked->raw_data, ['nium_execution' => $execution])]);
            });

            return $this->client($operation, $externalReference, ['x-request-id' => $requestId])
                ->post($path, $payload, $user, $relatedTransferId);
        }

        return $this->client($operation, $externalReference)->post($path, $payload, $user, $relatedTransferId);
    }

    public function getWithRequestId(string $path, array $query, User $user, string $requestId, string $operation, ?string $externalReference = null, ?int $relatedTransferId = null): Response
    {
        if (! Str::isUuid($requestId)) {
            throw new RuntimeException('Nium x-request-id must be a UUID.');
        }

        return $this->client($operation, $externalReference, additionalHeaders: ['x-request-id' => $requestId])->get($path, $query, $user, $relatedTransferId);
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

    public function postSensitiveWithRequestId(
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

        return $this->client($operation, $externalReference, ['x-request-id' => $requestId], false)
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

    public function postTransactionSimulation(
        string $path,
        array $payload,
        User $user,
        string $externalReference
    ): Response {
        return $this->client(
            'transaction_simulation_transition',
            $externalReference,
        )->post($path, $payload, $user);
    }

    public function put(string $path, array $payload = [], ?User $user = null): Response
    {
        return $this->client()->put($path, $payload, $user);
    }

    public function putWithRequestId(
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
            ->put($path, $payload, $user);
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

    public function assertConfigured(): void
    {
        if (! $this->provider()->isConfigured()) {
            throw new RuntimeException('Nium integration configuration is incomplete or unsafe.');
        }
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
        bool $persistRawExchange = true,
    ): ProviderHttpClient {
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
            persistRawExchange: $persistRawExchange,
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
