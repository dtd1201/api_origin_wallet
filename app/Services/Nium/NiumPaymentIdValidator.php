<?php

namespace App\Services\Nium;

/** Syntactic safety only; a valid-looking ID still requires authoritative provider evidence. */
final class NiumPaymentIdValidator
{
    public static function isValid(mixed $paymentId): bool
    {
        // Retain the existing conservative storage bound, not a claimed Nium format/length rule.
        if (! is_string($paymentId) || $paymentId === '' || strlen($paymentId) > 255
            || strcasecmp($paymentId, 'INITIALIZED') === 0
            || preg_match('/[\x00-\x1f\x7f]/', $paymentId) !== 0) {
            return false;
        }

        // Unicode boundary whitespace is not covered by trim(). Invalid UTF-8 fails closed too.
        return preg_match('/\A[\s\p{Z}]|[\s\p{Z}]\z/u', $paymentId) === 0;
    }
}
