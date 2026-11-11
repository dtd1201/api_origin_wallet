<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency;

/** VERIFIED is a supplied assertion, never inferred from provider-wide support. */
enum EvidenceState: string
{
    case Unknown = 'UNKNOWN';
    case Verified = 'VERIFIED';
    case Denied = 'DENIED';
    case Hold = 'HOLD';
}
