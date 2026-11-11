<?php

declare(strict_types=1);

namespace NiumFeeEvidence;

use RuntimeException;
use stdClass;

/** ClientFeeDetailsResponseDTO[] / FeeResponseDTO. Observations, never evaluation. */
final class V2FeeConfigurationProjection
{
    private array $missing = [];

    private array $unsupported = [];

    // Deliberately limited documentary vocabulary, not a payout currency registry.
    private const CURRENCIES = ['USD', 'HKD', 'SGD', 'EUR', 'GBP', 'AUD', 'CAD', 'JPY'];

    private const FEES = [
        'FX_MARKUP', 'FX_MARKUP_AUTO_SWEEP', 'FX_MARKUP_AUTO_SWEEP_EOD',
        'FX_MARKUP_AUTO_SWEEP_RECEIVE', 'FX_MARKUP_LOCKANDHOLD_1', 'FX_MARKUP_SETTLE_2DAYS',
        'FX_MARKUP_SETTLE_NEXTDAY', 'FX_MARKUP_SETTLE_ENDOFDAY', 'FX_MARKUP_SETTLE_IMMEDIATE',
        'FX_MARKUP_LOCK_5MINS', 'FX_MARKUP_LOCK_10MINS', 'FX_MARKUP_LOCK_15MINS',
        'FX_MARKUP_LOCK_1HOUR', 'FX_MARKUP_LOCK_4HOURS', 'FX_MARKUP_LOCK_8HOURS', 'FX_MARKUP_LOCK_24HOURS',
        'FX_MARKUP_CANCELLATION', 'FX_MARKUP_REVERSAL', 'FX_MARKUP_OFFMARKET',
        'REMIT_BANK_FEE', 'REMIT_BANK_FEE_SWIFT', 'REMIT_BANK_FEE_SWIFT_BEN', 'REMIT_BANK_FEE_SWIFT_OUR',
        'REMIT_CASH_FEE', 'REMIT_WALLET_FEE', 'REMIT_CARD_FEE', 'REMIT_PROXY_FEE',
        'REMIT_BANK_FEE_FEDWIRE', 'REMIT_CHECK_FEE', 'REMIT_RETURN_FEE',
    ];

    public function project(string $raw): array
    {
        $this->missing = $this->unsupported = [];
        $root = (new V2StrictJson)->decode($raw);
        $this->list($root);
        $plans = [];
        if ($root === []) {
            $this->missing[] = 'response[]';
        }
        foreach ($root as $index => $plan) {
            $path = 'response['.$index.']';
            $keys = ['default', 'segment', 'status', 'fees'];
            $this->object($plan, $path, $keys);
            $row = ['applicability' => 'UNVERIFIED'];
            foreach ($keys as $key) {
                if (! $this->present($plan, $key, $path)) {
                    continue;
                }
                $value = $plan->{$key};
                switch ($key) {
                    case 'default':
                        $row[$key] = $this->boolean($value);
                        break;
                    case 'segment':
                        if (! is_string($value) || $value === '' || strlen($value) > 256) {
                            throw new RuntimeException('v2_fee_response_invalid');
                        }
                        $row['segment_alias'] = 'segment-'.substr(hash('sha256', $value), 0, 16);
                        break;
                    case 'status':
                        $row[$key] = $this->token($value, $path.'.status', ['Active', 'Inactive', 'ACTIVE', 'INACTIVE']);
                        break;
                    case 'fees':
                        $this->list($value);
                        $row['fees'] = [];
                        if ($value === []) {
                            $this->missing[] = $path.'.fees[]';
                        }
                        foreach ($value as $fi => $fee) {
                            $row['fees'][] = $this->fee($fee, $path.'.fees['.$fi.']');
                        }
                }
            }
            $plans[] = $row;
        }

        return ['plans' => $plans, 'missing_fields' => $this->missing, 'unsupported_fields' => $this->unsupported];
    }

    private function fee(mixed $fee, string $path): array
    {
        $keys = ['authCurrency', 'feeCurrency', 'feeName', 'feeValue', 'fixed', 'slabFrom',
            'slabTo', 'thresholdFeeValue', 'status', 'transactionCurrency'];
        $this->object($fee, $path, $keys);
        $result = ['applicability' => 'UNVERIFIED'];
        foreach ($keys as $key) {
            if (! $this->present($fee, $key, $path)) {
                continue;
            }
            $value = $fee->{$key};
            $fp = $path.'.'.$key;
            $result[$key] = match ($key) {
                'feeValue', 'slabFrom', 'slabTo', 'thresholdFeeValue' => $this->decimal($value, $fp),
                'fixed' => $this->boolean($value),
                'feeName' => $this->token($value, $fp, self::FEES),
                'status' => $this->token($value, $fp, ['Active', 'Inactive', 'ACTIVE', 'INACTIVE']),
                default => $this->currency($value, $fp),
            };
        }

        return $result;
    }

    private function decimal(mixed $value, string $path): ?string
    {
        // Quoted numbers are a different schema, not a coercion opportunity.
        if (! $value instanceof V2JsonNumber || strlen($value->token) > 128) {
            throw new RuntimeException('v2_fee_response_invalid');
        }
        // Preserve negative/exponent tokens too, but explicitly deny interpretation.
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value->token) !== 1) {
            $this->unsupported[] = ['path' => $path, 'reason' => 'numeric_semantics_unverified'];
        }

        return $value->token;
    }

    private function currency(mixed $value, string $path): ?string
    {
        if ($value === null) {
            $this->unsupported[] = ['path' => $path, 'reason' => 'currency_unresolved'];

            return null;
        }
        if (! is_string($value) || preg_match('/^[A-Za-z]{3}$/D', $value) !== 1) {
            throw new RuntimeException('v2_fee_response_invalid');
        }
        if (! in_array($value, self::CURRENCIES, true)) {
            $this->unsupported[] = ['path' => $path, 'reason' => 'currency_unresolved'];
        }

        return $value;
    }

    private function token(mixed $value, string $path, array $known): string
    {
        // No arbitrary text, metadata, control characters or identifiers in output.
        if (! is_string($value) || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,79}$/D', $value) !== 1) {
            throw new RuntimeException('v2_fee_response_invalid');
        }
        if (! in_array($value, $known, true)) {
            $this->unsupported[] = ['path' => $path, 'reason' => 'unrecognized_value'];
        }

        return $value;
    }

    private function boolean(mixed $value): bool
    {
        if (! is_bool($value)) {
            throw new RuntimeException('v2_fee_response_invalid');
        }

        return $value;
    }

    private function present(stdClass $parent, string $key, string $path): bool
    {
        if (! property_exists($parent, $key)) {
            $this->missing[] = $path.'.'.$key;

            return false;
        }

        return true;
    }

    private function object(mixed $value, string $path, array $keys): void
    {
        if (! $value instanceof stdClass) {
            throw new RuntimeException('v2_fee_response_invalid');
        }
        $extra = count(array_diff(array_keys(get_object_vars($value)), $keys));
        if ($extra > 0) {
            $this->unsupported[] = ['path' => $path, 'reason' => 'unrecognized_members', 'count' => $extra];
        }
    }

    private function list(mixed $value): void
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 500) {
            throw new RuntimeException('v2_fee_response_invalid');
        }
    }
}
