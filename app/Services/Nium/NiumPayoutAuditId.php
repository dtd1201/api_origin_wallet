<?php

namespace App\Services\Nium;

use App\Models\FxQuote;
use RuntimeException;

final class NiumPayoutAuditId
{
    public static function isValid(mixed $value): bool
    {
        if (! is_int($value) && ! is_string($value)) {
            return false;
        }

        $value = (string) $value;
        $maximum = (string) PHP_INT_MAX;

        return preg_match('/^[1-9][0-9]*$/D', $value) === 1
            && (strlen($value) < strlen($maximum)
                || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0));
    }

    public static function fromQuote(FxQuote $quote): int
    {
        if (($quote->raw_data['provider_fx_type'] ?? null) !== 'payout_fx_lock'
            || ! self::isValid($quote->quote_ref)
            || ! self::isValid($quote->raw_data['audit_id'] ?? null)
            || (string) $quote->quote_ref !== (string) $quote->raw_data['audit_id']) {
            throw new RuntimeException('Nium payout requires a matching authoritative integer FX audit ID.');
        }

        return (int) $quote->quote_ref;
    }
}
