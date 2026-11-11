<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

enum PayoutAmountMode: string
{
    case Source = 'SOURCE_AMOUNT';
    case Destination = 'DESTINATION_AMOUNT';

    public function quoteField(): string
    {
        return $this === self::Source ? 'sourceAmount' : 'destinationAmount';
    }
}
