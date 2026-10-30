<?php

namespace App\Services\Nium;

use App\Models\FxQuote;
use App\Models\Transfer;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/** Pure validation of the public legacy lock contract; never requests a lock. */
final class NiumPayoutFxLockContract
{
    /** JSON objects may be reordered by PostgreSQL; values and list positions remain strict. */
    public static function sameBinding(mixed $actual, mixed $expected): bool
    {
        if (! is_array($actual) || ! is_array($expected)) {
            return $actual === $expected;
        }
        if (count($actual) !== count($expected) || array_is_list($actual) !== array_is_list($expected)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual) || ! self::sameBinding($actual[$key], $value)) {
                return false;
            }
        }

        return true;
    }

    public static function decimal(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value))
            || ! preg_match('/^\d+(?:\.\d+)?$/D', (string) $value)) {
            throw new RuntimeException('Nium payout FX requires an exact non-negative decimal.');
        }

        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        return $whole.($fraction === '' ? '' : '.'.$fraction);
    }

    public static function parse(array $response, string $sourceCurrency, string $targetCurrency, string $sourceAmount): array
    {
        $amount = self::decimal($sourceAmount);
        if ($amount === '0') {
            throw new RuntimeException('Nium payout FX source amount must be positive.');
        }

        $audit = self::aliases($response, ['audit_id', 'auditId'], function ($value): string {
            if (! NiumPayoutAuditId::isValid($value)) {
                throw new RuntimeException('Nium payout FX requires an authoritative integer audit ID.');
            }

            return (string) $value;
        });
        $rate = self::aliases($response, ['fx_rate', 'fxRate', 'exchangeRate'], self::decimal(...));
        if ($rate === '0' || strlen(explode('.', $rate)[1] ?? '') > 10) {
            throw new RuntimeException('Nium payout FX rate must be positive and fit the stored precision.');
        }
        $sgd = $targetCurrency === 'SGD';
        $rateText = null;
        foreach (['fx_rate', 'fxRate', 'exchangeRate'] as $key) {
            if (array_key_exists($key, $response)) {
                if ($sgd && ! is_string($response[$key]) && ! is_int($response[$key])) {
                    throw new RuntimeException('Nium SGD payout FX requires a decimal string rate, never a binary float.');
                }
                $rateText ??= (string) $response[$key];
            }
        }
        $expiry = self::aliases($response, ['hold_expiry_at', 'holdExpiryAt', 'expiresAt'], self::expiry(...));
        $storedExpiry = Carbon::parse($expiry)->startOfSecond();
        if ($storedExpiry->lessThanOrEqualTo(now())) {
            throw new RuntimeException('Nium payout FX lock has expired.');
        }
        if (($sgd || array_key_exists('status', $response)) && ($response['status'] ?? null) !== 'ACTIVE') {
            throw new RuntimeException('Nium payout FX lock is not active.');
        }

        foreach ([['source_currency', 'sourceCurrency', $sourceCurrency], ['destination_currency', 'destinationCurrency', $targetCurrency]] as [$snake, $camel, $expected]) {
            if ($sgd && ! array_key_exists($snake, $response) && ! array_key_exists($camel, $response)) {
                throw new RuntimeException('Nium SGD payout FX response must confirm both currencies.');
            }
            foreach ([$snake, $camel] as $key) {
                if (array_key_exists($key, $response) && $response[$key] !== $expected) {
                    throw new RuntimeException('Nium payout FX lock returned a mismatched currency pair.');
                }
            }
        }
        if (array_key_exists('sourceAmount', $response) && self::decimal($response['sourceAmount']) !== $amount) {
            throw new RuntimeException('Nium payout FX response source amount does not match the transfer.');
        }

        // The legacy schema is rate-only. The public endpoint example additionally
        // contains amounts: accept its conversion amount only with explicit bindings.
        $conversionAmount = null;
        if (isset($response['destinationAmount'], $response['sourceAmount'], $response['sourceCurrency'], $response['destinationCurrency'])) {
            $conversionAmount = self::decimal($response['destinationAmount']);
            if ($conversionAmount === '0') {
                throw new RuntimeException('Nium payout FX response conversion amount must be positive.');
            }
        }

        return [
            'version' => $sgd ? 2 : 1, 'audit_id' => $audit, 'rate' => $rateText,
            // The existing datetime column stores seconds; round expiry down, never up.
            'expires_at' => $storedExpiry->toISOString(),
            'source_currency' => $sourceCurrency, 'target_currency' => $targetCurrency,
            'source_amount' => $amount, 'conversion_amount' => $conversionAmount,
            'status' => $response['status'] ?? null,
        ];
    }

    public static function assertMatchesTransfer(FxQuote $quote, Transfer $transfer, array $context, ?string $consumingOperation = null): void
    {
        NiumPayoutAuditId::fromQuote($quote);
        if (isset($quote->raw_data['consumed_at']) && ($consumingOperation === null
            || $transfer->status !== 'submitting' || $transfer->provider_operation_key !== $consumingOperation
            || ($quote->raw_data['consumed_operation_key'] ?? null) !== $consumingOperation
            || ($quote->raw_data['consumed_transfer_id'] ?? null) !== $transfer->id)) {
            throw new RuntimeException('Nium payout FX hold has already entered submission and cannot be reused.');
        }
        if ($quote->expires_at === null || $quote->expires_at->lessThanOrEqualTo(now())
            || $quote->user_id !== $transfer->user_id || $quote->provider_id !== $transfer->provider_id
            || strtoupper($quote->source_currency) !== strtoupper($transfer->source_currency)
            || strtoupper($quote->target_currency) !== strtoupper($transfer->target_currency)
            || self::decimal($quote->source_amount) !== self::decimal($transfer->source_amount)
            || self::decimal($quote->net_rate) === '0'
            || self::decimal($quote->net_rate) !== self::decimal($transfer->fx_rate)) {
            throw new RuntimeException('Nium payout FX lock ownership, expiry, amount, currencies or rate do not match.');
        }

        $snapshot = $quote->raw_data['payout_fx_lock_contract'] ?? null;
        $binding = $quote->raw_data['provider_context'] ?? null;
        if ($snapshot === null && $binding === null && strtoupper($transfer->target_currency) !== 'SGD') {
            // Preserve historical non-SGD fixtures/locks. Never admit an unbound SGD lock.
            return;
        }

        if (! is_array($snapshot) || ! self::sameBinding($binding, $context)
            || ! in_array($snapshot['version'] ?? null, [1, 2], true)
            || (strtoupper($transfer->target_currency) === 'SGD'
                && (($snapshot['version'] ?? null) !== 2 || ($snapshot['status'] ?? null) !== 'ACTIVE'))
            || ($snapshot['audit_id'] ?? null) !== (string) $quote->quote_ref
            || ($snapshot['source_currency'] ?? null) !== strtoupper($transfer->source_currency)
            || ($snapshot['target_currency'] ?? null) !== strtoupper($transfer->target_currency)
            || ($snapshot['source_amount'] ?? null) !== self::decimal($transfer->source_amount)
            || self::decimal($snapshot['rate'] ?? null) !== self::decimal($quote->net_rate)
            || ($snapshot['expires_at'] ?? null) !== $quote->expires_at->toISOString()) {
            throw new RuntimeException('Nium payout FX lock client, customer, wallet or response binding does not match.');
        }
    }

    private static function aliases(array $response, array $keys, callable $normalize): string
    {
        $values = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $response)) {
                $values[] = $normalize($response[$key]);
            }
        }
        if ($values === [] || count(array_unique($values)) !== 1) {
            throw new RuntimeException('Nium payout FX response has missing or conflicting authoritative fields.');
        }

        return $values[0];
    }

    /** Absolute provider/review expiry; relative expressions must never extend validity. */
    public static function expiry(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})$/D', $value)) {
            throw new RuntimeException('Nium payout FX lock returned an invalid expiry.');
        }
        try {
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new RuntimeException('Invalid timestamp.');
            }

            return Carbon::instance($date)->toISOString();
        } catch (Throwable) {
            throw new RuntimeException('Nium payout FX lock returned an invalid expiry.');
        }
    }
}
