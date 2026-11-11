<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

/**
 * User-supplied documentary references only, including the user's signed-agreement
 * attestation. No account applicability, current validity/amendments, effective
 * version, fee payer or debit treatment has been independently verified.
 * There is deliberately no corridor selector, fallback or conversion to evidence.
 * Percentages are percent units, not rates to apply to an FX calculation.
 */
final class DocumentaryFeeSchedule
{
    public const STAGE = PricingStage::Contracted;

    public const SOURCE = 'user_supplied_unverified_fee_schedule';

    public const VOLATILITY_ADJUSTMENTS_POSSIBLE = true;

    public const LOCAL_REFERENCES = [
        ['funding' => 'USD', 'country' => 'HK', 'destination' => 'HKD', 'method' => 'LOCAL', 'margin_percent' => '0.13', 'fee' => '20', 'fee_currency' => 'USD'],
        ['funding' => 'USD', 'country' => 'SG', 'destination' => 'SGD', 'method' => 'LOCAL', 'margin_percent' => '0.13', 'fee' => '20', 'fee_currency' => 'USD'],
        ['funding' => 'USD', 'country' => 'GB', 'destination' => 'GBP', 'method' => 'LOCAL', 'margin_percent' => '0.13', 'fee' => '20', 'fee_currency' => 'USD'],
        ['funding' => 'USD', 'country' => 'DK', 'destination' => 'EUR', 'method' => 'LOCAL', 'margin_percent' => '0.13', 'fee' => '20', 'fee_currency' => 'USD'],
        ['funding' => 'USD', 'country' => 'DE', 'destination' => 'EUR', 'method' => 'LOCAL', 'margin_percent' => '0.13', 'fee' => '20', 'fee_currency' => 'USD'],
        ['funding' => 'USD', 'country' => 'JP', 'destination' => 'JPY', 'method' => 'LOCAL', 'margin_percent' => '0.28', 'fee' => '20', 'fee_currency' => 'USD'],
        ['funding' => 'USD', 'country' => 'KR', 'destination' => 'KRW', 'method' => 'LOCAL', 'margin_percent' => '0.44', 'fee' => '20', 'fee_currency' => 'USD'],
        ['funding' => 'USD', 'country' => 'TH', 'destination' => 'THB', 'method' => 'LOCAL', 'margin_percent' => '0.28', 'fee' => '20', 'fee_currency' => 'USD'],
        ['funding' => 'USD', 'country' => 'CH', 'destination' => 'EUR', 'method' => 'LOCAL', 'margin_percent' => '0.30', 'fee' => '20', 'fee_currency' => 'USD'],
    ];

    // The schedule calls these defaults. Here they remain non-executable references.
    // Unspecified corridor/account/funding scope is intentionally not filled in.
    public const NON_CONTRACTED_REFERENCES = [
        ['method' => 'LOCAL', 'fee' => '5', 'fee_currency' => 'USD', 'margin_percent' => '1.5'],
        ['method' => 'WIRES', 'fee' => '20', 'fee_currency' => 'USD', 'margin_percent' => '1.5'],
    ];

    /**
     * Documentary mapping of the signed summary supplied for AUTO-02C.
     * This is not PricingEvidence: that type requires complete FinancialTerms.
     * No date-based applicability, corridor fallback or fee arithmetic is performed.
     */
    public static function signedReference(): array
    {
        return [
            'stage' => self::STAGE->value,
            'source' => 'user_attested_signed_agreement_summary',
            'document_sha256' => null,
            'signature_independently_verified' => false,
            'agreement' => [
                'parties' => ['Nium Limited', 'HONG KONG MACHINING GROUP CO., LIMITED'],
                'effective_date' => '2026-08-15',
                'service' => 'Pay Out',
                'signature_method' => 'DocuSign',
                'schedule' => 'Appendix A-1, Parts 1 and 3',
            ],
            'contracted_local' => self::LOCAL_REFERENCES,
            // Preserve WIRE exactly as supplied in this signed summary. The older
            // NON_CONTRACTED_REFERENCES spelling WIRES remains a separate reference;
            // neither spelling establishes a provider SWIFT method or rail alias.
            'non_contracted' => [
                ['method' => 'LOCAL', 'fee' => '5', 'fee_currency' => 'USD', 'margin_percent' => '1.5'],
                ['method' => 'WIRE', 'fee' => '20', 'fee_currency' => 'USD', 'margin_percent' => '1.5'],
            ],
            // No destination/funding scope or FX margin is inferred for these rows.
            'swift_foreign_currency' => [
                ['method' => 'SWIFT', 'charge_type' => 'SHA', 'fee' => '10', 'fee_currency' => 'USD'],
                ['method' => 'SWIFT', 'charge_type' => 'OUR', 'fee' => '20', 'fee_currency' => 'USD'],
            ],
            'volatility_adjustments_possible' => self::VOLATILITY_ADJUSTMENTS_POSSIBLE,
            'current_contract_validity' => 'UNKNOWN',
            'current_amendments' => 'UNKNOWN',
            'account_applicability' => 'UNKNOWN',
            'fee_collection' => 'UNKNOWN',
            'fee_payer' => 'UNKNOWN',
            'fee_allocation' => 'UNKNOWN',
            'effective_pricing_version' => null,
            'fee_plan_id' => null,
            'current_fx_margin_percent' => null,
            'actual_transaction_fee' => null,
            'exact_wallet_debit' => null,
        ];
    }

    private function __construct() {}
}
