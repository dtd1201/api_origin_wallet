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

    public function test_spend_2020_12_schema_retains_conditions_and_observes_only_destination_precision(): void
    {
        $result = DynamicPayoutEligibility::schemaObservation($this->body(), $this->scope());
        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $result['schema_uri']);
        $this->assertSame(['GBP' => 2], $result['currency_scales']);
        $this->assertSame([], $result['supported_amount_modes']);
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
        $this->expectExceptionMessage($reason);
        DynamicPayoutEligibility::schemaObservation($this->body(), $this->scope(), $mode);
    }

    public function test_destination_amount_is_required_for_a_precision_observation(): void
    {
        $schema = $this->schema();
        $schema['properties'] = (object) [];
        $this->expectExceptionMessage('dynamic_payout_schema_destination_amount_unverified');
        DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope());
    }

    public function test_exact_decimal_count_extension_never_widens_the_observed_two_decimal_limit(): void
    {
        $schema = $this->schema();
        $schema['properties']['payout.destinationAmount']['maximumDecimals'] = '2';
        $result = DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope());
        $this->assertSame(['GBP' => 2], $result['currency_scales']);
        $this->assertFalse($result['eligible']);
    }

    public function test_unobserved_source_amount_schema_extension_cannot_enable_source_mode(): void
    {
        $schema = $this->schema();
        $schema['properties']['payout.sourceAmount'] = ['type' => 'number', 'maximumDecimals' => 2];
        $this->expectExceptionMessage('dynamic_payout_schema_contract_unavailable');
        DynamicPayoutEligibility::schemaObservation($this->body($schema), $this->scope(), PayoutAmountMode::Source);
    }

    public function test_draft_07_remains_scoped_to_gateway_and_requires_final_instruction_validation(): void
    {
        $schema = $this->schema();
        $schema['$schema'] = 'http://json-schema.org/draft-07/schema#';
        $schema['properties']['payout.sourceAmount'] = ['type' => 'number', 'maximumDecimals' => 2];
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
            [['properties' => ['payout.destinationAmount' => ['type' => 'number', 'maximumDecimals' => 0]]]],
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
        return [['$schema'], ['type'], ['properties'], ['required'], ['allOf']];
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
            [['query' => ['destinationCountry' => 'US', 'payoutMethod' => 'LOCAL']]],
            [['query' => ['destinationCountry' => 'GB', 'payoutMethod' => 'SWIFT']]],
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
