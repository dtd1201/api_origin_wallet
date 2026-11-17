<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Nium\DynamicMultiCurrency\Financial\Decimal;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutEligibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Minimal synthetic schemas test structure; no unretained provider field names or rules are asserted. */
final class NiumDynamicPayoutSchemaTest extends TestCase
{
    private function scope(string $origin = 'https://api.spend.nium.com'): array
    {
        return ['base_url' => $origin, 'client' => 'fixture-client', 'customer' => 'fixture-customer',
            'currency' => 'GBP', 'path' => '/api/v1/client/fixture-client/customer/fixture-customer/currency/GBP/payout/schema',
            'query' => ['destinationCountry' => 'GB', 'payoutMethod' => 'LOCAL']];
    }

    private function schema(): array
    {
        return ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'type' => 'object',
            'properties' => ['payout.destinationAmount' => ['type' => 'number', 'maximumDecimals' => 2]],
            'required' => ['payout.destinationAmount'],
            'allOf' => [['if' => ['properties' => ['synthetic.flag' => ['const' => true]]],
                'then' => ['required' => ['synthetic.conditionalField']]]]];
    }

    private function body(?array $schema = null): string
    {
        return json_encode($schema ?? $this->schema(), JSON_THROW_ON_ERROR);
    }

    /** Hypothetical mode requirements, never claimed as a Production response. */
    private function amountConditions(): array
    {
        return array_map(static fn (PayoutAmountMode $mode): array => [
            'if' => ['required' => ['payout.'.$mode->quoteField()]],
            'then' => ['required' => ['payout.'.$mode->quoteField(), 'payout.sourceCurrency']],
        ], PayoutAmountMode::cases());
    }

    public function test_spend_schema_retains_conditions_and_separates_funding_precision_from_source_mode(): void
    {
        $result = DynamicPayoutEligibility::schemaObservation($this->body(), $this->scope());
        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $result['schema_uri']);
        $this->assertSame(['USD' => 2, 'GBP' => 2], $result['currency_scales']);
        $this->assertSame(['DESTINATION_AMOUNT'], $result['supported_amount_modes']);
        $this->assertFalse($result['source_amount_present']);
        $this->assertSame($this->schema()['required'], $result['required']);
        $this->assertSame($this->schema()['allOf'], $result['allOf']);
        $this->assertSame($this->body(), $result['schema_json']);
        $this->assertFalse($result['instruction_validated']);
        $this->assertFalse($result['eligible']);
        $this->assertTrue($result['requires_instruction_validation']);
        $this->assertTrue((new Decimal('784.01'))->fitsScale($result['currency_scales']['GBP']));
        $this->assertFalse((new Decimal('784.001'))->fitsScale($result['currency_scales']['GBP']));
    }

    public function test_observed_schema_counts_do_not_require_inventing_provider_field_names(): void
    {
        $schema = $this->schema();
        for ($index = 1; $index <= 38; $index++) {
            $name = 'synthetic.field'.$index;
            $schema['properties'][$name] = ['type' => 'string'];
            if ($index <= 12) $schema['required'][] = $name;
        }
        $this->assertCount(39, $schema['properties']);
        $result = DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope());
        $this->assertCount(13, $result['required']);
        $this->assertCount(1, $result['allOf']);
        $this->assertFalse($result['eligible']);
    }

    public static function modes(): array
    {
        return [[PayoutAmountMode::Source, 'dynamic_payout_source_amount_contract_unverified'],
            [PayoutAmountMode::Destination, 'dynamic_payout_destination_amount_contract_unverified']];
    }

    #[DataProvider('modes')]
    public function test_schema_field_presence_or_absence_does_not_supply_spend_mode_authority(PayoutAmountMode $mode, string $reason): void
    {
        $schema = $this->schema();
        $schema['required'] = ['beneficiary.id']; // Precision without amount requirements is insufficient.
        $this->expectExceptionMessage($reason);
        DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope(), $mode);
    }

    public function test_destination_amount_is_required_for_a_precision_observation(): void
    {
        $schema = $this->schema();
        $schema['properties'] = (object) [];
        $this->expectExceptionMessage('dynamic_payout_schema_destination_amount_unverified');
        DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope());
    }

    public static function dynamicCorridors(): array
    {
        return [['HK', 'HKD', 2], ['SG', 'SGD', 2], ['GB', 'GBP', 2], ['DK', 'EUR', 2],
            ['DE', 'EUR', 2], ['JP', 'JPY', 0], ['KR', 'KRW', 0], ['TH', 'THB', 2], ['CH', 'EUR', 2]];
    }

    #[DataProvider('dynamicCorridors')]
    public function test_destination_only_schema_without_allof_keeps_all_required_fields_and_never_grants_source_mode(
        string $country, string $currency, int $scale): void
    {
        $schema = $this->schema();
        unset($schema['allOf']);
        $schema['properties']['payout.destinationAmount']['maximumDecimals'] = $scale;
        $schema['properties']['payout.destinationAmount']['minimum'] = 1;
        // Match the reported count without inventing the undisclosed Production field names.
        for ($index = 1; $index <= 16; $index++) {
            $schema['required'][] = 'synthetic.field'.$index;
            $schema['properties']['synthetic.field'.$index] = ['type' => 'string'];
        }
        $scope = $this->scope();
        $scope['currency'] = $currency;
        $scope['path'] = '/api/v1/client/fixture-client/customer/fixture-customer/currency/'.$currency.'/payout/schema';
        $scope['query']['destinationCountry'] = $country;
        $result = DynamicPayoutEligibility::schemaObservation($this->body($schema), $scope, PayoutAmountMode::Destination);
        $this->assertSame(['USD' => 2, $currency => $scale], $result['currency_scales']);
        $this->assertSame(['DESTINATION_AMOUNT'], $result['supported_amount_modes']);
        $this->assertCount(17, $result['required']);
        $this->assertSame($schema['required'], $result['required']);
        $this->assertSame([], $result['allOf']);
        $this->assertSame($this->body($schema), $result['schema_json']);
        $this->assertSame(['minimum' => '1'], $result['amount_limits']['payout.destinationAmount']);
        $this->assertFalse($result['eligible']);
        $this->assertTrue($result['requires_instruction_validation']);
        $this->expectExceptionMessage('dynamic_payout_source_amount_contract_unverified');
        DynamicPayoutEligibility::schemaObservation($this->body($schema), $scope, PayoutAmountMode::Source);
    }

    public static function amountBounds(): array
    {
        return [
            'inclusive minimum' => ['minimum', '784', true],
            'above minimum' => ['minimum', '783.99', true],
            'below minimum' => ['minimum', '784.01', false],
            'no float rounding of minimum' => ['minimum', '784.000000000000000001', false],
            'zero minimum' => ['minimum', '0', true],
            'negative minimum' => ['minimum', '-1.5', true],
            'exponent minimum' => ['minimum', '7.84e2', true],
            'inclusive maximum' => ['maximum', '784', true],
            'below maximum' => ['maximum', '784.01', true],
            'above maximum' => ['maximum', '783.99', false],
            'no float rounding of maximum' => ['maximum', '783.999999999999999999', false],
        ];
    }

    #[DataProvider('amountBounds')]
    public function test_amount_limits_are_numeric_inclusive_and_exact(string $keyword, string $token, bool $permitted): void
    {
        $schema = $this->schema();
        unset($schema['allOf']);
        $schema['properties']['payout.destinationAmount'][$keyword] = '__BOUND__';
        $body = str_replace('"__BOUND__"', $token, $this->body($schema));
        $result = DynamicPayoutEligibility::schemaObservation($body, $this->scope(), PayoutAmountMode::Destination);
        if (! $permitted) $this->expectExceptionMessage('dynamic_payout_amount_out_of_schema_bounds');
        DynamicPayoutEligibility::assertAmounts($result, 'GBP', PayoutAmountMode::Destination, [
            'quoted_principal' => ['amount' => '980'], 'quoted_destination_amount' => ['amount' => '784']]);
        if ($permitted) $this->assertFalse($result['instruction_validated']);
    }

    public static function invalidBounds(): array
    {
        return [['null'], ['true'], ['"0"'], ['"NaN"'], ['[]'], ['{}'], ['1e1025']];
    }

    #[DataProvider('invalidBounds')]
    public function test_malformed_or_unsupported_minimum_is_not_ignored(string $token): void
    {
        $schema = $this->schema();
        $schema['properties']['payout.destinationAmount']['minimum'] = '__BOUND__';
        $this->expectExceptionMessage('dynamic_payout_schema_contract_unavailable');
        DynamicPayoutEligibility::schemaObservation(str_replace('"__BOUND__"', $token, $this->body($schema)), $this->scope());
    }

    public function test_conflicting_numeric_bounds_fail_closed(): void
    {
        $schema = $this->schema();
        $schema['properties']['payout.destinationAmount']['minimum'] = 2;
        $schema['properties']['payout.destinationAmount']['maximum'] = 1;
        $this->expectExceptionMessage('dynamic_payout_schema_contract_unavailable');
        DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope());
    }

    public function test_retained_gateway_string_maximum_is_enforced_as_an_exact_bound(): void
    {
        $schema = $this->schema();
        $schema['$schema'] = 'http://json-schema.org/draft-07/schema#';
        $schema['properties']['payout.destinationAmount']['maximum'] = '783.99';
        $result = DynamicPayoutEligibility::schemaObservation($this->body($schema),
            $this->scope('https://gatewaysandbox.nium.com'), PayoutAmountMode::Destination);
        $this->expectExceptionMessage('dynamic_payout_amount_out_of_schema_bounds');
        DynamicPayoutEligibility::assertAmounts($result, 'GBP', PayoutAmountMode::Destination, [
            'quoted_principal' => ['amount' => '980'], 'quoted_destination_amount' => ['amount' => '784']]);
    }

    public function test_source_instruction_obeys_its_own_bounds_not_the_omitted_destination_property(): void
    {
        $schema = $this->schema();
        $schema['required'] = ['payout.sourceAmount'];
        unset($schema['allOf']);
        $schema['properties']['payout.sourceAmount'] = ['type' => 'number', 'maximumDecimals' => 2,
            'minimum' => 980, 'maximum' => 980];
        $schema['properties']['payout.destinationAmount']['minimum'] = 9999;
        $result = DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope(), PayoutAmountMode::Source);
        $this->assertSame(['SOURCE_AMOUNT'], $result['supported_amount_modes']);
        DynamicPayoutEligibility::assertAmounts($result, 'GBP', PayoutAmountMode::Source, [
            'quoted_principal' => ['amount' => '980'], 'quoted_destination_amount' => ['amount' => '784']]);
        $this->expectExceptionMessage('dynamic_payout_amount_out_of_schema_bounds');
        DynamicPayoutEligibility::assertAmounts($result, 'GBP', PayoutAmountMode::Source, [
            'quoted_principal' => ['amount' => '979.99'], 'quoted_destination_amount' => ['amount' => '784']]);
    }

    public function test_two_unconditionally_required_amounts_cannot_authorize_a_single_amount_instruction(): void
    {
        $schema = $this->schema();
        unset($schema['allOf']);
        $schema['properties']['payout.sourceAmount'] = ['type' => 'number', 'maximumDecimals' => 2];
        $schema['required'][] = 'payout.sourceAmount';
        $result = DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope());
        $this->assertSame([], $result['supported_amount_modes']);
        $this->assertFalse($result['eligible']);
    }

    public function test_exact_decimal_count_extension_never_widens_the_observed_two_decimal_limit(): void
    {
        $schema = $this->schema();
        $schema['properties']['payout.destinationAmount']['maximumDecimals'] = '2';
        $result = DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope());
        $this->assertSame(['USD' => 2, 'GBP' => 2], $result['currency_scales']);
        $this->assertFalse($result['eligible']);
    }

    public function test_explicit_source_precision_cannot_override_a_required_destination_amount(): void
    {
        $schema = $this->schema();
        $schema['properties']['payout.sourceAmount'] = ['type' => 'number', 'maximumDecimals' => 2];
        $this->expectExceptionMessage('dynamic_payout_source_amount_contract_unverified');
        DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope(), PayoutAmountMode::Source);
    }

    public static function spendTuplesAndModes(): array
    {
        $cases = [];
        foreach ([['SG', 'SGD', 2], ['GB', 'GBP', 2], ['JP', 'JPY', 0], ['GB', 'GBP', 1]] as [$country, $currency, $scale]) {
            foreach (PayoutAmountMode::cases() as $mode) $cases[] = [$country, $currency, $scale, $mode];
        }
        return $cases;
    }

    #[DataProvider('spendTuplesAndModes')]
    public function test_complete_spend_schema_exposes_exact_modes_and_precision_without_granting_execution(
        string $country, string $currency, int $scale, PayoutAmountMode $mode): void
    {
        $schema = $this->schema();
        $schema['required'] = ['beneficiary.id'];
        $schema['allOf'] = [...$schema['allOf'], ...$this->amountConditions()];
        $schema['properties']['payout.sourceAmount'] = ['type' => 'number', 'maximumDecimals' => 2];
        $schema['properties']['payout.destinationAmount']['maximumDecimals'] = $scale;
        $scope = $this->scope();
        $scope['currency'] = $currency;
        $scope['path'] = '/api/v1/client/fixture-client/customer/fixture-customer/currency/'.$currency.'/payout/schema';
        $scope['query']['destinationCountry'] = $country;
        $result = DynamicPayoutEligibility::schemaObservation($this->body($schema), $scope, $mode);
        $this->assertSame(['USD' => 2, $currency => $scale], $result['currency_scales']);
        $this->assertSame(['SOURCE_AMOUNT', 'DESTINATION_AMOUNT'], $result['supported_amount_modes']);
        $this->assertSame($schema['allOf'], $result['allOf']);
        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['instruction_validated']);
        $this->assertTrue($result['requires_instruction_validation']);
    }

    public function test_draft_07_remains_scoped_to_gateway_and_requires_final_instruction_validation(): void
    {
        $schema = $this->schema();
        $schema['$schema'] = 'http://json-schema.org/draft-07/schema#';
        $schema['properties']['payout.sourceAmount'] = ['type' => 'number', 'maximumDecimals' => 2];
        // Both-mode synthetic schema: neither exclusive amount field is mandatory.
        $schema['required'] = ['beneficiary.id'];
        $schema['allOf'] = [...$schema['allOf'], ...$this->amountConditions()];
        $schema['properties']['payout.destinationAmount']['maximumDecimals'] = '2';
        foreach (['https://gateway.nium.com', 'https://gatewaysandbox.nium.com'] as $origin) {
            $result = DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope($origin), PayoutAmountMode::Source);
            $this->assertSame(['USD' => 2, 'GBP' => 2], $result['currency_scales']);
            $this->assertSame(['SOURCE_AMOUNT', 'DESTINATION_AMOUNT'], $result['supported_amount_modes']);
            $this->assertTrue($result['requires_instruction_validation']);
            $this->assertFalse($result['instruction_validated']);
            $encoded = json_encode($this->body($schema), JSON_THROW_ON_ERROR);
            $this->assertSame($result, DynamicPayoutEligibility::schemaObservation($encoded, $this->scope($origin), PayoutAmountMode::Source));
        }
    }

    public function test_gateway_destination_only_schema_does_not_advertise_source_mode(): void
    {
        $schema = $this->schema();
        $schema['$schema'] = 'http://json-schema.org/draft-07/schema#';
        $scope = $this->scope('https://gatewaysandbox.nium.com');
        $result = DynamicPayoutEligibility::schemaObservation($this->body($schema), $scope, PayoutAmountMode::Destination);
        $this->assertSame(['DESTINATION_AMOUNT'], $result['supported_amount_modes']);
        $this->assertFalse($result['instruction_validated']);
        $this->expectExceptionMessage('dynamic_payout_source_amount_contract_unverified');
        DynamicPayoutEligibility::schemaObservation($this->body($schema), $scope, PayoutAmountMode::Source);
    }

    public function test_retained_draft07_example_keeps_required_destination_and_remitter_conditions(): void
    {
        // Retained OpenAPI example, not an authenticated Origin customer response.
        $schema = ['$schema' => 'http://json-schema.org/draft-07/schema#', '$id' => 'unified.sgd.local.json',
            'type' => 'object', 'title' => 'SGD Local Unified Schema',
            'required' => ['payout.destinationAmount', 'beneficiary.destinationCountry', 'remitter.name',
                'remitter.accountType', 'beneficiary.name', 'beneficiary.accountType',
                'beneficiary.routingCodeType1', 'beneficiary.routingCodeValue1'],
            'properties' => [
                'beneficiary.accountType' => ['enum' => ['INDIVIDUAL', 'CORPORATE'], 'title' => 'Beneficiary Account Type', 'type' => 'string'],
                'payout.destinationAmount' => ['$id' => '/properties/payout.destinationAmount', 'maximum' => '10000000',
                    'maximumDecimals' => '2', 'title' => 'Destination Amount', 'type' => 'number'],
                'remitter.accountType' => ['enum' => ['INDIVIDUAL', 'CORPORATE'], 'title' => 'Remitter Account Type', 'type' => 'string']],
            'allOf' => [['if' => ['properties' => ['remitter.accountType' => ['const' => 'INDIVIDUAL']],
                'required' => ['remitter.accountType']],
                'then' => ['required' => ['remitter.dob', 'remitter.identificationType', 'remitter.identificationNumber']]]]];
        $scope = $this->scope('https://gatewaysandbox.nium.com');
        $scope['currency'] = 'SGD';
        $scope['path'] = '/api/v1/client/fixture-client/customer/fixture-customer/currency/SGD/payout/schema';
        $scope['query']['destinationCountry'] = 'SG';
        $result = DynamicPayoutEligibility::schemaObservation($this->body($schema), $scope);
        $this->assertSame(['USD' => 2, 'SGD' => 2], $result['currency_scales']);
        $this->assertSame(['DESTINATION_AMOUNT'], $result['supported_amount_modes']);
        $this->assertSame($schema['required'], $result['required']);
        $this->assertSame($schema['allOf'], $result['allOf']);
        $this->assertFalse($result['instruction_validated']);
        $this->assertFalse($result['eligible']); // Parsing alone grants no customer entitlement or executable Review.
    }

    public function test_source_property_does_not_override_an_unconditional_destination_requirement(): void
    {
        $schema = $this->schema();
        $schema['$schema'] = 'http://json-schema.org/draft-07/schema#';
        $schema['properties']['payout.sourceAmount'] = ['type' => 'number', 'maximumDecimals' => 2];
        $scope = $this->scope('https://gatewaysandbox.nium.com');
        $result = DynamicPayoutEligibility::schemaObservation($this->body($schema), $scope);
        $this->assertSame(['DESTINATION_AMOUNT'], $result['supported_amount_modes']);
        $this->expectExceptionMessage('dynamic_payout_source_amount_contract_unverified');
        DynamicPayoutEligibility::schemaObservation($this->body($schema), $scope, PayoutAmountMode::Source);
    }

    public static function unsupportedConditions(): array
    {
        return [
            [['if' => (object) [], 'then' => (object) [], 'else' => (object) []]],
            [['if' => ['anyOf' => [(object) []]], 'then' => (object) []]],
            [['if' => ['properties' => ['remitter.accountType' => ['enum' => ['INDIVIDUAL']]]], 'then' => (object) []]],
            [['if' => ['properties' => ['synthetic.flag' => ['const' => []]]], 'then' => (object) []]],
            [['if' => (object) [], 'then' => ['properties' => (object) []]]],
        ];
    }

    #[DataProvider('unsupportedConditions')]
    public function test_unknown_conditional_semantics_are_explicitly_refused(array $condition): void
    {
        $schema = $this->schema();
        $schema['allOf'] = [$condition];
        $this->expectExceptionMessage('dynamic_payout_schema_conditional_unsupported');
        DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope());
    }

    public function test_unknown_root_composition_is_not_silently_ignored(): void
    {
        $schema = $this->schema();
        $schema['oneOf'] = [(object) []];
        $this->expectExceptionMessage('dynamic_payout_schema_contract_unavailable');
        DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope());
    }

    public function test_both_precision_properties_without_amount_requirements_supply_no_modes(): void
    {
        $schema = $this->schema();
        unset($schema['allOf']);
        $schema['required'] = ['beneficiary.id'];
        $schema['properties']['payout.sourceAmount'] = ['type' => 'number', 'maximumDecimals' => 2];
        $result = DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope());
        $this->assertSame(['USD' => 2, 'GBP' => 2], $result['currency_scales']);
        $this->assertSame([], $result['supported_amount_modes']);
        $this->assertFalse($result['eligible']);
        $this->expectExceptionMessage('dynamic_payout_source_amount_contract_unverified');
        DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope(), PayoutAmountMode::Source);
    }

    #[DataProvider('modes')]
    public function test_conditional_amount_requirement_is_retained_for_exact_provider_validation(PayoutAmountMode $mode, string $reason): void
    {
        $schema = $this->schema();
        $schema['required'] = ['beneficiary.id'];
        $schema['properties']['payout.sourceAmount'] = ['type' => 'number', 'maximumDecimals' => 2];
        $schema['allOf'] = [$this->amountConditions()[$mode === PayoutAmountMode::Source ? 0 : 1]];
        $result = DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope(), $mode);
        $this->assertSame([$mode->value], $result['supported_amount_modes']);
        $this->assertSame($schema['allOf'], $result['allOf']);
        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['instruction_validated']);
        $this->assertTrue($result['requires_instruction_validation']);
        $schema['allOf'] = $this->schema()['allOf'];
        $this->expectExceptionMessage($reason);
        DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope(), $mode);
    }

    public function test_amount_reference_with_additional_unknown_semantics_cannot_supply_precision_authority(): void
    {
        $schema = $this->schema();
        $schema['properties']['payout.destinationAmount']['$ref'] = '#/$defs/unknownAmount';
        $this->expectExceptionMessage('dynamic_payout_schema_contract_unavailable');
        DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope());
    }

    public static function gatewayTuples(): array
    {
        return [['DE', 'EUR', 'LOCAL', 2], ['JP', 'JPY', 'LOCAL', 0], ['GB', 'GBP', 'SWIFT', 2]];
    }

    #[DataProvider('gatewayTuples')]
    public function test_gateway_read_dialect_tracks_actual_tuple_and_precision_without_authorizing_execution(
        string $country, string $currency, string $rail, int $precision): void
    {
        $schema = $this->schema();
        $schema['$schema'] = 'http://json-schema.org/draft-07/schema#';
        $schema['properties']['payout.destinationAmount']['maximumDecimals'] = $precision;
        $scope = $this->scope('https://gatewaysandbox.nium.com');
        $scope['currency'] = $currency;
        $scope['path'] = '/api/v1/client/fixture-client/customer/fixture-customer/currency/'.$currency.'/payout/schema';
        $scope['query'] = ['destinationCountry' => $country, 'payoutMethod' => $rail];
        $result = DynamicPayoutEligibility::schemaObservation($this->body($schema), $scope);
        $this->assertSame($precision, $result['currency_scales'][$currency]);
        $this->assertSame(['DESTINATION_AMOUNT'], $result['supported_amount_modes']);
        $this->assertFalse($result['eligible']);
        $this->assertTrue($result['requires_instruction_validation']);
        $this->assertFalse((new Decimal('1.001'))->fitsScale($precision));
    }

    public static function wrongDialects(): array
    {
        return [
            ['https://api.spend.nium.com', 'http://json-schema.org/draft-07/schema#'],
            ['https://gateway.nium.com', 'https://json-schema.org/draft/2020-12/schema'],
            ['https://api.spend.nium.com', 'http://json-schema.org/draft/2020-12/schema'],
            ['https://api.spend.nium.com', 'https://json-schema.org/draft/2019-09/schema'],
        ];
    }

    #[DataProvider('wrongDialects')]
    public function test_dialects_are_never_selected_by_shape_or_arbitrary_version(string $origin, string $dialect): void
    {
        $schema = $this->schema();
        $schema['$schema'] = $dialect;
        $this->expectExceptionMessage('dynamic_payout_schema_contract_unavailable');
        DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope($origin));
    }

    public static function malformedSchemas(): array
    {
        return [
            [['type' => 'array']], [['properties' => []]], [['required' => null]],
            [['properties' => ['payout.destinationAmount' => ['type' => 'number', 'maximumDecimals' => 2], 'synthetic.field' => 5]]],
            [['required' => 'payout.destinationAmount']], [['required' => [false]]],
            [['required' => ['payout.destinationAmount', 'payout.destinationAmount']]],
            [['allOf' => null]], [['allOf' => []]], [['allOf' => ['if' => [], 'then' => []]]],
            [['allOf' => [['then' => (object) []]]]], [['allOf' => [['if' => (object) []]]]],
            [['allOf' => [['if' => true, 'then' => (object) []]]]],
            [['allOf' => [['if' => ['properties' => []], 'then' => (object) []]]]],
            [['allOf' => [['if' => ['properties' => ['synthetic.field' => 'invalid']], 'then' => (object) []]]]],
            [['allOf' => [['if' => (object) [], 'then' => ['required' => 'synthetic.field']]]]],
            [['properties' => ['payout.destinationAmount' => ['type' => 'string', 'maximumDecimals' => 2]]]],
            [['properties' => ['payout.destinationAmount' => ['type' => 'number']]]],
            [['properties' => ['payout.destinationAmount' => ['type' => 'number', 'maximumDecimals' => '2.5']]]],
            [['properties' => ['payout.destinationAmount' => ['type' => 'number', 'maximumDecimals' => true]]]],
            [['properties' => ['payout.destinationAmount' => ['type' => 'number', 'maximumDecimals' => 3]]]],
            [['properties' => ['payout.destinationAmount' => ['type' => 'number', 'maximumDecimals' => -1]]]],
        ];
    }

    #[DataProvider('malformedSchemas')]
    public function test_unknown_or_malformed_structure_cannot_be_a_schema_observation(array $changes): void
    {
        $this->expectExceptionMessage('dynamic_payout_schema_contract_unavailable');
        DynamicPayoutEligibility::schemaObservation($this->body([...$this->schema(), ...$changes]), $this->scope());
    }

    public static function missingSchemaKeywords(): array
    {
        return [['$schema'], ['type'], ['properties'], ['required']];
    }

    #[DataProvider('missingSchemaKeywords')]
    public function test_required_schema_structure_is_not_defaulted(string $keyword): void
    {
        $schema = $this->schema();
        unset($schema[$keyword]);
        $this->expectExceptionMessage('dynamic_payout_schema_contract_unavailable');
        DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope());
    }

    public function test_spend_rejects_wrapper_and_string_encoded_schema(): void
    {
        foreach ([$this->body(['data' => $this->schema()]), json_encode($this->body(), JSON_THROW_ON_ERROR)] as $body) {
            try {
                DynamicPayoutEligibility::schemaObservation($body, $this->scope());
                $this->fail('Spend schema must be a direct root object.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('dynamic_payout_schema_contract_unavailable', $exception->getMessage());
            }
        }
    }

    public function test_unapproved_origin_does_not_reach_the_schema_parser(): void
    {
        $this->expectExceptionMessage('dynamic_provider_environment_unverified');
        DynamicPayoutEligibility::schemaObservation($this->body(), $this->scope('https://unknown.nium.com'));
    }

    public static function wrongScopes(): array
    {
        return [
            [['path' => '/custom/payout/schema']],
            [['customer' => 'foreign-customer']], [['client' => 'foreign-client']],
            [['query' => ['destinationCountry' => 'G', 'payoutMethod' => 'LOCAL']]],
            [['query' => ['destinationCountry' => 'GB', 'payoutMethod' => 'local']]],
            [['query' => ['destinationCountry' => 'GB', 'payoutMethod' => 'LOCAL', 'extra' => 'ignored']]],
        ];
    }

    #[DataProvider('wrongScopes')]
    public function test_wrong_customer_endpoint_or_query_cannot_use_the_spend_schema(array $changes): void
    {
        $this->expectExceptionMessage('dynamic_payout_schema_endpoint_unverified');
        DynamicPayoutEligibility::schemaObservation($this->body(), [...$this->scope(), ...$changes]);
    }
}
