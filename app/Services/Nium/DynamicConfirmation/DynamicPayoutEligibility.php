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
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Request;
use RuntimeException;

/** Runtime customer-scoped reads. No operator audit-collector configuration or catalogue promotion. */
final class DynamicPayoutEligibility
{
    public function __construct(private readonly NiumService $nium) {}

    /** Customer-scoped corridor support only; pricing and instruction admission remain separate. */
    public function discover(User $user, Beneficiary $beneficiary, array $binding): array
    {
        $body = $this->read($user, $beneficiary, $binding, 'customer_corridors', null, now(), listing: true);
        try {
            $decoded = ExactProviderJson::decode($body);
        } catch (\Throwable $exception) {
            throw new RuntimeException('dynamic_customer_corridor_response_contract_unavailable', previous: $exception);
        }
        foreach (['clientHashId' => 'client', 'customerHashId' => 'customer'] as $field => $key) {
            if (array_key_exists($field, $decoded) && $decoded[$field] !== $binding[$key]) {
                throw new RuntimeException('dynamic_eligibility_scope_changed');
            }
        }

        return [...self::listing($decoded, $beneficiary->country_code, $beneficiary->currency, $beneficiary->payout_method),
            'response_sha256' => hash('sha256', $body)];
    }

    public function observe(User $user, Beneficiary $beneficiary, NiumDynamicPayoutQuote $quote,
        string $legalEntity, array $amounts): array
    {
        $evidence = $this->options($user, $beneficiary, $quote->binding, $legalEntity, $quote->id,
            PayoutAmountMode::from($quote->binding['amount_mode']));
        self::assertAmounts($evidence['schema_observation'], $beneficiary->currency,
            PayoutAmountMode::from($quote->binding['amount_mode']), $amounts);

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
            $bodies[$endpoint] = $this->read($user, $beneficiary, $binding, $endpoint, $reference, $started);
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
                $selected = self::select($body, $beneficiary->country_code, $beneficiary->currency, $legalEntity, $beneficiary->payout_method);
                // CLEAR customer + authenticated exact V1 tuple establishes corridor
                // support. Spend may omit the optional OAS example metadata.
            } else {
                $schema = self::schemaObservation($bodies[$endpoint], [
                    ...array_intersect_key($binding, array_flip(['base_url', 'client', 'customer'])),
                    'currency' => $beneficiary->currency,
                    'path' => $this->nium->path(NiumEntitlementEvidenceTransport::ENDPOINTS[$endpoint],
                        ['client' => $binding['client'], 'customer' => $binding['customer'], 'currencyCode' => $beneficiary->currency]),
                    'query' => ['destinationCountry' => $beneficiary->country_code, 'payoutMethod' => $beneficiary->payout_method],
                ], $mode);
                // schemaObservation validates the exact origin, endpoint, tuple
                // and returned dialect. A blanket origin grant supplies no evidence.
            }
        }
        return ['version' => 'nium-customer-payout-v2',
            'selection' => $selected, 'currency_scales' => $schema['currency_scales'],
            'supported_amount_modes' => $schema['supported_amount_modes'],
            'schema_observation' => $schema,
            'customer_corridors_sha256' => hash('sha256', $bodies['customer_corridors']),
            'payout_schema_sha256' => hash('sha256', $bodies['payout_schema']),
            'observed_at' => now()->toISOString(), 'expires_at' => $started->copy()->addSeconds(30)->toISOString(),
            'scope' => array_intersect_key($binding, array_flip(['base_url', 'client', 'customer', 'wallet']))];
    }

    private function read(User $user, Beneficiary $beneficiary, array $binding, string $endpoint,
        ?string $reference, \Carbon\CarbonInterface $started, bool $listing = false): string
    {
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('dynamic_eligibility_network_requires_no_transaction');
        }
        $this->assertScope($binding, $user, $beneficiary);
        $path = $this->nium->path(NiumEntitlementEvidenceTransport::ENDPOINTS[$endpoint],
            ['client' => $binding['client'], 'customer' => $binding['customer'], 'currencyCode' => $beneficiary->currency]);
        $environment = DynamicPayoutEnvironment::configured();
        $query = $endpoint === 'payout_schema' ? ['destinationCountry' => $beneficiary->country_code, 'payoutMethod' => $beneficiary->payout_method] : [];
        if ($endpoint === 'customer_corridors') {
            $environment->assertCustomerCorridorsReadEndpoint($path, $binding['client'], $binding['customer']);
        } else {
            $environment->payoutSchemaDialect($path, $query, $binding['client'], $binding['customer'], $beneficiary->currency);
        }
        $expectedUrl = $environment->origin.$path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $dispatched = false;
        $response = $this->nium->get(path: $path,
            query: $query,
            user: $user, operation: $listing ? 'dynamic_customer_corridor_listing' : 'dynamic_'.$endpoint,
            externalReference: $reference ?? (string) $beneficiary->id,
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
        if ($response->status() !== 200 || strlen($response->body()) > 1048576
            || $started->copy()->addSeconds(30)->isPast()) {
            throw new RuntimeException('dynamic_'.$endpoint.'_unavailable');
        }
        return $response->body();
    }

    /** Preserve every matching row. A listing alone never authorizes an instruction. */
    public static function listing(array $body, string $country, string $currency, string $rail = 'LOCAL'): array
    {
        if (! is_array($body['data'] ?? null) || ! array_is_list($body['data'])) {
            throw new RuntimeException('dynamic_customer_corridor_response_contract_unavailable');
        }
        $matches = [];
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
            if (array_key_exists('client_legal_entity', $row)
                && (! is_array($row['client_legal_entity']) || ! array_is_list($row['client_legal_entity'])
                || count(array_filter($row['client_legal_entity'], 'is_string')) !== count($row['client_legal_entity']))) {
                throw new RuntimeException('dynamic_customer_corridor_response_contract_unavailable');
            }
            if (array_key_exists('ignoreLookup', $row) && ! is_bool($row['ignoreLookup'])) {
                throw new RuntimeException('dynamic_customer_corridor_response_contract_unavailable');
            }
            $matches[] = array_intersect_key($row, array_flip(['destination_currency', 'payout_method',
                'destination_country', 'routing_code_type', 'client_legal_entity', 'ignoreLookup']));
        }
        return ['status' => $matches === [] ? 'NOT_LISTED' : 'LISTED', 'eligible' => false,
            'matches' => $matches, 'reason_codes' => $matches === [] ? ['dynamic_customer_corridor_not_permitted'] : []];
    }

    /** Select one customer-scoped tuple; never fabricate metadata omitted by Nium. */
    public static function select(array $body, string $country, string $currency, string $entity, string $rail = 'LOCAL'): array
    {
        $listing = self::listing($body, $country, $currency, $rail);
        $matches = $listing['matches'];
        if (count($matches) !== 1) {
            throw new RuntimeException(count($matches) === 0 ? 'dynamic_customer_corridor_not_permitted' : 'dynamic_customer_corridor_ambiguous');
        }
        $row = $matches[0];
        // The caller supplies entity from the authenticated original registration
        // request. A missing corridor field neither supplies nor changes that entity.
        if (preg_match('/^[A-Z]{2}$/D', $entity) !== 1) {
            throw new RuntimeException('dynamic_quote_registration_evidence_unavailable');
        }
        if (array_key_exists('client_legal_entity', $row)
            && ! in_array('LEGAL_ENTITY_'.$entity, $row['client_legal_entity'], true)) {
            throw new RuntimeException('dynamic_customer_corridor_not_permitted');
        }
        // Retain the existing refusal for an explicit unsupported lookup-bypass
        // flag. Absence has no assigned value and creates no lookup policy.
        if (array_key_exists('ignoreLookup', $row) && $row['ignoreLookup'] !== false) {
            throw new RuntimeException('dynamic_customer_corridor_response_contract_unavailable');
        }
        return ['legal_entity' => $entity, 'destination_country' => $country,
            'destination_currency' => $currency, 'payout_method' => $rail, 'routing_code_type' => $row['routing_code_type']];
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
            || (property_exists($root, 'allOf')
                && (! is_array($root->allOf) || ! array_is_list($root->allOf) || $root->allOf === []))) {
            throw new RuntimeException('dynamic_payout_schema_contract_unavailable');
        }
        $amountRequirements = $root->required;
        foreach ($root->allOf ?? [] as $condition) {
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
            // Retain only the documented properties/const -> required subset.
            // This recognises structure, never evaluates or satisfies the condition.
            if (array_diff(array_keys(get_object_vars($condition)), ['if', 'then']) !== []
                || array_diff(array_keys(get_object_vars($condition->if)), ['properties', 'required']) !== []
                || array_diff(array_keys(get_object_vars($condition->then)), ['required']) !== []) {
                throw new RuntimeException('dynamic_payout_schema_conditional_unsupported');
            }
            foreach (($condition->if->properties ?? new \stdClass) as $property) {
                if (! ($property instanceof \stdClass) || ! property_exists($property, 'const')
                    || array_keys(get_object_vars($property)) !== ['const']
                    || (! is_scalar($property->const) && $property->const !== null)) {
                    throw new RuntimeException('dynamic_payout_schema_conditional_unsupported');
                }
            }
            // Amount names have the same documented meaning in required and
            // then.required. Retain the condition; do not assume its predicate
            // matches a registered-beneficiary request. Nium must validate the
            // exact remittance body, including its resolved beneficiary/remitter.
            $amountRequirements = [...$amountRequirements, ...($condition->then->required ?? [])];
        }
        if (array_diff(array_keys(get_object_vars($root)), ['$schema', '$id', 'type', 'title',
            'description', 'properties', 'required', 'allOf']) !== []) {
            throw new RuntimeException('dynamic_payout_schema_contract_unavailable');
        }
        if (! property_exists($root->properties, 'payout.destinationAmount')) {
            throw new RuntimeException('dynamic_payout_schema_destination_amount_unverified');
        }
        // USD funding already has a two-decimal ceiling in the retained remittance
        // contract and FinalizedUsdPayoutPreparation. This is not source-mode evidence.
        // An explicit source property can narrow that ceiling, never widen it.
        $scales = ['USD' => 2];
        $limits = [];
        foreach (['payout.sourceAmount' => 'USD', 'payout.destinationAmount' => $currency] as $name => $code) {
            if (! property_exists($root->properties, $name)) continue;
            $property = $root->properties->{$name};
            if (! ($property instanceof \stdClass) || ($property->type ?? null) !== 'number'
                || array_diff(array_keys(get_object_vars($property)), ['$id', 'title', 'description',
                    'type', 'minimum', 'maximum', 'maximumDecimals']) !== []
                || ! in_array($property->maximumDecimals ?? null, [0, 1, 2, '0', '1', '2'], true)) {
                throw new RuntimeException('dynamic_payout_schema_contract_unavailable');
            }
            $scales[$code] = (int) $property->maximumDecimals;
            $limits[$name] = [];
            foreach (['minimum', 'maximum'] as $bound) {
                if (property_exists($property, $bound)) {
                    $limits[$name][$bound] = self::schemaBound($property->{$bound}, $schema['properties'][$name][$bound],
                        // The retained draft-07 example encodes maximum as a string.
                        ! $spend && $bound === 'maximum');
                }
            }
            if (isset($limits[$name]['minimum'], $limits[$name]['maximum'])
                && BigDecimal::of($limits[$name]['minimum'])->isGreaterThan($limits[$name]['maximum'])) {
                throw new RuntimeException('dynamic_payout_schema_contract_unavailable');
            }
        }
        // Precision properties alone establish no amount mode. A required amount
        // (possibly conditional) supplies a candidate for non-settling validation,
        // never executable eligibility. An unconditional opposite amount still
        // rules out our single-amount request. Provider validation evaluates all
        // predicates/requirements for the selected registered instruction.
        $modes = ! isset($scales['USD'], $scales[$currency]) ? [] : array_values(array_filter(PayoutAmountMode::cases(),
            static fn (PayoutAmountMode $candidate): bool => property_exists($root->properties, 'payout.'.$candidate->quoteField())
                && in_array('payout.'.$candidate->quoteField(), $amountRequirements, true)
                && ! in_array($candidate === PayoutAmountMode::Source ? 'payout.destinationAmount' : 'payout.sourceAmount',
                    $root->required, true)));
        $modes = array_map(static fn (PayoutAmountMode $candidate): string => $candidate->value, $modes);
        if ($mode !== null && ! in_array($mode->value, $modes, true)) {
            throw new RuntimeException($mode === PayoutAmountMode::Source
                ? 'dynamic_payout_source_amount_contract_unverified' : 'dynamic_payout_destination_amount_contract_unverified');
        }
        return ['schema_uri' => $dialect, 'currency_scales' => $scales,
            'supported_amount_modes' => $modes, 'amount_limits' => $limits,
            // Retain the exact constraints, including number types/tokens, for evidence.
            // The summary alone never validates a complete payout instruction.
            'schema_json' => $body,
            'required' => $schema['required'], 'allOf' => $schema['allOf'] ?? [],
            'source_amount_present' => property_exists($root->properties, 'payout.sourceAmount'),
            'instruction_validated' => false, 'eligible' => false, 'requires_instruction_validation' => true];
    }

    /** Exact checks on quote amounts, repeated from retained schema bytes under admission locks. */
    public static function assertAmounts(array $schema, string $destinationCurrency, PayoutAmountMode $mode, array $amounts): void
    {
        foreach (['USD' => $amounts['quoted_principal']['amount'], $destinationCurrency => $amounts['quoted_destination_amount']['amount']] as $currency => $amount) {
            if (! isset($schema['currency_scales'][$currency])
                || ! (new Decimal($amount))->fitsScale($schema['currency_scales'][$currency])) {
                throw new RuntimeException('dynamic_payout_amount_precision_unsupported');
            }
        }
        // JSON Schema property bounds apply to the field present in the exact
        // single-amount remittance body, not to an omitted opposite amount field.
        $amount = BigDecimal::of($amounts[$mode === PayoutAmountMode::Source ? 'quoted_principal' : 'quoted_destination_amount']['amount']);
        $limits = $schema['amount_limits']['payout.'.$mode->quoteField()];
        if ((isset($limits['minimum']) && $amount->isLessThan($limits['minimum']))
            || (isset($limits['maximum']) && $amount->isGreaterThan($limits['maximum']))) {
            throw new RuntimeException('dynamic_payout_amount_out_of_schema_bounds');
        }
    }

    private static function schemaBound(mixed $native, mixed $token, bool $legacyString): string
    {
        // Native decoding verifies JSON number vs string; only the exact token
        // participates in arithmetic. Bound parsing is resource limited, not rounded.
        if ((! is_int($native) && ! is_float($native) && ! ($legacyString && is_string($native)))
            || ! is_string($token) || strlen($token) > 1024
            || preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE]([+-]?[0-9]{1,4}))?$/D', $token, $matches) !== 1
            || abs((int) ($matches[1] ?? 0)) > 1024) {
            throw new RuntimeException('dynamic_payout_schema_contract_unavailable');
        }

        return (string) BigDecimal::of($token);
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
            || $currentBeneficiary->payout_method !== $beneficiary->payout_method
            || preg_match('/^[A-Z][A-Z0-9_]*$/D', (string) $beneficiary->payout_method) !== 1
            || (isset($binding['payout_method']) && $binding['payout_method'] !== $currentBeneficiary->payout_method)
            || (isset($binding['destination_country']) && $binding['destination_country'] !== $currentBeneficiary->country_code)
            || (isset($binding['destination_currency']) && $binding['destination_currency'] !== $currentBeneficiary->currency)) {
            throw new RuntimeException('dynamic_eligibility_scope_changed');
        }
    }
}
