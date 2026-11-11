<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

use DateTimeImmutable;
use InvalidArgumentException;

/** Parsed rate/reference-amount evidence; deliberately not a fee or debit calculator. */
final readonly class PayoutRateQuote
{
    private function __construct(
        public PayoutQuoteRequest $request,
        public string $contextFingerprint,
        public string $id,
        public Decimal $sourceAmount,
        public Decimal $destinationAmount,
        public Decimal $rate,
        public DateTimeImmutable $expiresAt,
        public string $responseSha256,
    ) {}

    public static function fromJson(string $body, PayoutQuoteRequest $request, PayoutQuoteContext $context): self
    {
        if ($request->customer !== $context->corridor->customer
            || $request->sourceCurrency !== $context->corridor->sourceCurrency
            || $request->destinationCurrency !== $context->corridor->destinationCurrency) {
            throw new InvalidArgumentException('payout_quote_request_context_mismatch');
        }
        $quote = self::referenceFromJson($body, $request);

        return new self($request, $context->fingerprint(), $quote['id'], $quote['sourceAmount'],
            $quote['destinationAmount'], $quote['netExchangeRate'], $quote['expiresAt'], hash('sha256', $body));
    }

    /** Exact provider reference values only. This does not create an eligible payout context. */
    public static function referenceFromJson(string $body, PayoutQuoteRequest $request): array
    {
        if (strlen($body) > 65536) {
            throw new InvalidArgumentException('payout_quote_response_too_large');
        }
        // Syntax/type validation only. Never use this decode's financial values.
        // Quote original numeric tokens before the authoritative decode, including
        // large integers. Strings are matched atomically and unchanged.
        $structure = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($structure) || array_is_list($structure)) {
            throw new InvalidArgumentException('payout_quote_response_invalid');
        }
        $pattern = '~"(?:[^"\\\\]|\\\\.)*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|[{}\[\]:,]|true|false|null~s';
        preg_match_all($pattern, $body, $matches);
        $depth = 0;
        $previous = null;
        $keys = [];
        foreach ($matches[0] as $token) {
            if ($depth === 1 && str_starts_with($token, '"') && in_array($previous, ['{', ','], true)) {
                $key = json_decode($token, true, 512, JSON_THROW_ON_ERROR);
                if (isset($keys[$key])) {
                    throw new InvalidArgumentException('payout_quote_duplicate_field');
                }
                $keys[$key] = true;
            }
            $depth += in_array($token, ['{', '['], true) ? 1 : (in_array($token, ['}', ']'], true) ? -1 : 0);
            $previous = $token;
        }
        $json = preg_replace_callback('~"(?:[^"\\\\]|\\\\.)*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?~s',
            static fn (array $m): string => str_starts_with($m[0], '"') ? $m[0] : json_encode($m[0], JSON_THROW_ON_ERROR), $body);
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('payout_quote_response_invalid');
        }
        foreach (['quoteType' => 'payout', 'customerHashId' => $request->customer,
            'sourceCurrencyCode' => $request->sourceCurrency, 'destinationCurrencyCode' => $request->destinationCurrency,
            'conversionSchedule' => 'immediate', 'executionType' => 'at_conversion_time',
            'quoteIntent' => 'EXECUTABLE', 'lockPeriod' => $request->lockPeriod] as $key => $value) {
            if (($structure[$key] ?? null) !== $value) {
                throw new InvalidArgumentException('payout_quote_response_binding_mismatch');
            }
        }
        $id = $structure['id'] ?? null;
        $expiry = $structure['expiryTime'] ?? null;
        if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $id) !== 1
            || ! is_string($expiry)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$/D', $expiry) !== 1) {
            throw new InvalidArgumentException('payout_quote_identity_or_expiry_invalid');
        }
        $date = new DateTimeImmutable($expiry);
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
            throw new InvalidArgumentException('payout_quote_expiry_invalid');
        }
        $numbers = [];
        foreach (['sourceAmount', 'destinationAmount', 'netExchangeRate'] as $field) {
            if (! is_string($data[$field] ?? null) || strlen($data[$field]) > 64) {
                throw new InvalidArgumentException('payout_quote_amounts_unavailable');
            }
            $numbers[$field] = new Decimal($data[$field]);
            if ($numbers[$field]->isZero()) {
                throw new InvalidArgumentException('payout_quote_amount_invalid');
            }
        }
        if (! $numbers[$request->mode->quoteField()]->equals($request->amount)) {
            throw new InvalidArgumentException('payout_quote_input_mismatch');
        }

        return ['id' => $id, 'expiresAt' => $date, ...$numbers];
    }
}
