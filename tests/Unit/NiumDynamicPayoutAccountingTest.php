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
    private function evaluate(array $changes = [], string $mode = 'SOURCE_AMOUNT', array $intentChanges = [], array $outboxChanges = []): array
    {
        $intent = (new NiumDynamicIntent)->forceFill(array_replace_recursive(['source_wallet' => 'wallet', 'customer' => 'customer',
            'source_currency' => 'USD',
            'destination_currency' => 'GBP', 'amount_mode' => $mode, 'total_debit' => '1000', 'confirmed_terms' => [
                'context' => ['corridor' => ['client' => 'client'], 'external_beneficiary_id' => 'beneficiary'],
                'financial_terms' => ['fx_rate' => '0.8', 'principal' => ['amount' => '980', 'currency' => 'USD'],
                    'transaction_fee' => ['amount' => '20', 'currency' => 'USD'], 'wallet_debit' => ['amount' => '1000', 'currency' => 'USD'],
                    'estimated_recipient' => ['amount' => '784', 'currency' => 'GBP']],
            ]], $intentChanges));
        $outbox = (new NiumDynamicPayoutOutbox)->forceFill(array_replace(
            ['provider_reference' => 'RT-1', 'operation_key' => 'operation'], $outboxChanges));
        $scope = ['clientHashId' => 'client', 'customerHashId' => 'customer', 'walletHashId' => 'wallet',
            'authCurrencyCode' => 'USD', 'debit' => true, 'status' => 'Approved', 'settlementStatus' => 'Settled'];
        $rows = [
            [...$scope, 'authCode' => 'RT-1', 'transactionType' => 'Remittance_Debit',
                'externalId' => null, 'originalAuthorizationCode' => null,
                'settlementAuthAmount' => '980', 'effectiveAuthAmount' => '1000', 'settlementTransactionAmount' => '784',
                'transactionCurrencyCode' => 'GBP', 'labels' => ['beneficiaryId' => 'beneficiary']],
            [...$scope, 'authCode' => 'FT-1', 'transactionType' => 'Fee_Debit', 'settlementAuthAmount' => '20',
                'originalAuthorizationCode' => null,
                'labels' => ['originalAuthorizationCode' => 'RT-1', 'feeName' => 'REMIT_BANK_FEE']],
        ];
        foreach ($changes as $index => $fields) {
            if ($fields === null) {
                unset($rows[$index]);
            } else {
                $rows[$index] = array_replace($rows[$index] ?? [], $fields);
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

    public function test_provider_reference_and_scoped_identities_accept_null_external_id(): void
    {
        $result = $this->evaluate();
        $this->assertSame('matched', $result['state']);
        $this->assertSame('RT-1', $result['provider_reference']);
        $this->assertSame('SETTLED_FEE_RECORDS', $result['receipt']['fee_evidence']);
        $this->assertArrayNotHasKey('provider_status', $result);
    }

    public function test_operation_key_without_provider_reference_cannot_correlate_a_payment(): void
    {
        $result = $this->evaluate([0 => ['externalId' => 'operation']], outboxChanges: ['provider_reference' => null]);
        $this->assertSame('pending', $result['state']);
        $this->assertSame('dynamic_remittance_reference_missing', $result['reason']);
        $this->assertArrayNotHasKey('provider_reference', $result);
        $this->assertArrayNotHasKey('receipt', $result);
    }

    public function test_matching_amounts_and_operation_key_do_not_override_a_different_reference(): void
    {
        $result = $this->evaluate([0 => ['externalId' => 'operation']], outboxChanges: ['provider_reference' => 'other-reference']);
        $this->assertSame('pending', $result['state']);
        $this->assertSame('dynamic_remittance_record_missing', $result['reason']);
    }

    public function test_missing_fee_stays_unknown_even_if_customer_fee_is_zero_and_status_is_paid(): void
    {
        $result = $this->evaluate([0 => ['status' => 'PAID'], 1 => null], intentChanges: [
            'total_debit' => '980', 'confirmed_terms' => ['financial_terms' => [
                'transaction_fee' => ['amount' => '0'], 'wallet_debit' => ['amount' => '980'],
            ]],
        ]);
        $this->assertSame('pending', $result['state']);
        $this->assertSame('dynamic_fee_records_missing', $result['reason']);
        $this->assertArrayNotHasKey('provider_status', $result);
        $this->assertArrayNotHasKey('receipt', $result);
    }

    public function test_explicit_linked_settled_zero_fee_record_is_distinct_from_an_absent_record(): void
    {
        // Synthetic existing Fee_Debit contract: this does NOT establish a
        // provider no-charge rule or justify omitting a fee record in Production.
        $result = $this->evaluate([0 => ['status' => 'PAID'], 1 => ['settlementAuthAmount' => '0']], intentChanges: [
            'total_debit' => '980', 'confirmed_terms' => ['financial_terms' => [
                'transaction_fee' => ['amount' => '0'], 'wallet_debit' => ['amount' => '980'],
            ]],
        ]);
        $this->assertSame('matched', $result['state']);
        $this->assertSame('0', $result['receipt']['fee_usd']);
        $this->assertCount(1, $result['receipt']['provider_fee_records']);
        $this->assertSame('PAID', $result['provider_status']);
    }

    public function test_paid_status_is_not_exported_when_financial_accounting_mismatches(): void
    {
        $result = $this->evaluate([0 => ['status' => 'PAID'], 1 => ['settlementAuthAmount' => '21']]);
        $this->assertSame('mismatched', $result['state']);
        $this->assertArrayNotHasKey('provider_status', $result);
    }

    public function test_unsettled_remittance_is_pending_before_missing_fee_classification(): void
    {
        $result = $this->evaluate([0 => ['settlementStatus' => 'Unsettled', 'status' => 'PAID'], 1 => null]);
        $this->assertSame('pending', $result['state']);
        $this->assertSame('dynamic_provider_records_pending', $result['reason']);
        $this->assertArrayNotHasKey('provider_status', $result);
    }

    public function test_same_provider_rows_in_later_accounting_snapshots_are_not_duplicates(): void
    {
        $this->assertSame('matched', $this->evaluate()['state']);
        $this->assertSame('matched', $this->evaluate()['state']);
    }

    public function test_original_json_numbers_are_reconciled_as_exact_decimal_strings(): void
    {
        $decoded = ExactProviderJson::decode('{"content":[{"settlementAuthAmount":980.12345678,'
            .'"settlementTransactionAmount":784.00000001},{"settlementAuthAmount":20.00000001}]}');
        $this->assertSame('980.12345678', $decoded['content'][0]['settlementAuthAmount']);
        $this->assertSame('784.00000001', $decoded['content'][0]['settlementTransactionAmount']);
        $result = $this->evaluate($decoded['content'], mode: 'DESTINATION_AMOUNT', intentChanges: [
            'total_debit' => '1000.12345679', 'confirmed_terms' => ['financial_terms' => [
                'principal' => ['amount' => '980.12345678'], 'transaction_fee' => ['amount' => '20.00000001'],
                'wallet_debit' => ['amount' => '1000.12345679'], 'estimated_recipient' => ['amount' => '784.00000001'],
            ]],
        ]);
        $this->assertSame('matched', $result['state']);
        $this->assertSame('1000.12345679', $result['receipt']['wallet_debit_usd']);
        $this->assertSame('784.00000001', $result['receipt']['destination_amount']);
    }

    public static function invalidCustomerTerms(): array
    {
        return [
            'source currency' => [['source_currency' => 'EUR']],
            'principal currency' => [['confirmed_terms' => ['financial_terms' => ['principal' => ['currency' => 'EUR']]]]],
            'fee currency' => [['confirmed_terms' => ['financial_terms' => ['transaction_fee' => ['currency' => 'EUR']]]]],
            'wallet debit currency' => [['confirmed_terms' => ['financial_terms' => ['wallet_debit' => ['currency' => 'EUR']]]]],
            'destination terms currency' => [['confirmed_terms' => ['financial_terms' => ['estimated_recipient' => ['currency' => 'EUR']]]]],
            'terms total differs from intent' => [['confirmed_terms' => ['financial_terms' => ['wallet_debit' => ['amount' => '1001']]]]],
            'principal plus fee differs from debit' => [['confirmed_terms' => ['financial_terms' => ['principal' => ['amount' => '979']]]]],
            'unsupported amount mode' => [['amount_mode' => 'UNKNOWN']],
        ];
    }

    #[DataProvider('invalidCustomerTerms')]
    public function test_inconsistent_customer_terms_cannot_be_matched_or_debited(array $changes): void
    {
        $result = $this->evaluate(intentChanges: $changes);
        $this->assertSame('unavailable', $result['state']);
        $this->assertSame('dynamic_customer_accounting_terms_unverified', $result['reason']);
        $this->assertArrayNotHasKey('receipt', $result);
        $this->assertArrayNotHasKey('provider_status', $result);
    }

    public function test_equal_total_does_not_hide_different_principal_and_fee_components(): void
    {
        $result = $this->evaluate([0 => ['settlementAuthAmount' => '979'], 1 => ['settlementAuthAmount' => '21']]);
        $this->assertSame('mismatched', $result['state']);
        $this->assertSame('1000', $result['receipt']['wallet_debit_usd']);
        $this->assertSame('979', $result['receipt']['principal_usd']);
        $this->assertSame('21', $result['receipt']['fee_usd']);
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

    public function test_destination_mode_requires_exact_intended_credit_without_repricing_or_claiming_a_guarantee(): void
    {
        $exact = $this->evaluate(mode: 'DESTINATION_AMOUNT');
        $this->assertSame('matched', $exact['state']);
        $this->assertSame('784', $exact['receipt']['intended_destination_amount']);
        $different = $this->evaluate([0 => ['settlementTransactionAmount' => '783']], 'DESTINATION_AMOUNT');
        $this->assertSame('mismatched', $different['state']);
        $this->assertSame('783', $different['receipt']['destination_amount']);
        $this->assertSame('784', $different['receipt']['intended_destination_amount']);
        $this->assertSame('1000', $different['receipt']['customer_authorized_debit_usd']);
        $this->assertTrue($different['receipt']['discrepancy']);
        $source = $this->evaluate([0 => ['settlementTransactionAmount' => '783']]);
        $this->assertSame('matched', $source['state']);
        $this->assertNull($source['receipt']['intended_destination_amount']);
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
            'wrong customer' => [[0 => ['customerHashId' => 'other']], 'unavailable'],
            'wrong client' => [[0 => ['clientHashId' => 'other']], 'unavailable'],
            'wrong beneficiary' => [[0 => ['labels' => ['beneficiaryId' => 'other']]], 'unavailable'],
            'missing beneficiary proof' => [[0 => ['labels' => []]], 'unavailable'],
            'conflicting supplied externalId' => [[0 => ['externalId' => 'other-operation']], 'unavailable'],
            'credit is not a remittance even with same reference' => [[0 => ['transactionType' => 'Wallet_Credit_Mode_Offline']], 'pending'],
            'legacy documented remittance type remains supported' => [[0 => ['transactionType' => 'Remittance_Debit_External']], 'matched'],
            'duplicate authorization within snapshot' => [[1 => ['authCode' => 'RT-1']], 'unavailable'],
            'unknown remittance status' => [[0 => ['status' => 'UNKNOWN']], 'unavailable'],
            'missing remittance status' => [[0 => ['status' => null]], 'unavailable'],
            'unknown fee status' => [[1 => ['status' => 'UNKNOWN']], 'unavailable'],
            'unknown settlement status' => [[0 => ['settlementStatus' => 'UNKNOWN']], 'unavailable'],
            'missing fee settlement status' => [[1 => ['settlementStatus' => null]], 'unavailable'],
            'false debit' => [[0 => ['debit' => false]], 'unavailable'],
            'string debit is not boolean proof' => [[0 => ['debit' => 'true']], 'unavailable'],
            'wrong principal currency' => [[0 => ['authCurrencyCode' => 'EUR']], 'unavailable'],
            'wrong destination currency' => [[0 => ['transactionCurrencyCode' => 'EUR']], 'unavailable'],
            'zero destination is not settlement proof' => [[0 => ['settlementTransactionAmount' => '0']], 'unavailable'],
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
            'correlated remittance reversal type' => [[0 => ['transactionType' => 'Remittance_Reversal']], 'exception'],
            'unlinked reversal is not ignored' => [[1 => ['transactionType' => 'Fee_Reversal', 'labels' => []]], 'unavailable'],
            'unsafe numeric exponent' => [[1 => ['settlementAuthAmount' => '2e1']], 'unavailable'],
            'float is not exact evidence' => [[1 => ['settlementAuthAmount' => 20.0]], 'unavailable'],
            'negative provider fee' => [[1 => ['settlementAuthAmount' => '-1']], 'unavailable'],
            'negative destination' => [[0 => ['settlementTransactionAmount' => '-1']], 'unavailable'],
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
