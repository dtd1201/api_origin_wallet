<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Registry;

use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceKind;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Complete immutable assertion. Scope, environment and contract/profile versions
 * are carried by the mandatory Phase 1 identity, without wildcard/default fields.
 * provenance is an opaque source-document reference, never fetched or imported.
 * collectedAt is the collection time of this issued assertion, not underlying data.
 */
final readonly class EvidenceRecord
{
    public function __construct(
        public string $id,
        public CorridorIdentity $corridor,
        public EvidenceKind $kind,
        public EvidenceState $state,
        public EvidenceSource $source,
        public string $provenance,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $collectedAt,
        public DateTimeImmutable $validFrom,
        public DateTimeImmutable $expiresAt,
        public RevocationStatus $revocation,
    ) {
        foreach ([$id, $provenance] as $value) {
            if ($value === '' || trim($value) !== $value || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new InvalidArgumentException('registry_metadata_invalid');
            }
        }
    }
}
