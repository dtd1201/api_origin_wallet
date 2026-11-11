<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

enum FeePayer: string
{
    case Sender = 'SENDER';
    case Recipient = 'RECIPIENT';
    case Shared = 'SHARED';
    case Unknown = 'UNKNOWN';
}
