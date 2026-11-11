<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

/** Declared normalized relationships, never inferred Nium fee treatment. */
enum FeeAllocation: string
{
    case SenderAdditional = 'SENDER_ADDITIONAL';
    case RecipientDeducted = 'RECIPIENT_DEDUCTED';
    case Shared = 'SHARED';
    case Unknown = 'UNKNOWN';
}
