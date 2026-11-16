<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Models\{Beneficiary, User, UserProviderAccount};
use App\Services\Nium\{NiumProviderAccountStateService, NiumService};
use App\Services\Wallet\Concurrency\OwnerFirst;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Cache, Crypt, DB};
use RuntimeException;
use Throwable;

/** Short-lived, sealed runtime evidence; never imports operator diagnostic flags. */
final class DynamicBeneficiaryDetailsEvidence
{
    public function __construct(private readonly NiumService $nium,
        private readonly NiumProviderAccountStateService $accounts,
        private readonly DynamicBeneficiaryReceivingSchema $schemas) {}

    public function snapshot(Beneficiary $beneficiary, UserProviderAccount $account, array $binding,
        array $registered, bool $refresh = false): array
    {
        DynamicBeneficiaryDetailsResponse::expected($registered); // Fail before I/O for insufficient local inputs.
        $scope = $this->scope($beneficiary, $account, $binding, $registered);
        $key = 'nium:dynamic:beneficiary:v2:'.hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
        $sealed = Cache::store('database')->get($key);
        if (is_string($sealed)) {
            // Corruption is a refusal, not an invitation to replace evidence.
            $cached = json_decode(Crypt::decryptString($sealed), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($cached) || ($cached['scope'] ?? null) !== $scope || ! is_string($cached['expires_at'] ?? null)) $this->refuse();
            if (now()->lessThan($cached['expires_at'])) {
                if (isset($cached['error'])) $this->refuse();
                return $this->validated($cached, $registered);
            }
        }
        if (! $refresh) $this->refuse();
        OwnerFirst::outside();
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) $this->refuse();
        // A non-blocking cache lock coalesces concurrent renders. This is not a
        // financial transaction; provider HTTP is always outside database locks.
        $lock = Cache::store('database')->lock($key.':refresh', 35);
        if (! $lock->get()) $this->refuse();
        try {
            // Another request may have populated the cache between our first
            // read and acquiring this lock. Reuse it instead of issuing a GET.
            $latest = Cache::store('database')->get($key);
            if (is_string($latest) && $latest !== $sealed) {
                return $this->snapshot($beneficiary, $account, $binding, $registered);
            }
            $started = now();
            $credential = $this->credential();
            $user = User::query()->findOrFail($account->user_id);
            if ($this->accounts->assertEligible($user)->id !== $account->id) $this->refuse();
            $schema = $this->read($scope, $scope['schema_path'], $scope['schema_query'], 'dynamic_beneficiary_binding_schema',
                $user, $beneficiary, $account, $binding, $registered, $credential);
            $schemaAt = now()->toISOString();
            $this->schemas->validate($schema, $registered, $scope, $schemaAt);
            if (now()->greaterThanOrEqualTo($started->copy()->addSeconds(30))) $this->refuse();
            $body = $this->read($scope, $scope['path'], [], 'dynamic_beneficiary_details',
                $user, $beneficiary, $account, $binding, $registered, $credential);
            if (now()->greaterThanOrEqualTo($started->copy()->addSeconds(30))) $this->refuse();
            $snapshot = ['version' => 2, 'scope' => $scope, 'response_json' => $body,
                'response_sha256' => hash('sha256', $body), 'schema_json' => $schema,
                'schema_sha256' => hash('sha256', $schema), 'schema_observed_at' => $schemaAt,
                'requested_at' => $started->toISOString(), 'observed_at' => now()->toISOString(),
                'expires_at' => $started->copy()->addSeconds(30)->toISOString()];
            $snapshot = $this->validated($snapshot, $registered);
            if (! Cache::store('database')->put($key, Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)),
                \Carbon\CarbonImmutable::parse($snapshot['expires_at']))) $this->refuse();
            return $snapshot;
        } catch (Throwable $exception) {
            // Brief negative caching prevents repeated failed GETs on Options.
            Cache::store('database')->put($key, Crypt::encryptString(json_encode([
                'scope' => $scope, 'error' => true, 'expires_at' => now()->addSeconds(10)->toISOString()], JSON_THROW_ON_ERROR)), now()->addSeconds(10));
            throw new RuntimeException('dynamic_registered_account_customer_scope_unavailable', previous: $exception);
        } finally {
            $lock->release();
        }
    }

    private function read(array $scope, string $path, array $query, string $operation, User $user,
        Beneficiary $beneficiary, UserProviderAccount $account, array $binding, array $registered, string $credential): string
    {
        OwnerFirst::outside();
        $url = $scope['origin'].$path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $dispatched = false;
        $response = $this->nium->get(path: $path, query: $query, user: $user, operation: $operation,
            externalReference: (string) $beneficiary->id,
            beforeDispatch: function (Request $request) use ($scope, $beneficiary, $account, $binding, $registered, $credential, $url, &$dispatched): void {
                OwnerFirst::outside();
                if ($dispatched || $this->scope($beneficiary->fresh(), $account->fresh(), $binding, $registered) !== $scope
                    || $this->credential() !== $credential || $request->method() !== 'GET' || $request->url() !== $url
                    || $request->toPsrRequest()->getHeader('x-api-key') !== [$credential]) $this->refuse();
                $dispatched = true;
            });
        if (! $dispatched || $response->status() !== 200 || strlen($response->body()) > 1048576
            || $this->scope($beneficiary->fresh(), $account->fresh(), $binding, $registered) !== $scope
            || $this->credential() !== $credential
            || $this->accounts->assertEligible($user->fresh())->id !== $account->id) $this->refuse();
        return $response->body();
    }

    private function validated(array $snapshot, array $registered): array
    {
        foreach (['requested_at', 'observed_at', 'expires_at', 'schema_observed_at'] as $field) {
            if (! is_string($snapshot[$field] ?? null)
                || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/D', $snapshot[$field])) $this->refuse();
        }
        $requested = \Carbon\CarbonImmutable::parse($snapshot['requested_at']);
        $observed = \Carbon\CarbonImmutable::parse($snapshot['observed_at']);
        $expires = \Carbon\CarbonImmutable::parse($snapshot['expires_at']);
        if (($snapshot['version'] ?? null) !== 2 || ! is_string($snapshot['response_json'] ?? null)
            || ! is_string($snapshot['schema_json'] ?? null)
            || ($snapshot['schema_sha256'] ?? null) !== hash('sha256', $snapshot['schema_json'])
            || ($snapshot['response_sha256'] ?? null) !== hash('sha256', $snapshot['response_json'])
            || now()->lessThan($requested) || now()->lessThan($observed) || $observed->lessThan($requested)
            || \Carbon\CarbonImmutable::parse($snapshot['schema_observed_at'])->lessThan($requested)
            || \Carbon\CarbonImmutable::parse($snapshot['schema_observed_at'])->greaterThan($observed)
            || ! $expires->greaterThan($observed) || now()->greaterThanOrEqualTo($expires)
            || $expires->greaterThan($requested->addSeconds(30))) $this->refuse();
        $scope = $snapshot['scope'];
        $schemaHash = $this->schemas->validate($snapshot['schema_json'], $registered, $scope, $snapshot['schema_observed_at']);
        $matched = DynamicBeneficiaryDetailsResponse::verify($snapshot['response_json'], $registered,
            $scope['beneficiary'], $scope['client'], $scope['customer']);
        $fingerprint = hash('sha256', json_encode(['scope' => $scope, 'schema_sha256' => $schemaHash,
            'receiving_account' => $matched], JSON_THROW_ON_ERROR));
        if (isset($snapshot['binding_sha256']) && $snapshot['binding_sha256'] !== $fingerprint) $this->refuse();
        return [...$snapshot, 'binding_sha256' => $fingerprint];
    }

    private function scope(Beneficiary $beneficiary, UserProviderAccount $account, array $binding, array $registered): array
    {
        $environment = DynamicPayoutEnvironment::configured();
        $environment->assertBinding($binding['base_url']);
        $client = $this->nium->clientId();
        if ($client !== $binding['client'] || $account->external_customer_id !== $binding['customer']
            || (string) $account->id !== (string) ($binding['provider_account_id'] ?? '')
            || (string) $account->user_id !== (string) ($binding['owner'] ?? '')
            || (string) $beneficiary->id !== (string) ($binding['beneficiary_id'] ?? '')
            || (string) $account->user_id !== (string) $beneficiary->user_id
            || (string) $account->provider_id !== (string) $beneficiary->provider_id
            || $account->provider?->code !== 'nium' || $account->provider?->status !== 'active'
            || ! is_string($account->external_customer_id) || $account->external_customer_id === ''
            || ! is_string($beneficiary->external_beneficiary_id) || $beneficiary->external_beneficiary_id === ''
            || $beneficiary->country_code !== $binding['destination_country']
            || $beneficiary->currency !== $binding['destination_currency'] || $beneficiary->payout_method !== $binding['payout_method']
            || DynamicReceivingAccountBinding::fingerprint($beneficiary) !== $binding['current_receiving_record_sha256']) $this->refuse();
        $path = '/api/v2/client/'.urlencode($client).'/customer/'.urlencode($account->external_customer_id)
            .'/beneficiaries/'.urlencode($beneficiary->external_beneficiary_id);
        $customerType = User::query()->findOrFail($account->user_id)->kycProfile?->applicant_type;
        if (! in_array($customerType, ['individual', 'business'], true)
            || ! in_array($beneficiary->beneficiary_type, ['individual', 'business'], true)) $this->refuse();
        $routingTypes = [];
        foreach ([1, 2] as $slot) {
            if (isset($registered['routingCodeType'.$slot])) {
                $type = $registered['routingCodeType'.$slot];
                if (! is_string($type) || trim($type) === '') $this->refuse();
                $routingTypes[] = strtoupper($type);
            }
        }
        $routingTypes = array_values(array_unique($routingTypes));
        sort($routingTypes, SORT_STRING);
        $schemaPath = '/api/v2/client/'.urlencode($client).'/customer/'.urlencode($account->external_customer_id)
            .'/currency/'.urlencode($beneficiary->currency).'/validationSchemas';
        if ($this->nium->path((string) config('services.nium.beneficiary_validation_schema_endpoint'),
            ['client' => $client, 'customer' => $account->external_customer_id, 'currencyCode' => $beneficiary->currency]) !== $schemaPath) $this->refuse();
        return ['origin' => $environment->origin, 'method' => 'GET', 'path' => $path,
            'schema_path' => $schemaPath, 'schema_query' => ['destinationCountry' => $beneficiary->country_code,
                'destinationCurrency' => $beneficiary->currency, 'payoutMethod' => $beneficiary->payout_method,
                'beneficiaryAccountType' => $beneficiary->beneficiary_type === 'business' ? 'CORPORATE' : 'INDIVIDUAL',
                'customerType' => $customerType === 'business' ? 'CORPORATE' : 'INDIVIDUAL', 'routingCodeType' => $routingTypes],
            'owner' => (string) $account->user_id, 'provider_id' => (string) $account->provider_id,
            'account_id' => (string) $account->id, 'client' => $client, 'customer' => $account->external_customer_id,
            'beneficiary_id' => (string) $beneficiary->id, 'beneficiary' => $beneficiary->external_beneficiary_id,
            'receiving_sha256' => $binding['current_receiving_record_sha256'],
            'registration_log_id' => $binding['registration_log_id'],
            'registration_request_sha256' => $binding['registration_request_sha256'],
            'registration_response_sha256' => $binding['registration_response_sha256'],
            'credential_sha256' => hash('sha256', $this->credential())];
    }

    private function credential(): string
    {
        $value = config('services.nium.auth.header_value');
        if (strtolower((string) config('services.nium.auth.mode')) !== 'header'
            || strtolower(trim((string) config('services.nium.auth.header_name'))) !== 'x-api-key'
            || ! is_string($value) || trim($value) === '') $this->refuse();
        return $value;
    }

    private function refuse(): never
    {
        throw new RuntimeException('dynamic_registered_account_customer_scope_unavailable');
    }
}
