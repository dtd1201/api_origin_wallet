<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * An assertion supplied by the caller, not an importer or proof authenticator.
 * Each record binds every corridor dimension; there are no wildcard scopes.
 * Expiry is explicit: Phase 1 invents no Nium freshness or validity contract.
 */
final readonly class CorridorEvidence
{
    public function __construct(
        public CorridorIdentity $corridor,
        public EvidenceKind $kind,
        public EvidenceState $state,
        public string $reference,
        public DateTimeImmutable $observedAt,
        public DateTimeImmutable $expiresAt,
        public bool $synthetic,
    ) {
        if (trim($reference) === '' || trim($reference) !== $reference
            || preg_match('/[\x00-\x1F\x7F]/', $reference)) {
            throw new InvalidArgumentException('Evidence requires an explicit reference.');
        }
    }
}
