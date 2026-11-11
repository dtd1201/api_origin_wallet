<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Registry;

use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceKind;
use DateTimeImmutable;

/**
 * In-memory registry: construction captures a supplied snapshot; lookup never
 * imports, mutates, discovers scope, refreshes evidence or reads a wall clock.
 * The caller must supply the entire applicable set, including override clearance
 * and all holds. This registry cannot authenticate provenance or detect omissions.
 */
final readonly class EvidenceSnapshot
{
    /** @var list<EvidenceRecord> */
    private array $records;

    /** @var list<string> Identifier uniqueness applies to the entire snapshot. */
    private array $duplicateIds;

    public function __construct(EvidenceRecord ...$records)
    {
        $seen = [];
        $duplicates = [];
        foreach ($records as $record) {
            $key = 'id:'.$record->id;
            if (isset($seen[$key])) {
                $duplicates[] = $record->id;
            }
            $seen[$key] = true;
        }
        sort($duplicates, SORT_STRING);
        $this->duplicateIds = array_values(array_unique($duplicates, SORT_STRING));
        $this->records = array_values($records);
    }

    public function lookup(CorridorIdentity $corridor, DateTimeImmutable $asOf): EvidenceLookup
    {
        $matching = [];
        $byKind = [];
        $reasons = $this->duplicateIds === [] ? [] : [RegistryReason::DuplicateIdentifier];

        foreach ($this->records as $record) {
            // Foreign scopes never fill categories or contribute assertions.
            if (! $record->corridor->equals($corridor)) {
                continue;
            }
            $matching[] = $record;
            $byKind[$record->kind->value][] = $record;

            if ($record->expiresAt <= $record->validFrom || $record->expiresAt <= $record->issuedAt
                || $record->expiresAt <= $record->collectedAt || $record->issuedAt > $record->collectedAt) {
                $reasons[] = RegistryReason::InvalidWindow;
            }
            if ($record->issuedAt > $asOf || $record->collectedAt > $asOf || $record->validFrom > $asOf) {
                $reasons[] = RegistryReason::FutureDated;
            }
            if ($record->expiresAt <= $asOf) {
                $reasons[] = RegistryReason::Expired;
            }
            if ($record->revocation === RevocationStatus::Revoked) {
                $reasons[] = RegistryReason::Revoked;
            } elseif ($record->revocation === RevocationStatus::Unknown) {
                $reasons[] = RegistryReason::UnknownRevocation;
            }

            // Provider support/imported data can never establish a local decision.
            if (in_array($record->kind, [EvidenceKind::LocalActivation, EvidenceKind::OverrideClearance], true)
                && $record->source === EvidenceSource::ProviderResponse) {
                $reasons[] = RegistryReason::LocalAttestationRequired;
            }
        }

        if ($matching === []) {
            $reasons[] = RegistryReason::ScopeNotFound;
        }
        $missing = [];
        $conflicting = [];
        foreach (EvidenceKind::required() as $kind) {
            $count = count($byKind[$kind->value] ?? []);
            if ($count === 0) {
                $missing[] = $kind->value;
            } elseif ($count > 1) {
                // Even equal assertions with different IDs are ambiguous. No winner.
                $conflicting[] = $kind->value;
            }
        }
        // Multiple blocking overrides coexist; Phase 1 evaluates each restriction.
        if ($missing !== []) {
            $reasons[] = RegistryReason::IncompleteEvidence;
        }
        if ($conflicting !== []) {
            $reasons[] = RegistryReason::ConflictingAssertions;
        }

        return new EvidenceLookup($matching, $reasons, $missing, $conflicting, $this->duplicateIds);
    }
}
