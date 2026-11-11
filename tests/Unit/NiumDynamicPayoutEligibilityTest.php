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

    public function test_payout_schema_can_narrow_documented_remittance_precision_without_rounding(): void
    {
        $this->assertSame(['USD' => 2, 'JPY' => 0], DynamicPayoutEligibility::scales([
            '$schema' => 'http://json-schema.org/draft-07/schema#', 'type' => 'object',
            'properties' => ['payout.destinationAmount' => ['type' => 'number', 'maximumDecimals' => '0']]], 'JPY'));
    }

    public function test_unknown_schema_precision_is_not_invented(): void
    {
        $this->expectExceptionMessage('dynamic_payout_schema_contract_unavailable');
        DynamicPayoutEligibility::scales(['$schema' => 'http://json-schema.org/draft-07/schema#', 'type' => 'object',
            'properties' => ['payout.destinationAmount' => ['type' => 'number', 'maximumDecimals' => 'unlimited']]], 'GBP');
    }
}
