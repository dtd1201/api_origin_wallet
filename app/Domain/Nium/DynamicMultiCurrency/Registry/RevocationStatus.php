<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Registry;

/**
 * Status explicitly supplied for this snapshot; no live revocation discovery.
 * Revocation blocks every evaluation of this snapshot, including historical times.
 */
enum RevocationStatus: string
{
    case NotRevoked = 'NOT_REVOKED';
    case Revoked = 'REVOKED';
    case Unknown = 'UNKNOWN';
}
