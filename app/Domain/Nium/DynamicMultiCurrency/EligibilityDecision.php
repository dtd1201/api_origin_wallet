<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency;

/** Advisory foundation output only. Dispatch is unconditionally denied. */
final readonly class EligibilityDecision
{
    public string $preparation;

    public string $dispatch;

    /** @var list<string> */
    public array $preparationReasons;

    /** @var list<string> */
    public array $dispatchReasons;

    public function __construct(bool $hasSyntheticEvidence, ReasonCode ...$preparationReasons)
    {
        $this->preparationReasons = self::codes($preparationReasons);
        $this->preparation = $this->preparationReasons === [] ? 'ALLOWED' : 'DENIED';
        $this->dispatch = 'DENIED';
        $dispatchReasons = [...$preparationReasons, ReasonCode::DispatchDisabled];
        if ($hasSyntheticEvidence) {
            $dispatchReasons[] = ReasonCode::SyntheticEvidence;
        }
        $this->dispatchReasons = self::codes($dispatchReasons);
    }

    /** @param list<ReasonCode> $reasons
     *  @return list<string>
     */
    private static function codes(array $reasons): array
    {
        $codes = array_values(array_unique(array_map(static fn (ReasonCode $reason): string => $reason->value, $reasons)));
        sort($codes, SORT_STRING);

        return $codes;
    }
}
