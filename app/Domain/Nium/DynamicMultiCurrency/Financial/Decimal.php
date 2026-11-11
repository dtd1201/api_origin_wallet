<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Nonnegative exact decimal. No float, exponent, implicit rounding or money scale. */
final readonly class Decimal
{
    public string $value;

    public function __construct(string $value)
    {
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw new InvalidArgumentException('financial_decimal_invalid');
        }
        $this->value = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isZero(): bool
    {
        return $this->value === '0';
    }

    public function fitsScale(int $scale): bool
    {
        return $scale >= 0 && strlen(explode('.', $this->value)[1] ?? '') <= $scale;
    }

    /** Only used to check explicitly declared fee/debit equations. */
    public function plus(self $other): self
    {
        return new self((string) BigDecimal::of($this->value)->plus($other->value));
    }
}
