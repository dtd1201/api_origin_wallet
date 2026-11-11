<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

use InvalidArgumentException;

/** Currency and amount are explicit; currency precision comes from evidence. */
final readonly class Money
{
    public function __construct(public string $currency, public Decimal $amount)
    {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('financial_currency_invalid');
        }
    }

    public function snapshot(): array
    {
        return ['currency' => $this->currency, 'amount' => $this->amount->value];
    }
}
