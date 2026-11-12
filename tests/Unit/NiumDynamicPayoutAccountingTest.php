<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutAccounting;
use App\Services\Nium\DynamicConfirmation\ExactProviderJson;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NiumDynamicPayoutAccountingTest extends TestCase
{
    private function evaluate(array $changes = []): array
    {
        $intent = (new NiumDynamicIntent)->forceFill(['source_wallet' => 'wallet', 'customer' => 'customer',
            'destination_currency' => 'GBP', 'total_debit' => '1000', 'confirmed_terms' => [
                'context' => ['corridor' => ['client' => 'client'], 'external_beneficiary_id' => 'beneficiary'],
                'financial_terms' => ['fx_rate' => '0.8', 'principal' => ['amount' => '980'], 'transaction_fee' => ['amount' => '20'],
                    'estimated_recipient' => ['amount' => '784']],
            ]]);
        $outbox = (new NiumDynamicPayoutOutbox)->forceFill(['provider_reference' => 'RT-1', 'operation_key' => 'operation']);
        $scope = ['clientHashId' => 'client', 'customerHashId' => 'customer', 'walletHashId' => 'wallet',
            'authCurrencyCode' => 'USD', 'debit' => true, 'status' => 'Approved', 'settlementStatus' => 'Settled'];
        $rows = [
            [...$scope, 'authCode' => 'RT-1', 'transactionType' => 'Remittance_Debit_External',
                'settlementAuthAmount' => '980', 'effectiveAuthAmount' => '1000', 'settlementTransactionAmount' => '784',
                'transactionCurrencyCode' => 'GBP', 'labels' => ['beneficiaryId' => 'beneficiary']],
            [...$scope, 'authCode' => 'FT-1', 'transactionType' => 'Fee_Debit', 'settlementAuthAmount' => '20',
                'labels' => ['originalAuthorizationCode' => 'RT-1', 'feeName' => 'REMIT_BANK_FEE']],
        ];
        foreach ($changes as $index => $fields) {
            if ($fields === null) {
                unset($rows[$index]);
            } else {
                $rows[$index] = array_replace($rows[$index], $fields);
            }
        }

        return (new DynamicPayoutAccounting)->evaluate($intent, $outbox,
            ['records' => array_values($rows), 'response_sha256' => [str_repeat('a', 64)], 'observed_at' => '2030-01-01T00:00:00Z']);
    }

    public function test_effective_amount_is_not_added_to_the_separate_fee_again(): void
    {
        $result = $this->evaluate();
        $this->assertSame('matched', $result['state']);
        $this->assertSame('1000', $result['receipt']['wallet_debit_usd']);
    }

    public function test_provider_cost_difference_never_reprices_customer_consent(): void
    {
        foreach (['10' => '-10', '30' => '10'] as $providerFee => $delta) {
            $result = $this->evaluate([1 => ['settlementAuthAmount' => (string) $providerFee]]);
            $this->assertSame('mismatched', $result['state']);
            $this->assertSame('1000', $result['receipt']['customer_authorized_debit_usd']);
            $this->assertSame('20', $result['receipt']['customer_fee_allocation_usd']);
            $this->assertSame($delta, $result['receipt']['provider_minus_customer_usd']);
            $this->assertTrue($result['receipt']['discrepancy']);
        }
    }

    public function test_actual_recipient_amount_is_recorded_separately_from_quote_estimate(): void
    {
        $result = $this->evaluate([0 => ['settlementTransactionAmount' => '783']]);
        $this->assertSame('matched', $result['state']);
        $this->assertSame('783', $result['receipt']['destination_amount']);
        $this->assertSame('784', $result['receipt']['estimated_destination_amount']);
        $this->assertSame('-1', $result['receipt']['destination_minus_estimate']);
    }

    public function test_explicit_zero_fee_observation_is_retained_as_a_provider_cost_difference(): void
    {
        $result = $this->evaluate([1 => ['settlementAuthAmount' => '0']]);
        $this->assertSame('mismatched', $result['state']);
        $this->assertSame('0', $result['receipt']['fee_usd']);
        $this->assertSame('980', $result['receipt']['wallet_debit_usd']);
        $this->assertSame('-20', $result['receipt']['provider_minus_customer_usd']);
    }

    public function test_other_provider_fee_currency_is_recorded_without_conversion_or_customer_repricing(): void
    {
        $result = $this->evaluate([1 => ['authCurrencyCode' => 'EUR', 'settlementAuthAmount' => '7.25']]);
        $this->assertSame('mismatched', $result['state']);
        $this->assertSame(['USD' => '980', 'EUR' => '7.25'], $result['receipt']['provider_wallet_debits']);
        $this->assertNull($result['receipt']['provider_minus_customer_usd']);
        $this->assertNull($result['receipt']['wallet_debit_usd']);
        $this->assertSame('1000', $result['receipt']['customer_authorized_debit_usd']);
    }

    public function test_observed_fx_rate_is_compared_to_the_quote_without_applying_markup_again(): void
    {
        foreach (['0.8' => 'matched', '0.79' => 'mismatched'] as $rate => $state) {
            $result = $this->evaluate([0 => ['labels' => ['beneficiaryId' => 'beneficiary',
                'netExchangeRate' => $rate, 'markupRate' => '0.13']]]);
            $this->assertSame($state, $result['state']);
            $this->assertSame(['netExchangeRate' => $rate, 'markupRate' => '0.13'], $result['receipt']['fx_observations']);
            $this->assertSame('1000', $result['receipt']['customer_authorized_debit_usd']);
        }
    }

    public static function terminalStatuses(): array
    {
        return [['Returned', 'RETURNED'], ['Rejected', 'REJECTED'], ['Declined', 'REJECTED'],
            ['Failed', 'FAILED'], ['Error', 'FAILED'], ['Blocked', 'FAILED'], ['Cancelled', 'CANCELLED']];
    }

    #[DataProvider('terminalStatuses')]
    public function test_correlated_terminal_remittance_status_is_available_to_customer_reconciliation(string $status, string $expected): void
    {
        $result = $this->evaluate([0 => ['status' => $status]]);
        $this->assertSame('exception', $result['state']);
        $this->assertSame('RT-1', $result['provider_reference']);
        $this->assertSame($expected, $result['provider_status']);
    }

    public function test_failed_fee_or_released_settlement_does_not_invent_a_returned_payout(): void
    {
        foreach ([[1 => ['status' => 'Failed']], [0 => ['settlementStatus' => 'Released']]] as $changes) {
            $result = $this->evaluate($changes);
            $this->assertSame('exception', $result['state']);
            $this->assertArrayNotHasKey('provider_status', $result);
        }
    }

    public static function cases(): array
    {
        return [
            'missing fee is not zero' => [[1 => null], 'pending'],
            'pending remittance' => [[0 => ['settlementStatus' => 'Unsettled']], 'pending'],
            'pending fee' => [[1 => ['settlementStatus' => 'Unsettled']], 'pending'],
            'fee lacks linkage' => [[1 => ['labels' => ['feeName' => 'REMIT_BANK_FEE']]], 'unavailable'],
            'missing settled debit' => [[1 => ['settlementAuthAmount' => null]], 'unavailable'],
            'missing receive amount' => [[0 => ['settlementTransactionAmount' => null]], 'unavailable'],
            'wrong wallet' => [[1 => ['walletHashId' => 'other']], 'unavailable'],
            'wrong beneficiary' => [[0 => ['labels' => ['beneficiaryId' => 'other']]], 'unavailable'],
            'other fee currency is retained without FX invention' => [[1 => ['authCurrencyCode' => 'EUR']], 'mismatched'],
            'missing fee identity' => [[1 => ['labels' => ['originalAuthorizationCode' => 'RT-1']]], 'unavailable'],
            'different provider fee is an operational discrepancy' => [[1 => ['labels' => ['originalAuthorizationCode' => 'RT-1', 'feeName' => 'OTHER_PROVIDER_FEE']]], 'mismatched'],
            'confirmed extra dollar' => [[1 => ['settlementAuthAmount' => '21']], 'mismatched'],
            'confirmed principal mismatch' => [[0 => ['settlementAuthAmount' => '1000']], 'mismatched'],
            'actual receive amount differs from reference estimate' => [[0 => ['settlementTransactionAmount' => '783']], 'matched'],
            'zero principal cannot prove a wallet debit' => [[0 => ['settlementAuthAmount' => '0']], 'unavailable'],
            'contradictory fee linkage' => [[1 => ['originalAuthorizationCode' => 'other']], 'unavailable'],
            'returned payout' => [[0 => ['status' => 'Returned']], 'exception'],
            'failed fee' => [[1 => ['status' => 'Failed']], 'exception'],
            'released fee' => [[1 => ['settlementStatus' => 'Released']], 'exception'],
            'fee reversal' => [[1 => ['transactionType' => 'Fee_Reversal']], 'exception'],
            'unsafe numeric exponent' => [[1 => ['settlementAuthAmount' => '2e1']], 'unavailable'],
            'float is not exact evidence' => [[1 => ['settlementAuthAmount' => 20.0]], 'unavailable'],
        ];
    }

    #[DataProvider('cases')]
    public function test_evidence_classes_remain_distinct(array $changes, string $expected): void
    {
        $this->assertSame($expected, $this->evaluate($changes)['state']);
    }

    public function test_large_json_money_tokens_survive_without_float_rounding(): void
    {
        $data = ExactProviderJson::decode('{"content":[{"settlementAuthAmount":9007199254740993.01}],"totalElements":1}');
        $this->assertSame('9007199254740993.01', $data['content'][0]['settlementAuthAmount']);
        $this->assertSame('1', $data['totalElements']);
    }

    public function test_duplicate_nested_provider_fields_are_rejected(): void
    {
        $this->expectExceptionMessage('dynamic_provider_duplicate_field');
        ExactProviderJson::decode('{"content":[{"authAmount":980,"authAmount":1000}]}');
    }

    public function test_live_hk_swift_is_excluded_from_the_dynamic_payload_builder(): void
    {
        $intent = (new NiumDynamicIntent)->forceFill(['source_currency' => 'USD', 'destination_currency' => 'USD',
            'confirmed_terms' => ['context' => ['corridor' => ['payoutMethod' => 'SWIFT']],
                'remittance_instruction' => ['purposeCode' => 'IR005', 'sourceOfFunds' => 'Salary', 'customerComments' => 'Invoice']]]);
        $this->expectExceptionMessage('dynamic_execution_corridor_unsupported');
        DynamicPayoutRequest::json($intent, 'fixture-operation');
    }

    public static function instructionScales(): array
    {
        return [
            'three decimals allowed by existing contract' => [3, true],
            'two decimals reject excess precision' => [2, false],
            'zero decimals reject excess precision' => [0, false],
            'missing precision fails closed' => [null, false],
        ];
    }

    #[DataProvider('instructionScales')]
    public function test_wire_amount_uses_existing_consented_currency_precision(?int $scale, bool $allowed): void
    {
        $intent = (new NiumDynamicIntent)->forceFill(['source_currency' => 'USD', 'destination_currency' => 'BHD',
            'amount_mode' => 'DESTINATION_AMOUNT', 'quote_id' => 'fixture-quote', 'confirmed_terms' => [
                'context' => ['corridor' => ['payoutMethod' => 'LOCAL'], 'external_beneficiary_id' => 'beneficiary'],
                'quote_id' => 'fixture-quote', 'finalized_usd_preparation' => [],
                'payout_contract' => ['currencyScales' => ['USD' => 2, 'BHD' => $scale]],
                'financial_terms' => ['estimated_recipient' => ['amount' => '123.456']],
                'remittance_instruction' => ['purposeCode' => 'IR005', 'sourceOfFunds' => 'Salary', 'customerComments' => 'Invoice'],
            ]]);
        if (! $allowed) {
            $this->expectExceptionMessage('dynamic_remittance_amount_unrepresentable');
        }
        $json = DynamicPayoutRequest::json($intent, 'fixture-operation');
        $this->assertStringContainsString('"destinationAmount":123.456', $json);
    }
}
