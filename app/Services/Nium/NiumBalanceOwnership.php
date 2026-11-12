<?php

declare(strict_types=1);

namespace App\Services\Nium;

use App\Models\Balance;
use Brick\Math\BigDecimal;

/** Call with the Balance row locked, before applying a provider observation. */
final class NiumBalanceOwnership
{
    public static function preservesBooks(Balance $balance): bool
    {
        // Holds belong to Origin. Dynamic ledger ownership survives consumption
        // and cancellation: a later snapshot is not a journal credit or debit.
        return ! BigDecimal::of((string) $balance->reserved_balance)->isZero()
            || self::hasDynamicBooks($balance);
    }

    public static function hasDynamicBooks(Balance $balance): bool
    {
        return $balance->ledgerEntries()->where('source_type', 'nium_dynamic_reservation')->exists();
    }
}
