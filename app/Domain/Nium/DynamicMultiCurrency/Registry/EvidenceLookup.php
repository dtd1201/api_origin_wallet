<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Registry;

/**
 * accepted concerns snapshot integrity/completeness only, never eligibility.
 * A valid DENIED/HOLD/UNKNOWN assertion remains evidence for Phase 1 to evaluate.
 * Rejected lookups expose no usable records, preventing partial-set approval.
 */
final readonly class EvidenceLookup
{
    public bool $accepted;

    /** @var list<EvidenceRecord> */
    public array $records;

    /** @var list<string> */
    public array $reasons;

    /** @var list<string> */
    public array $missingKinds;

    /** @var list<string> */
    public array $conflictingKinds;

    /** @var list<string> */
    public array $duplicateIds;

    /** @var list<string> All matching records quarantined if any check fails. */
    public array $rejectedIds;

    /**
     * @param list<EvidenceRecord> $records
     * @param list<RegistryReason> $reasons
     * @param list<string> $missingKinds
     * @param list<string> $conflictingKinds
     * @param list<string> $duplicateIds
     */
    public function __construct(array $records, array $reasons, array $missingKinds, array $conflictingKinds, array $duplicateIds)
    {
        usort($records, static fn (EvidenceRecord $a, EvidenceRecord $b): int => strcmp($a->id, $b->id));
        $this->reasons = self::sorted(array_map(static fn (RegistryReason $reason): string => $reason->value, $reasons));
        $this->accepted = $this->reasons === [];
        $this->records = $this->accepted ? $records : [];
        $this->rejectedIds = $this->accepted ? [] : self::sorted(array_map(static fn (EvidenceRecord $record): string => $record->id, $records));
        $this->missingKinds = self::sorted($missingKinds);
        $this->conflictingKinds = self::sorted($conflictingKinds);
        $this->duplicateIds = self::sorted($duplicateIds);
    }

    /** @param list<string> $values
     *  @return list<string>
     */
    private static function sorted(array $values): array
    {
        $values = array_values(array_unique($values, SORT_STRING));
        sort($values, SORT_STRING);

        return $values;
    }
}
