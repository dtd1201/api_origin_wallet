<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nium\DynamicConfirmation\DynamicPayoutEligibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NiumDynamicPayoutEligibilityTest extends TestCase
{
    private function row(): array
    {
        return ['client_legal_entity' => ['LEGAL_ENTITY_HK'], 'destination_country' => ['GB'],
            'destination_currency' => 'GBP', 'payout_method' => 'LOCAL', 'routing_code_type' => 'SORT CODE', 'ignoreLookup' => false];
    }

    private function spendRow(): array
    {
        return ['destination_currency' => 'GBP', 'payout_method' => 'LOCAL',
            'destination_country' => ['GB'], 'routing_code_type' => 'synthetic-route-a'];
    }

    public function test_retained_v1_openapi_example_is_selected_by_legal_entity_and_exact_tuple(): void
    {
        // Documented example only; no Origin account authorization is implied.
        $row = ['client_legal_entity' => ['LEGAL_ENTITY_BR'], 'destination_country' => ['BR'],
            'destination_currency' => 'BRL', 'ignoreLookup' => false, 'payout_method' => 'LOCAL',
            'routing_code_type' => 'BRANCH CODE'];
        $result = DynamicPayoutEligibility::select(['data' => [$row]], 'BR', 'BRL', 'BR', 'LOCAL');
        $this->assertSame(['legal_entity' => 'BR', 'destination_country' => 'BR', 'destination_currency' => 'BRL',
            'payout_method' => 'LOCAL', 'routing_code_type' => 'BRANCH CODE'], $result);
    }

    public function test_spend_four_field_envelope_lists_support_without_authorizing_an_instruction(): void
    {
        $body = ['requestId' => 'synthetic-request', 'correlationId' => 'synthetic-correlation',
            'timestamp' => 'synthetic-provider-timestamp', 'data' => [$this->spendRow()]];
        $listing = DynamicPayoutEligibility::listing($body, 'GB', 'GBP');
        $this->assertSame('LISTED', $listing['status']);
        $this->assertFalse($listing['eligible']);
        $this->assertSame([$this->spendRow()], $listing['matches']);
        $this->assertSame([], $listing['reason_codes']);
        $this->assertArrayNotHasKey('client_legal_entity', $listing['matches'][0]);
        $this->assertArrayNotHasKey('ignoreLookup', $listing['matches'][0]);
    }

    public function test_both_spend_gbp_local_records_are_retained_without_selecting_a_route(): void
    {
        $rows = [$this->spendRow(), [...$this->spendRow(), 'routing_code_type' => 'synthetic-route-b']];
        $listing = DynamicPayoutEligibility::listing(['data' => $rows], 'GB', 'GBP');
        $this->assertSame($rows, $listing['matches']);
        $this->assertFalse($listing['eligible']);
        $this->expectExceptionMessage('dynamic_customer_corridor_ambiguous');
        DynamicPayoutEligibility::select(['data' => $rows], 'GB', 'GBP', 'HK');
    }

    public static function omittedMetadata(): array
    {
        return [
            ['client_legal_entity'], ['ignoreLookup'],
        ];
    }

    #[DataProvider('omittedMetadata')]
    public function test_missing_optional_metadata_is_not_required_or_fabricated(string $field): void
    {
        $row = $this->row();
        unset($row[$field]);
        $listing = DynamicPayoutEligibility::listing(['data' => [$row]], 'GB', 'GBP');
        $this->assertFalse($listing['eligible']);
        $this->assertSame([], $listing['reason_codes']);
        $this->assertArrayNotHasKey($field, $listing['matches'][0]);
        $selected = DynamicPayoutEligibility::select(['data' => [$row]], 'GB', 'GBP', 'HK');
        $this->assertSame('HK', $selected['legal_entity']); // Supplied registration entity, not a corridor default.
        $this->assertArrayNotHasKey($field, $selected);
    }

    public static function malformedSpendFields(): array
    {
        return [
            [['destination_country' => 'GB']], [['destination_country' => ['GB', null]]],
            [['routing_code_type' => null]], [['client_legal_entity' => null]],
            [['client_legal_entity' => ['LEGAL_ENTITY_HK', false]]], [['ignoreLookup' => null]],
            [['ignoreLookup' => 'false']], [['ignoreLookup' => 0]],
        ];
    }

    #[DataProvider('malformedSpendFields')]
    public function test_malformed_listing_or_optional_authorization_fields_remain_unavailable(array $changes): void
    {
        $this->expectExceptionMessage('dynamic_customer_corridor_response_contract_unavailable');
        DynamicPayoutEligibility::listing(['data' => [[...$this->spendRow(), ...$changes]]], 'GB', 'GBP');
    }

    public function test_unknown_tuple_is_not_listed_and_does_not_inherit_another_country_or_rail(): void
    {
        foreach ([['US', 'GBP', 'LOCAL'], ['GB', 'EUR', 'LOCAL'], ['GB', 'GBP', 'SWIFT']] as [$country, $currency, $rail]) {
            $listing = DynamicPayoutEligibility::listing(['data' => [$this->spendRow()]], $country, $currency, $rail);
            $this->assertSame('NOT_LISTED', $listing['status']);
            $this->assertFalse($listing['eligible']);
            $this->assertSame([], $listing['matches']);
            $this->assertSame(['dynamic_customer_corridor_not_permitted'], $listing['reason_codes']);
        }
    }

    public function test_complete_row_cannot_hide_another_matching_four_field_row(): void
    {
        $this->expectExceptionMessage('dynamic_customer_corridor_ambiguous');
        DynamicPayoutEligibility::select(['data' => [$this->row(), $this->spendRow()]], 'GB', 'GBP', 'HK');
    }

    public function test_documented_customer_response_selects_only_the_exact_entity_country_currency_and_rail(): void
    {
        $row = $this->row();
        $selected = DynamicPayoutEligibility::select(['data' => [
            [...$row, 'destination_currency' => 'EUR'], [...$row, 'payout_method' => 'SWIFT'], $row]], 'GB', 'GBP', 'HK');
        $this->assertSame(['legal_entity' => 'HK', 'destination_country' => 'GB', 'destination_currency' => 'GBP',
            'payout_method' => 'LOCAL', 'routing_code_type' => 'SORT CODE'], $selected);
    }

    public static function refusals(): array
    {
        return [
            'catalogue is not customer entitlement' => [['content' => []], 'dynamic_customer_corridor_response_contract_unavailable'],
            'unknown response remains unavailable' => [['unverifiedCustomerResponse' => []], 'dynamic_customer_corridor_response_contract_unavailable'],
            'empty list denies this corridor' => [['data' => []], 'dynamic_customer_corridor_not_permitted'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_unsupported_or_denied_evidence_never_grants_eligibility(array $body, string $reason): void
    {
        $this->expectExceptionMessage($reason);
        DynamicPayoutEligibility::select($body, 'GB', 'GBP', 'HK');
    }

    public function test_a_different_customer_legal_entity_cannot_be_used_as_a_fallback(): void
    {
        $this->expectExceptionMessage('dynamic_customer_corridor_not_permitted');
        DynamicPayoutEligibility::select(['data' => [$this->row()]], 'GB', 'GBP', 'SG');
    }

    public static function registeredCorridors(): array
    {
        return [['HK', 'HKD', 'LOCAL'], ['SG', 'SGD', 'LOCAL'], ['GB', 'GBP', 'LOCAL'],
            ['DK', 'EUR', 'LOCAL'], ['DE', 'EUR', 'LOCAL'], ['JP', 'JPY', 'LOCAL'],
            ['TH', 'THB', 'LOCAL'], ['KR', 'KRW', 'LOCAL'], ['CH', 'EUR', 'LOCAL']];
    }

    #[DataProvider('registeredCorridors')]
    public function test_exact_customer_tuple_can_be_observed_without_granting_dynamic_dispatch(string $country, string $currency, string $rail): void
    {
        // Synthetic four-field rows; the caller's registration evidence supplies entity.
        $row = [...$this->spendRow(), 'destination_country' => [$country], 'destination_currency' => $currency,
            'payout_method' => $rail];
        $selected = DynamicPayoutEligibility::select(['data' => [$row]], $country, $currency, 'HK', $rail);
        $this->assertSame($rail, $selected['payout_method']);
        $this->assertSame($currency, $selected['destination_currency']);
        $this->assertSame('HK', $selected['legal_entity']);
        $this->assertArrayNotHasKey('client_legal_entity', $selected);
        $this->assertArrayNotHasKey('ignoreLookup', $selected);
        $this->expectExceptionMessage('dynamic_customer_corridor_not_permitted');
        DynamicPayoutEligibility::select(['data' => [$row]], $country, $currency, 'HK', $rail === 'LOCAL' ? 'SWIFT' : 'LOCAL');
    }

    public function test_duplicate_matching_rows_are_ambiguous(): void
    {
        $this->expectExceptionMessage('dynamic_customer_corridor_ambiguous');
        DynamicPayoutEligibility::select(['data' => [$this->row(), $this->row()]], 'GB', 'GBP', 'HK');
    }

    public function test_lookup_bypass_is_not_interpreted_as_authority(): void
    {
        $this->expectExceptionMessage('dynamic_customer_corridor_response_contract_unavailable');
        DynamicPayoutEligibility::select(['data' => [[...$this->row(), 'ignoreLookup' => true]]], 'GB', 'GBP', 'HK');
    }

    public function test_four_field_row_cannot_supply_a_missing_registration_entity(): void
    {
        $this->expectExceptionMessage('dynamic_quote_registration_evidence_unavailable');
        DynamicPayoutEligibility::select(['data' => [$this->spendRow()]], 'GB', 'GBP', '');
    }

    public function test_payout_schema_can_narrow_documented_remittance_precision_without_rounding(): void
    {
        $observation = DynamicPayoutEligibility::schemaObservation(json_encode([
            '$schema' => 'http://json-schema.org/draft-07/schema#', 'type' => 'object',
            'required' => ['payout.destinationAmount'], 'allOf' => [['if' => (object) [], 'then' => (object) []]],
            'properties' => ['payout.destinationAmount' => ['type' => 'number', 'maximumDecimals' => '0']]], JSON_THROW_ON_ERROR),
            ['base_url' => 'https://gatewaysandbox.nium.com', 'client' => 'fixture-client', 'customer' => 'fixture-customer',
                'currency' => 'JPY', 'path' => '/api/v1/client/fixture-client/customer/fixture-customer/currency/JPY/payout/schema',
                'query' => ['destinationCountry' => 'JP', 'payoutMethod' => 'LOCAL']]);
        $this->assertSame(['USD' => 2, 'JPY' => 0], $observation['currency_scales']);
        $this->assertFalse($observation['instruction_validated']);
    }

    public function test_unknown_schema_precision_is_not_invented(): void
    {
        $this->expectExceptionMessage('dynamic_payout_schema_contract_unavailable');
        DynamicPayoutEligibility::schemaObservation(json_encode([
            '$schema' => 'http://json-schema.org/draft-07/schema#', 'type' => 'object',
            'required' => ['payout.destinationAmount'], 'allOf' => [['if' => (object) [], 'then' => (object) []]],
            'properties' => ['payout.destinationAmount' => ['type' => 'number', 'maximumDecimals' => 'unlimited']]], JSON_THROW_ON_ERROR),
            ['base_url' => 'https://gatewaysandbox.nium.com', 'client' => 'fixture-client', 'customer' => 'fixture-customer',
                'currency' => 'GBP', 'path' => '/api/v1/client/fixture-client/customer/fixture-customer/currency/GBP/payout/schema',
                'query' => ['destinationCountry' => 'GB', 'payoutMethod' => 'LOCAL']]);
    }
}
