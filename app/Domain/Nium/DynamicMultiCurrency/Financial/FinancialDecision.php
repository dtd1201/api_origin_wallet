<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

/** Financial consistency is advisory; it never emits corridor readiness/authorization. */
final readonly class FinancialDecision
{
    public string $status;

    public string $preparation;

    public string $dispatch;

    public array $reasons;

    public array $dispatchReasons;

    public function __construct(bool $synthetic, FinancialReason ...$reasons)
    {
        $codes = array_values(array_unique(array_map(static fn (FinancialReason $reason): string => $reason->value, $reasons)));
        sort($codes, SORT_STRING);
        $denied = array_filter($reasons, static fn (FinancialReason $reason): bool => ! $reason->isUnknown()) !== [];
        $this->status = $codes === [] ? 'VERIFIED' : ($denied ? 'DENIED' : 'UNKNOWN');
        $this->preparation = $codes === [] ? 'ALLOWED' : 'DENIED';
        $this->dispatch = 'DENIED';
        $this->reasons = $codes;
        $dispatch = [...$codes, 'phase_three_dispatch_disabled'];
        if ($synthetic) {
            $dispatch[] = 'synthetic_evidence';
        }
        sort($dispatch, SORT_STRING);
        $this->dispatchReasons = $dispatch;
    }
}
