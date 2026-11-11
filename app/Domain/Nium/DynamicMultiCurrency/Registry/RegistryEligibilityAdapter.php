<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Registry;

use App\Domain\Nium\DynamicMultiCurrency\CorridorEvidence;
use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use App\Domain\Nium\DynamicMultiCurrency\EligibilityResolver;
use DateTimeImmutable;

/** Isolated, explicit adapter. No container binding or runtime caller is added. */
final class RegistryEligibilityAdapter
{
    public function evaluate(EvidenceSnapshot $snapshot, CorridorIdentity $corridor, DateTimeImmutable $asOf): RegistryEvaluation
    {
        $lookup = $snapshot->lookup($corridor, $asOf);
        $evidence = [];
        // A failed lookup releases no records, including otherwise valid positives.
        foreach (($lookup->accepted ? $lookup->records : []) as $record) {
            $observedAt = $record->collectedAt;
            if ($record->issuedAt > $observedAt) {
                $observedAt = $record->issuedAt;
            }
            if ($record->validFrom > $observedAt) {
                $observedAt = $record->validFrom;
            }
            $evidence[] = new CorridorEvidence($record->corridor, $record->kind, $record->state,
                $record->id, $observedAt, $record->expiresAt, $record->source === EvidenceSource::Synthetic);
        }

        $decision = (new EligibilityResolver)->resolve($corridor, $asOf, ...$evidence);

        return new RegistryEvaluation($lookup, $decision);
    }
}
