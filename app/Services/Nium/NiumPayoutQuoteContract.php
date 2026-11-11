<?php

namespace App\Services\Nium;

use App\Models\FxQuote;
use App\Models\Transfer;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use RuntimeException;

/** Executable payout quotes are not legacy rate-lock audit IDs or balance transfers. */
final class NiumPayoutQuoteContract
{
    /** Preserve numeric JSON financial tokens before PHP can round a provider decimal. */
    public static function decodeResponse(string $body): array
    {
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($response) || array_is_list($response)) {
            throw new RuntimeException('NIUM payout quote response must be an object.');
        }
        // JSON was validated above. Tokenize strings atomically so nested fields and
        // text containing JSON cannot supply a different top-level financial value.
        $pattern = <<<'REGEX'
~"(?:[^"\\]|\\.)*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|[{}\[\]:,]|true|false|null~s
REGEX;
        preg_match_all($pattern, $body, $matches);
        $depth = 0;
        $previous = null;
        $key = null;
        $values = [];
        foreach ($matches[0] as $token) {
            if ($depth === 1 && str_starts_with($token, '"') && in_array($previous, ['{', ','], true)) {
                $key = json_decode($token, true, 512, JSON_THROW_ON_ERROR);
            } elseif ($depth === 1 && $previous === ':' && $key !== null) {
                $values[$key] = $token;
            }
            $depth += in_array($token, ['{', '['], true) ? 1 : (in_array($token, ['}', ']'], true) ? -1 : 0);
            $previous = $token;
        }
        foreach (['sourceAmount', 'destinationAmount', 'exchangeRate', 'netExchangeRate', 'markupRate', 'clientMarkupRate', 'destinationMarkupAmount'] as $field) {
            if (is_float($response[$field] ?? null)) {
                $number = $values[$field];
                if (strlen($number) > 64 || (preg_match('/[eE]([+-]?[0-9]+)$/D', $number, $exponent)
                    && abs((int) $exponent[1]) > 30)) {
                    throw new RuntimeException('NIUM financial number exceeds supported decimal bounds.');
                }
                $response[$field] = (string) BigDecimal::of($number);
            }
        }

        return $response;
    }

    public static function request(string $customer, string $sourceCurrency, string $targetCurrency, string $sourceAmount): array
    {
        return [
            'sourceCurrencyCode' => $sourceCurrency, 'destinationCurrencyCode' => $targetCurrency,
            'sourceAmount' => self::sourceAmountNumber($sourceAmount),
            'customerHashId' => $customer, 'quoteType' => 'payout',
            'quoteIntent' => 'EXECUTABLE', 'conversionSchedule' => 'immediate',
            'executionType' => 'at_conversion_time', 'lockPeriod' => '15_mins',
        ];
    }

    public static function parse(array $response, string $sourceCurrency, string $targetCurrency, string $sourceAmount): array
    {
        $id = $response['id'] ?? null;
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $id)
            || (array_key_exists('quoteType', $response) && $response['quoteType'] !== 'payout')
            || (array_key_exists('quoteIntent', $response) && $response['quoteIntent'] !== 'EXECUTABLE')
            || (array_key_exists('conversionSchedule', $response) && $response['conversionSchedule'] !== 'immediate')
            || (array_key_exists('executionType', $response) && $response['executionType'] !== 'at_conversion_time')
            || (array_key_exists('lockPeriod', $response) && $response['lockPeriod'] !== '15_mins')) {
            throw new RuntimeException('NIUM requires an executable payout quote, not a balance-transfer quote.');
        }

        $amount = NiumPayoutFxLockContract::decimal($sourceAmount);
        if ($amount === '0' || NiumPayoutFxLockContract::decimal($response['sourceAmount'] ?? '') !== $amount) {
            throw new RuntimeException('NIUM payout quote does not match the fixed source amount.');
        }
        foreach (['sourceCurrencyCode' => $sourceCurrency, 'destinationCurrencyCode' => $targetCurrency] as $key => $currency) {
            if (array_key_exists($key, $response) && $response[$key] !== $currency) {
                throw new RuntimeException('NIUM payout quote currencies do not match.');
            }
        }
        $providerRate = $response['netExchangeRate'] ?? $response['exchangeRate'] ?? null;
        if (! is_string($providerRate) && ! is_int($providerRate)) {
            throw new RuntimeException('NIUM SGD payout requires a decimal string rate, never a binary float.');
        }
        $rate = NiumPayoutFxLockContract::decimal($providerRate);
        if ($rate === '0' || strlen(explode('.', $rate)[1] ?? '') > 10 || strlen(explode('.', $rate)[0]) > 10) {
            throw new RuntimeException('NIUM payout quote rate does not fit the stored precision.');
        }
        // The longer lock must not introduce a margin or additional charge. This
        // guard supplements, and never replaces, the unchanged financial policy.
        foreach (['markupRate', 'clientMarkupRate', 'destinationMarkupAmount'] as $field) {
            if (array_key_exists($field, $response) && NiumPayoutFxLockContract::decimal($response[$field]) !== '0') {
                throw new RuntimeException('NIUM payout quote contains an unapproved FX markup. Submission is blocked.');
            }
        }
        if (array_key_exists('exchangeRate', $response)
            && NiumPayoutFxLockContract::decimal($response['exchangeRate']) !== $rate) {
            throw new RuntimeException('NIUM payout quote contains an unapproved rate discrepancy. Submission is blocked.');
        }
        foreach (array_keys($response) as $field) {
            // Create Quote has no approved fee/debit evidence adapter here. Do not
            // interpret newly returned charge fields as covered business terms,
            // even if a field appears to contain zero or an empty collection.
            if (preg_match('/fee|charge|debit/i', $field)) {
                throw new RuntimeException('NIUM payout quote returned unsupported charge terms. Submission is blocked pending financial review.');
            }
        }
        $expiresAt = Carbon::parse(NiumPayoutFxLockContract::expiry($response['expiryTime'] ?? null))->startOfSecond();
        if ($expiresAt->lessThanOrEqualTo(now())) {
            throw new RuntimeException('NIUM payout quote has expired.');
        }
        $conversion = isset($response['destinationAmount']) ? NiumPayoutFxLockContract::decimal($response['destinationAmount']) : null;
        if ($conversion === '0') {
            throw new RuntimeException('NIUM payout quote conversion amount must be positive.');
        }

        return [
            'version' => 1, 'quote_id' => $id, 'quote_type' => 'payout',
            'source_currency' => $sourceCurrency, 'target_currency' => $targetCurrency,
            'source_amount' => $amount, 'rate' => $rate, 'expires_at' => $expiresAt->toISOString(),
            'conversion_amount' => $conversion,
        ];
    }

    /** Exact JSON number for quote I/O only; this does not define the held remittance payload. */
    public static function sourceAmountNumber(string $value): int|float
    {
        $amount = NiumSgdLocalCugScope::principal($value);
        $number = str_contains($amount, '.') ? (float) $amount : (int) $amount;
        if (! BigDecimal::of(json_encode($number, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))->isEqualTo($amount)) {
            throw new RuntimeException('The source principal cannot be represented exactly in the NIUM quote request.');
        }

        return $number;
    }

    public static function assertMatchesTransfer(FxQuote $quote, Transfer $transfer, array $context, bool $requireActive = true): void
    {
        $raw = $quote->raw_data ?? [];
        $snapshot = $raw['payout_quote_contract'] ?? [];
        if ($requireActive && isset($raw['consumed_at'])) {
            throw new RuntimeException('A consumed NIUM quote cannot authorize a new review or approval.');
        }
        if (($transfer->raw_data['sgd_local_workflow']['version'] ?? null) === 1
            && (($raw['workflow_transfer_id'] ?? null) !== $transfer->id
                || ($raw['lock_request_uuid'] ?? null) !== ($transfer->raw_data['sgd_local_workflow']['lock_request_id'] ?? null)
                || ($raw['execution_type'] ?? null) !== 'at_conversion_time'
                || ($raw['conversion_schedule'] ?? null) !== 'immediate')) {
            throw new RuntimeException('A modern quote bound to this saved instruction is required.');
        }
        if (($raw['provider_fx_type'] ?? null) !== 'modern_quote'
            || ($raw['quote_type'] ?? null) !== 'payout' || ($raw['quote_intent'] ?? null) !== 'EXECUTABLE'
            || ($raw['provider_quote_id'] ?? null) !== $quote->quote_ref
            || ! NiumPayoutFxLockContract::sameBinding($raw['provider_context'] ?? null, $context)
            || ($snapshot['version'] ?? null) !== 1 || ($snapshot['quote_type'] ?? null) !== 'payout'
            || ($snapshot['quote_id'] ?? null) !== $quote->quote_ref
            || ! is_string($quote->quote_ref) || ! preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $quote->quote_ref)
            || $quote->expires_at === null || ($requireActive && $quote->expires_at->lessThanOrEqualTo(now()))
            || ($snapshot['expires_at'] ?? null) !== $quote->expires_at->toISOString()
            || $quote->user_id !== $transfer->user_id || $quote->provider_id !== $transfer->provider_id
            || $quote->source_currency !== $transfer->source_currency || $quote->target_currency !== $transfer->target_currency
            || ($snapshot['source_currency'] ?? null) !== $transfer->source_currency
            || ($snapshot['target_currency'] ?? null) !== $transfer->target_currency
            || ($snapshot['source_amount'] ?? null) !== NiumPayoutFxLockContract::decimal($transfer->source_amount)
            || NiumPayoutFxLockContract::decimal($quote->source_amount) !== NiumPayoutFxLockContract::decimal($transfer->source_amount)
            || NiumPayoutFxLockContract::decimal($snapshot['rate'] ?? '') !== NiumPayoutFxLockContract::decimal($quote->net_rate)
            || NiumPayoutFxLockContract::decimal($quote->net_rate) === '0'
            || NiumPayoutFxLockContract::decimal($quote->net_rate) !== NiumPayoutFxLockContract::decimal($transfer->fx_rate)) {
            throw new RuntimeException('NIUM payout quote type, ownership, expiry or accepted instruction does not match. Obtain a fresh quote and customer authorization; do not reuse this lock.');
        }
    }
}
