<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Models\Beneficiary;
use App\Models\Balance;
use App\Models\NiumDynamicPayoutQuote;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumEntitlementEvidenceTransport;
use App\Services\Nium\NiumService;
use App\Domain\Nium\DynamicMultiCurrency\Financial\Decimal;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Request;
use RuntimeException;

/** Runtime customer-scoped reads. No operator audit-collector configuration or catalogue promotion. */
final class DynamicPayoutEligibility
{
    public function __construct(private readonly NiumService $nium) {}

    public function observe(User $user, Beneficiary $beneficiary, NiumDynamicPayoutQuote $quote,
        string $legalEntity, array $amounts): array
    {
        $evidence = $this->options($user, $beneficiary, $quote->binding, $legalEntity, $quote->id,
            PayoutAmountMode::from($quote->binding['amount_mode']));
        foreach (['USD' => $amounts['quoted_principal']['amount'], $beneficiary->currency => $amounts['quoted_destination_amount']['amount']] as $currency => $amount) {
            if (! (new Decimal($amount))->fitsScale($evidence['currency_scales'][$currency])) {
                throw new RuntimeException('dynamic_payout_amount_precision_unsupported');
            }
        }

        return $evidence;
    }

    /** Customer-scoped options use the same authenticated tuple/schema as Review. */
    public function options(User $user, Beneficiary $beneficiary, array $binding,
        string $legalEntity, ?string $reference = null, ?PayoutAmountMode $mode = null): array
    {
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('dynamic_eligibility_network_requires_no_transaction');
        }
        $started = now();
        $bodies = [];
        foreach (['customer_corridors', 'payout_schema'] as $endpoint) {
            $this->assertScope($binding, $user, $beneficiary);
            $path = $this->nium->path(NiumEntitlementEvidenceTransport::ENDPOINTS[$endpoint],
                ['client' => $binding['client'], 'customer' => $binding['customer'], 'currencyCode' => $beneficiary->currency]);
            $environment = DynamicPayoutEnvironment::configured();
            $query = $endpoint === 'payout_schema' ? ['destinationCountry' => $beneficiary->country_code, 'payoutMethod' => 'LOCAL'] : [];
            if ($endpoint === 'customer_corridors') {
                $environment->assertCustomerCorridorsReadEndpoint($path, $binding['client'], $binding['customer']);
            } else {
                $environment->payoutSchemaDialect($path, $query, $binding['client'], $binding['customer'], $beneficiary->currency);
            }
            $expectedUrl = $environment->origin.$path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
            $dispatched = false;
            $response = $this->nium->get(path: $path,
                query: $query,
                user: $user, operation: 'dynamic_'.$endpoint, externalReference: $reference ?? (string) $beneficiary->id,
                beforeDispatch: function (Request $request) use ($binding, $user, $beneficiary, $path, $query, $endpoint, $environment, $expectedUrl, &$dispatched): void {
                    if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
                        throw new RuntimeException('dynamic_eligibility_network_requires_no_transaction');
                    }
                    $this->assertScope($binding, $user, $beneficiary);
                    if ($endpoint === 'customer_corridors') {
                        $environment->assertCustomerCorridorsReadEndpoint($path, $binding['client'], $binding['customer']);
                    } else {
                        $environment->payoutSchemaDialect($path, $query, $binding['client'], $binding['customer'], $beneficiary->currency);
                    }
                    if ($dispatched || $request->method() !== 'GET' || $request->url() !== $expectedUrl) {
                        throw new RuntimeException('dynamic_eligibility_scope_changed');
                    }
                    $dispatched = true;
                });
            $this->assertScope($binding, $user, $beneficiary);
            if (! $dispatched) {
                throw new RuntimeException('dynamic_eligibility_scope_changed');
            }
            if (! $response->successful() || strlen($response->body()) > 1048576
                || $started->copy()->addSeconds(30)->isPast()) {
                throw new RuntimeException('dynamic_'.$endpoint.'_unavailable');
            }
            $bodies[$endpoint] = $response->body();
            if ($endpoint === 'customer_corridors') {
                try {
                    $body = ExactProviderJson::decode($bodies[$endpoint]);
                } catch (\Throwable $exception) {
                    throw new RuntimeException('dynamic_customer_corridor_response_contract_unavailable', previous: $exception);
                }
                foreach (['clientHashId' => 'client', 'customerHashId' => 'customer'] as $field => $key) {
                    if (array_key_exists($field, $body) && $body[$field] !== $binding[$key]) {
                        throw new RuntimeException('dynamic_eligibility_scope_changed');
                    }
                }
                // Parse the evidenced listing before requesting any unverified API.
                // Missing authorization must not be masked by the Schema guard.
                $selected = self::select($body, $beneficiary->country_code, $beneficiary->currency, $legalEntity);
                $environment->assertContract('customer_corridors');
            } else {
                $schema = self::schemaObservation($bodies[$endpoint], [
                    ...array_intersect_key($binding, array_flip(['base_url', 'client', 'customer'])),
                    'currency' => $beneficiary->currency, 'path' => $path, 'query' => $query,
                ], $mode);
                $environment->assertContract('payout_schema');
            }
        }
        return ['version' => 'nium-customer-payout-oas-example-v1',
            'selection' => $selected, 'currency_scales' => $schema['currency_scales'],
            'schema_observation' => $schema,
            'customer_corridors_sha256' => hash('sha256', $bodies['customer_corridors']),
            'payout_schema_sha256' => hash('sha256', $bodies['payout_schema']),
            'observed_at' => now()->toISOString(), 'expires_at' => $started->copy()->addSeconds(30)->toISOString(),
            'scope' => array_intersect_key($binding, array_flip(['base_url', 'client', 'customer', 'wallet']))];
    }

    /** Spend's four-field records prove listing only. Preserve every matching row. */
    public static function listing(array $body, string $country, string $currency, string $rail = 'LOCAL'): array
    {
        if (! is_array($body['data'] ?? null) || ! array_is_list($body['data'])) {
            throw new RuntimeException('dynamic_customer_corridor_response_contract_unavailable');
        }
        $matches = [];
        $reasons = [];
        foreach ($body['data'] as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('dynamic_customer_corridor_response_contract_unavailable');
            }
            if (($row['destination_currency'] ?? null) !== $currency
                || ($row['payout_method'] ?? null) !== $rail) continue;
            if (! is_array($row['destination_country'] ?? null) || ! array_is_list($row['destination_country'])) {
                throw new RuntimeException('dynamic_customer_corridor_response_contract_unavailable');
            }
            foreach ($row['destination_country'] as $value) {
                if (! is_string($value)) {
                    throw new RuntimeException('dynamic_customer_corridor_response_contract_unavailable');
                }
            }
            if (! in_array($country, $row['destination_country'], true)) continue;
            if (! is_string($row['routing_code_type'] ?? null)) {
                throw new RuntimeException('dynamic_customer_corridor_response_contract_unavailable');
            }
            if (! array_key_exists('client_legal_entity', $row)) {
                $reasons[] = 'dynamic_customer_corridor_legal_entity_unverified';
            } elseif (! is_array($row['client_legal_entity']) || ! array_is_list($row['client_legal_entity'])
                || count(array_filter($row['client_legal_entity'], 'is_string')) !== count($row['client_legal_entity'])) {
                throw new RuntimeException('dynamic_customer_corridor_response_contract_unavailable');
            }
            if (! array_key_exists('ignoreLookup', $row)) {
                $reasons[] = 'dynamic_customer_corridor_lookup_policy_unverified';
            } elseif (! is_bool($row['ignoreLookup'])) {
                throw new RuntimeException('dynamic_customer_corridor_response_contract_unavailable');
            }
            $matches[] = array_intersect_key($row, array_flip(['destination_currency', 'payout_method',
                'destination_country', 'routing_code_type', 'client_legal_entity', 'ignoreLookup']));
        }
        return ['status' => $matches === [] ? 'NOT_LISTED' : 'LISTED', 'eligible' => false,
            'matches' => $matches, 'reason_codes' => $matches === [] ? ['dynamic_customer_corridor_not_permitted']
                : array_values(array_unique([...$reasons, 'dynamic_customer_corridor_authorization_unverified']))];
    }

    /** Listing is not legal-entity/lookup authorization; preserve the stricter execution requirements. */
    public static function select(array $body, string $country, string $currency, string $entity, string $rail = 'LOCAL'): array
    {
        $listing = self::listing($body, $country, $currency, $rail);
        $matches = [];
        foreach ($listing['matches'] as $row) {
            if (! array_key_exists('client_legal_entity', $row)) {
                throw new RuntimeException('dynamic_customer_corridor_legal_entity_unverified');
            }
            if (! in_array('LEGAL_ENTITY_'.$entity, $row['client_legal_entity'], true)) continue;
            if (! array_key_exists('ignoreLookup', $row)) {
                throw new RuntimeException('dynamic_customer_corridor_lookup_policy_unverified');
            }
            // No undocumented interpretation of lookup-bypass flags, including absence.
            if ($row['ignoreLookup'] !== false) {
                throw new RuntimeException('dynamic_customer_corridor_response_contract_unavailable');
            }
            $matches[] = ['legal_entity' => $entity, 'destination_country' => $country,
                'destination_currency' => $currency, 'payout_method' => $rail, 'routing_code_type' => $row['routing_code_type']];
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(count($matches) === 0 ? 'dynamic_customer_corridor_not_permitted' : 'dynamic_customer_corridor_ambiguous');
        }
        return $matches[0];
    }

    /** Structural/precision observation, never a partial JSON Schema instruction validator. */
    public static function schemaObservation(string $body, array $scope, ?PayoutAmountMode $mode = null): array
    {
        $environment = DynamicPayoutEnvironment::resolve($scope['base_url']);
        $currency = $scope['currency'];
        $dialect = $environment->payoutSchemaDialect($scope['path'], $scope['query'],
            $scope['client'], $scope['customer'], $currency);
        $spend = $environment->origin === 'https://api.spend.nium.com';
        try {
            $root = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
            // Preserve the documented gateway string encoding without accepting it on Spend.
            if (! $spend && is_string($root)) {
                $body = $root;
                $root = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
            }
            $schema = ExactProviderJson::decode($body);
        } catch (\Throwable $exception) {
            throw new RuntimeException('dynamic_payout_schema_contract_unavailable', previous: $exception);
        }
        if (! ($root instanceof \stdClass) || ($schema['$schema'] ?? null) !== $dialect
            || ($schema['type'] ?? null) !== 'object' || ! self::schemaProperties($root->properties ?? null)
            || ! self::requiredNames($root->required ?? null) || $root->required === []
            || ! is_array($root->allOf ?? null) || ! array_is_list($root->allOf) || $root->allOf === []) {
            throw new RuntimeException('dynamic_payout_schema_contract_unavailable');
        }
        foreach ($root->allOf as $condition) {
            if (! ($condition instanceof \stdClass) || ! (($condition->if ?? null) instanceof \stdClass)
                || ! (($condition->then ?? null) instanceof \stdClass)) {
                throw new RuntimeException('dynamic_payout_schema_contract_unavailable');
            }
            foreach ([$condition->if, $condition->then] as $branch) {
                if ((property_exists($branch, 'required') && ! self::requiredNames($branch->required))
                    || (property_exists($branch, 'properties') && ! self::schemaProperties($branch->properties))) {
                    throw new RuntimeException('dynamic_payout_schema_contract_unavailable');
                }
            }
        }
        if (! property_exists($root->properties, 'payout.destinationAmount')) {
            throw new RuntimeException('dynamic_payout_schema_destination_amount_unverified');
        }
        // The gateway USD scale is independently documented in the retained remittance
        // contract. Spend gets no source scale or mode permission from an absent field.
        $scales = $spend ? [] : ['USD' => 2];
        foreach (['payout.sourceAmount' => 'USD', 'payout.destinationAmount' => $currency] as $name => $code) {
            if (! property_exists($root->properties, $name)) continue;
            $property = $root->properties->{$name};
            if (! ($property instanceof \stdClass) || ($property->type ?? null) !== 'number'
                || ! in_array($property->maximumDecimals ?? null, [0, 1, 2, '0', '1', '2'], true)
                || ($spend && ($name !== 'payout.destinationAmount' || ! in_array($property->maximumDecimals, [2, '2'], true)))) {
                throw new RuntimeException('dynamic_payout_schema_contract_unavailable');
            }
            $scales[$code] = (int) $property->maximumDecimals;
        }
        if ($spend && $mode !== null) {
            throw new RuntimeException($mode === PayoutAmountMode::Source
                ? 'dynamic_payout_source_amount_contract_unverified' : 'dynamic_payout_destination_amount_contract_unverified');
        }
        return ['schema_uri' => $dialect, 'currency_scales' => $scales,
            // Retain the exact constraints, including number types/tokens, for evidence.
            // The parsed summary is never evaluated as a payload validator.
            'schema_json' => $body,
            'required' => $schema['required'], 'allOf' => $schema['allOf'],
            'source_amount_present' => property_exists($root->properties, 'payout.sourceAmount'),
            'instruction_validated' => false, 'eligible' => false, 'requires_instruction_validation' => true];
    }

    private static function requiredNames(mixed $names): bool
    {
        return is_array($names) && array_is_list($names)
            && count(array_filter($names, static fn ($name): bool => is_string($name) && $name !== '')) === count($names)
            && count(array_unique($names)) === count($names);
    }

    private static function schemaProperties(mixed $properties): bool
    {
        if (! ($properties instanceof \stdClass)) return false;
        foreach ($properties as $property) {
            // JSON Schema nodes are objects or boolean schemas, never scalar defaults.
            if (! ($property instanceof \stdClass) && ! is_bool($property)) return false;
        }
        return true;
    }

    private function assertScope(array $binding, User $user, Beneficiary $beneficiary): void
    {
        $environment = DynamicPayoutEnvironment::configured();
        $environment->assertBinding($binding['base_url']);
        $account = UserProviderAccount::query()->find($binding['provider_account_id'] ?? null);
        $wallet = Balance::query()->find($binding['balance_id'] ?? null);
        $currentBeneficiary = Beneficiary::query()->find($beneficiary->id);
        if ($this->nium->clientId() !== $binding['client'] || $account === null || $wallet === null
            || $currentBeneficiary === null || (string) ($binding['owner'] ?? '') !== (string) $user->id
            || (string) ($binding['beneficiary_id'] ?? '') !== (string) $currentBeneficiary->id
            || (string) $account->user_id !== (string) $user->id || (string) $wallet->user_id !== (string) $user->id
            || (string) $currentBeneficiary->user_id !== (string) $user->id
            || (string) $currentBeneficiary->provider_id !== (string) $account->provider_id
            || (string) $wallet->provider_id !== (string) $account->provider_id
            || $account->provider?->code !== 'nium' || $account->provider?->status !== 'active'
            || $account->external_customer_id !== $binding['customer']
            || $account->external_account_id !== $binding['wallet'] || $wallet->external_account_id !== $binding['wallet']
            || $currentBeneficiary->country_code !== $beneficiary->country_code
            || $currentBeneficiary->currency !== $beneficiary->currency
            || $currentBeneficiary->payout_method !== 'LOCAL') {
            throw new RuntimeException('dynamic_eligibility_scope_changed');
        }
    }
}
