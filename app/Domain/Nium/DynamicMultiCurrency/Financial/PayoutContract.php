<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceSource;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RevocationStatus;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Supplied applicability/calculation evidence, not a Nium contract implementation.
 * EXACT only checks already representable values; other rounding rules stay unknown.
 * No FX equation is implemented. FX needs a referenced, verified calculation
 * contract plus bound provider output. These fields do not authenticate evidence.
 * A VERIFIED calculationReference attests the normalized customer principal/fee
 * and complete CUSTOMER debit relationship. Provider fees and settlement debits
 * are independent observations reconciled after initiation; equality is not admission evidence.
 */
final readonly class PayoutContract
{
    public function __construct(
        public CorridorIdentity $corridor,
        public string $reference,
        public EvidenceState $state,
        public EvidenceSource $source,
        public RevocationStatus $revocation,
        public string $pricingVersion,
        public string $feePlanId,
        public array $supportedMethods,
        public array $currencyScales,
        public ?int $rateScale,
        public ?string $roundingRule,
        public ?string $calculationReference,
        public ?string $fxCalculationReference,
        public string $feeCurrency,
        public FeePayer $feePayer,
        public FeeAllocation $feeAllocation,
        public DateTimeImmutable $validFrom,
        public DateTimeImmutable $expiresAt,
    ) {
        foreach ([$reference, $pricingVersion, $feePlanId] as $value) {
            if (trim($value) === '' || trim($value) !== $value || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new InvalidArgumentException('financial_contract_metadata_invalid');
            }
        }
        if (preg_match('/^[A-Z]{3}$/D', $feeCurrency) !== 1 || ($rateScale !== null && $rateScale < 0)) {
            throw new InvalidArgumentException('financial_contract_precision_invalid');
        }
        foreach ($currencyScales as $currency => $scale) {
            if (! is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || ! is_int($scale) || $scale < 0) {
                throw new InvalidArgumentException('financial_contract_precision_invalid');
            }
        }
        foreach ($supportedMethods as $method) {
            if (! is_string($method) || preg_match('/^[A-Z][A-Z0-9_]*$/D', $method) !== 1) {
                throw new InvalidArgumentException('financial_contract_method_invalid');
            }
        }
    }
}
