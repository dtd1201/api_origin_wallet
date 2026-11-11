<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Options;

use InvalidArgumentException;
use JsonSerializable;

/** Dynamic projection only; never replaces the existing HK transfer eligibility. */
final readonly class TransferOptionsResponse implements JsonSerializable
{
    private array $reasonCodes;

    public function __construct(private array $options, OptionsReason ...$reasons)
    {
        foreach ($options as $option) {
            if (! $option instanceof TransferOption) {
                throw new InvalidArgumentException('options_response_invalid');
            }
        }
        $this->reasonCodes = OptionsReason::publicCodes(...array_map(static fn (OptionsReason $reason): string => $reason->value, $reasons));
    }

    public function toArray(): array
    {
        return ['options' => array_values(array_map(static fn (TransferOption $option): array => $option->toArray(), $this->options)),
            'reason_codes' => $this->reasonCodes];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
