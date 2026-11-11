<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use InvalidArgumentException;

/**
 * Immutable candidate/accepted snapshot, never a transfer or a price calculator.
 * Null means unknown or explicitly inapplicable, never an implicit zero.
 * principal is a declared funding-currency remittance principal, not total debit.
 * Its meaning must be covered by the supplied calculation contract.
 * conversionAmount preserves the provider's quoted FX output, not an amount
 * derived from principal, margin or rate. estimatedRecipient is never guaranteed credit.
 */
final readonly class FinancialTerms
{
    public function __construct(
        public CorridorIdentity $corridor,
        public Money $principal,
        public FxRequirement $fxRequirement,
        public ?Decimal $fxRate,
        public ?string $quoteId,
        public ?string $quoteProvenance,
        public ?Decimal $fxMarginPercent,
        public ?Money $conversionAmount,
        public ?Money $transactionFee,
        public FeePayer $feePayer,
        public FeeAllocation $feeAllocation,
        public ?Money $totalWalletDebit,
        public ?Money $estimatedRecipient,
        public string $pricingSource,
        public string $pricingVersion,
        public string $feePlanId,
    ) {
        foreach ([$pricingSource, $pricingVersion, $feePlanId] as $value) {
            if (trim($value) === '' || trim($value) !== $value || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new InvalidArgumentException('financial_terms_metadata_invalid');
            }
        }
    }

    /** Exact binding, including scope, versions, provenance and every money term. */
    public function snapshot(): array
    {
        return [
            'corridor' => get_object_vars($this->corridor), 'principal' => $this->principal->snapshot(),
            'fx_requirement' => $this->fxRequirement->value, 'fx_rate' => $this->fxRate?->value,
            'quote_id' => $this->quoteId, 'quote_provenance' => $this->quoteProvenance,
            'fx_margin_percent' => $this->fxMarginPercent?->value,
            'conversion_amount' => $this->conversionAmount?->snapshot(),
            'transaction_fee' => $this->transactionFee?->snapshot(), 'fee_payer' => $this->feePayer->value,
            'fee_allocation' => $this->feeAllocation->value, 'wallet_debit' => $this->totalWalletDebit?->snapshot(),
            'estimated_recipient' => $this->estimatedRecipient?->snapshot(),
            'pricing_source' => $this->pricingSource, 'pricing_version' => $this->pricingVersion, 'fee_plan' => $this->feePlanId,
        ];
    }
}
