<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use InvalidArgumentException;
use RuntimeException;

/** Exact major-unit wallet amount conversion. This grants no provider authenticity. */
final class DynamicProviderBalance
{
    public static function normalize(mixed $amount, string $reason = 'dynamic_authoritative_wallet_balance_unavailable'): string
    {
        // JSON/Eloquent may retain a whole number as an int. Never cast a float,
        // boolean or arbitrary numeric value: precision/provenance is already lost.
        if (is_int($amount) && $amount >= 0) {
            $amount = (string) $amount;
        }
        if (! is_string($amount)) {
            throw new RuntimeException($reason);
        }
        try {
            // Existing Wallet contract: major units, DECIMAL(20,8), no rounding.
            return DynamicReservationLedger::storage($amount);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw new RuntimeException($reason, 0, $exception);
        }
    }
}
