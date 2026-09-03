<?php

namespace App\Data\Aml;

use InvalidArgumentException;

final readonly class AmlScreeningResult
{
    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, scalar|null>>  $matches
     */
    public function __construct(
        public string $reference,
        public string $outcome,
        public string $riskLevel,
        public array $summary = [],
        public array $matches = [],
    ) {
        if (! in_array($outcome, ['clear', 'match'], true)) {
            throw new InvalidArgumentException('Unsupported AML screening outcome.');
        }

        if (! in_array($riskLevel, ['low', 'medium', 'high', 'critical', 'unknown'], true)) {
            throw new InvalidArgumentException('Unsupported AML risk level.');
        }
    }
}
