<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Nium\DynamicMultiCurrency\Financial\DocumentaryFeeSchedule;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PricingEvidence;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PricingStage;
use PHPUnit\Framework\TestCase;

final class NiumSignedPayoutFeeScheduleTest extends TestCase
{
    public function test_signed_summary_retains_contract_provenance_without_claiming_document_verification(): void
    {
        $reference = DocumentaryFeeSchedule::signedReference();

        $this->assertSame(PricingStage::Contracted->value, $reference['stage']);
        $this->assertSame('user_attested_signed_agreement_summary', $reference['source']);
        $this->assertSame([
            'parties' => ['Nium Limited', 'HONG KONG MACHINING GROUP CO., LIMITED'],
            'effective_date' => '2026-08-15', 'service' => 'Pay Out',
            'signature_method' => 'DocuSign', 'schedule' => 'Appendix A-1, Parts 1 and 3',
        ], $reference['agreement']);
        $this->assertNull($reference['document_sha256']);
        $this->assertFalse($reference['signature_independently_verified']);
        $this->assertSame('NIUM', $reference['local_fee_classification']['fee_owner']);
        $this->assertSame('NIUM', $reference['local_fee_classification']['fx_margin_owner']);
        $this->assertSame('TRANSACTION_FEE', $reference['local_fee_classification']['fee_type']);
        $this->assertSame('UNVERIFIED', $reference['local_fee_classification']['fee_collection']);
        $this->assertSame('NOT_RECOGNIZED', $reference['local_fee_classification']['origin_revenue']);
        $this->assertNotInstanceOf(PricingEvidence::class, $reference);
    }

    public function test_each_contracted_local_corridor_retains_exact_percent_units_and_usd_fee(): void
    {
        $rows = DocumentaryFeeSchedule::signedReference()['contracted_local'];
        $expected = [
            ['HK', 'HKD', '0.13'], ['SG', 'SGD', '0.13'], ['GB', 'GBP', '0.13'],
            ['DK', 'EUR', '0.13'], ['DE', 'EUR', '0.13'], ['JP', 'JPY', '0.28'],
            ['KR', 'KRW', '0.44'], ['TH', 'THB', '0.28'], ['CH', 'EUR', '0.30'],
        ];
        $this->assertCount(count($expected), $rows);
        foreach ($expected as $index => [$country, $currency, $margin]) {
            $this->assertSame([
                'funding' => 'USD', 'country' => $country, 'destination' => $currency,
                'method' => 'LOCAL', 'margin_percent' => $margin, 'fee' => '20', 'fee_currency' => 'USD',
            ], $rows[$index]);
        }
    }

    public function test_non_contracted_and_swift_charge_types_remain_separate_documentary_references(): void
    {
        $reference = DocumentaryFeeSchedule::signedReference();

        $this->assertSame([
            ['method' => 'LOCAL', 'fee' => '5', 'fee_currency' => 'USD', 'margin_percent' => '1.5'],
            ['method' => 'WIRE', 'fee' => '20', 'fee_currency' => 'USD', 'margin_percent' => '1.5'],
        ], $reference['non_contracted']);
        $this->assertSame([
            ['method' => 'SWIFT', 'charge_type' => 'SHA', 'fee' => '10', 'fee_currency' => 'USD'],
            ['method' => 'SWIFT', 'charge_type' => 'OUR', 'fee' => '20', 'fee_currency' => 'USD'],
        ], $reference['swift_foreign_currency']);
        // The new signed spelling must not normalize the earlier reference or
        // silently introduce WIRE/WIRES/SWIFT aliases in existing financial code.
        $this->assertSame(['LOCAL', 'WIRES'], array_column(DocumentaryFeeSchedule::NON_CONTRACTED_REFERENCES, 'method'));
    }

    public function test_signed_amounts_do_not_supply_current_amendments_actual_fee_or_total_debit(): void
    {
        $reference = DocumentaryFeeSchedule::signedReference();

        $this->assertTrue($reference['volatility_adjustments_possible']);
        foreach (['current_contract_validity', 'current_amendments', 'account_applicability',
            'fee_collection', 'fee_payer', 'fee_allocation'] as $field) {
            $this->assertSame('UNKNOWN', $reference[$field]);
        }
        foreach (['effective_pricing_version', 'fee_plan_id', 'current_fx_margin_percent',
            'actual_transaction_fee', 'exact_wallet_debit'] as $field) {
            $this->assertNull($reference[$field]);
        }

        // Callers receive independent values; a local alteration cannot install a
        // new margin, pricing version or debit in the documentary source.
        $original = $reference;
        $reference['contracted_local'][0]['margin_percent'] = '0.99';
        $reference['actual_transaction_fee'] = '0';
        $reference['exact_wallet_debit'] = '120';
        $this->assertSame($original, DocumentaryFeeSchedule::signedReference());
    }
}
