<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use App\Domain\Nium\DynamicMultiCurrency\Registry\EvidenceSource;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RevocationStatus;
use DateTimeImmutable;
use InvalidArgumentException;

/** A stage-specific frozen assertion; no imports, stage promotion or implicit consent. */
final readonly class PricingEvidence
{
    public function __construct(
        public string $id,
        public PricingStage $stage,
        public EvidenceState $state,
        public EvidenceSource $source,
        public string $provenance,
        public FinancialTerms $terms,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $validFrom,
        public DateTimeImmutable $expiresAt,
        public RevocationStatus $revocation,
    ) {
        foreach ([$id, $provenance] as $value) {
            if (trim($value) === '' || trim($value) !== $value || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new InvalidArgumentException('financial_evidence_metadata_invalid');
            }
        }
    }
}
