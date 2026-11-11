<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Options;

use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinancialTerms;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutContract;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PricingEvidence;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceSnapshot;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceRecord;
use InvalidArgumentException;

/** Internal input only. Never serialize this object or accept it from a client. */
final readonly class TransferOptionCandidate
{
    public EvidenceSnapshot $evidence;

    public array $pricingEvidence;

    public function __construct(
        public CustomerAccountScope $scope,
        public CorridorIdentity $corridor,
        array $corridorEvidence,
        public ?BeneficiarySnapshot $beneficiary,
        public ?FinancialTerms $financialTerms,
        public ?PayoutContract $financialContract,
        PricingEvidence ...$pricingEvidence,
    ) {
        $matching = [];
        foreach ($corridorEvidence as $record) {
            if (! $record instanceof EvidenceRecord) {
                throw new InvalidArgumentException('options_evidence_invalid');
            }
            if ($corridor->equals($record->corridor)) {
                $matching[] = $record;
            }
        }
        // Scope before constructing Phase 2's registry, whose ID uniqueness is
        // snapshot-wide. Foreign records/duplicate IDs cannot influence this user.
        $this->evidence = new EvidenceSnapshot(...$matching);
        $this->pricingEvidence = array_values(array_filter($pricingEvidence,
            static fn (PricingEvidence $record): bool => $corridor->equals($record->terms->corridor)));
    }
}
