<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Registry;

use App\Domain\Nium\DynamicMultiCurrency\EligibilityDecision;

/** Retains registry rejection diagnostics separately from Phase 1 decisions. */
final readonly class RegistryEvaluation
{
    public function __construct(
        public EvidenceLookup $lookup,
        public EligibilityDecision $decision,
    ) {}
}
