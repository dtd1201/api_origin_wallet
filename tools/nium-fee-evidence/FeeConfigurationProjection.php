<?php

declare(strict_types=1);

namespace NiumFeeEvidence;

use RuntimeException;
use stdClass;

/** Bounded documentary projection only; never chooses rules or calculates fees. */
final class FeeConfigurationProjection
{
    public const MAX_BYTES = 262144;

    private array $missing = [];

    private array $unsupported = [];

    private int $nodes = 0;

    private const FEES = [
        'remit_bank_fee', 'remit_bank_fee_swift_ben', 'remit_bank_fee_swift_our', 'remit_bank_fee_swift',
        'remit_cash_fee', 'remit_wallet_fee', 'remit_card_fee', 'remit_proxy_fee', 'remit_bank_fee_fedwire',
        'remit_check_fee', 'remit_return_fee', 'fx_markup', 'fx_markup_auto_sweep', 'fx_markup_auto_sweep_eod',
        'fx_markup_auto_sweep_receive', 'fx_markup_lockandhold_1', 'fx_markup_settle_2days',
        'fx_markup_settle_nextday', 'fx_markup_settle_endofday', 'fx_markup_settle_immediate',
        'fx_markup_lock_5mins', 'fx_markup_lock_10mins', 'fx_markup_lock_15mins', 'fx_markup_lock_1hour',
        'fx_markup_lock_4hours', 'fx_markup_lock_8hours', 'fx_markup_lock_24hours', 'fx_markup_cancellation',
        'fx_markup_reversal', 'fx_markup_offmarket', 'fx_markup_settle_3days', 'fx_markup_settle_7days',
        'fx_markup_variance', 'fx_markup_settle_0_7days', 'fx_markup_settle_8_14days',
        'fx_markup_settle_15_21days', 'fx_markup_settle_22_30days',
        'fx_markup_forwards_cancellation', 'fx_markup_forwards_settlement_failure',
    ];

    public function project(string $raw): array
    {
        $this->missing = $this->unsupported = [];
        $this->nodes = 0;
        $root = self::decodeObject($raw);
        $this->object($root, 'response', ['feePlans']);
        $plans = [];
        foreach ($this->list($root, 'feePlans', 'response') as $index => $plan) {
            $path = 'feePlans['.$index.']';
            $this->object($plan, $path, ['planName', 'isDefault', 'products']);
            if (! isset($plan->planName) || ! is_string($plan->planName) || $plan->planName === ''
                || strlen($plan->planName) > 256 || ! is_bool($plan->isDefault ?? null)) {
                throw new RuntimeException('fee_response_invalid');
            }
            $products = [];
            foreach ($this->list($plan, 'products', $path) as $pi => $product) {
                $pp = $path.'.products['.$pi.']';
                $this->object($product, $pp, ['name', 'fees']);
                $name = $this->enum($product, 'name', $pp, ['payout', 'fx'], true);
                $fees = [];
                foreach ($this->list($product, 'fees', $pp) as $fi => $fee) {
                    $fp = $pp.'.fees['.$fi.']';
                    $this->object($fee, $fp, ['fee', 'rules']);
                    $feeName = $this->enum($fee, 'fee', $fp, self::FEES, true);
                    $sourceRules = $this->list($fee, 'rules', $fp);
                    if ($name === null || $feeName === null) {
                        // No values from an unrecognized product/fee are exported.
                        $fees[] = ['fee' => $feeName, 'rules' => [], 'projection' => 'UNSUPPORTED'];
                        continue;
                    }
                    $rules = [];
                    foreach ($sourceRules as $ri => $rule) {
                        $rules[] = $this->rule($rule, $fp.'.rules['.$ri.']');
                    }
                    $fees[] = ['fee' => $feeName, 'rules' => $rules];
                }
                $products[] = ['name' => $name, 'fees' => $fees];
            }
            $plans[] = ['plan_alias' => 'plan-'.substr(hash('sha256', $plan->planName), 0, 16),
                'is_default' => $plan->isDefault, 'products' => $products];
        }

        return ['plans' => $plans, 'missing_fields' => $this->missing, 'unsupported_fields' => $this->unsupported];
    }

    /** Preserve object/list types and reject duplicate keys, including escaped duplicates. */
    public static function decodeObject(string $raw): stdClass
    {
        if (strlen($raw) > self::MAX_BYTES) {
            throw new RuntimeException('fee_response_invalid');
        }
        $value = json_decode($raw, false, 40, JSON_THROW_ON_ERROR);
        if (! $value instanceof stdClass) {
            throw new RuntimeException('fee_response_invalid');
        }
        preg_match_all('~"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|true|false|null~s', $raw, $tokens);
        $stack = [];
        $previous = null;
        foreach ($tokens[0] as $token) {
            $top = count($stack) - 1;
            if ($top >= 0 && $stack[$top]['object'] && str_starts_with($token, '"')
                && in_array($previous, ['{', ','], true)) {
                $key = json_decode($token, false, 2, JSON_THROW_ON_ERROR);
                // Prefix keeps numeric-looking keys from PHP array-key coercion.
                if (isset($stack[$top]['keys']['key:'.$key])) {
                    throw new RuntimeException('fee_response_invalid');
                }
                $stack[$top]['keys']['key:'.$key] = true;
            }
            if ($token === '{' || $token === '[') {
                $stack[] = ['object' => $token === '{', 'keys' => []];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            }
            $previous = $token;
        }

        return $value;
    }

    private function rule(mixed $rule, string $path): array
    {
        $this->object($rule, $path, ['type', 'condition', 'price', 'tier']);
        $type = $this->enum($rule, 'type', $path, ['nontier', 'tier']);
        $result = ['type' => $type, 'applicability' => 'UNVERIFIED'];
        if (property_exists($rule, 'condition')) {
            $result['condition'] = $this->condition($rule->condition, $path.'.condition', 0);
        }
        if (property_exists($rule, 'price')) {
            $result['price'] = $this->price($rule->price, $path.'.price');
        } elseif ($type === 'nontier') {
            $this->missing[] = $path.'.price';
        }
        if (property_exists($rule, 'tier')) {
            $tp = $path.'.tier';
            $this->object($rule->tier, $tp, ['on', 'ranges']);
            if (! property_exists($rule->tier, 'on')) {
                throw new RuntimeException('fee_response_invalid');
            }
            $on = $rule->tier->on;
            $this->object($on, $tp.'.on', ['aggregatedBy', 'currency', 'parameter']);
            $ranges = [];
            foreach ($this->list($rule->tier, 'ranges', $tp) as $index => $range) {
                $rp = $tp.'.ranges['.$index.']';
                $this->object($range, $rp, ['start', 'end', 'price']);
                $ranges[] = ['start' => $this->decimal($range, 'start', $rp), 'end' => $this->decimal($range, 'end', $rp),
                    'price' => property_exists($range, 'price') ? $this->price($range->price, $rp.'.price') : $this->absent($rp.'.price')];
            }
            $result['tier'] = ['on' => [
                'aggregatedBy' => $this->enum($on, 'aggregatedBy', $tp.'.on', ['aggregated_volume', 'total_quantity']),
                'currency' => $this->code($on, 'currency', $tp.'.on', 3),
                'parameter' => $this->enum($on, 'parameter', $tp.'.on', ['source_amount', 'destination_amount']),
            ], 'ranges' => $ranges];
        } elseif ($type === 'tier') {
            $this->missing[] = $path.'.tier';
        }
        if (isset($result['price'], $result['tier'])) {
            $this->unsupported[] = ['path' => $path, 'reason' => 'combined_price_and_tier'];
        }

        return $result;
    }

    private function price(mixed $price, string $path): array
    {
        $this->object($price, $path, ['type', 'currency', 'value', 'percentageOf']);
        $type = $this->enum($price, 'type', $path, ['flat', 'percentage']);
        $result = ['type' => $type, 'currency' => $this->code($price, 'currency', $path, 3),
            'value' => $this->decimal($price, 'value', $path)];
        if ($type === 'percentage' || property_exists($price, 'percentageOf')) {
            $result['percentageOf'] = $this->enum($price, 'percentageOf', $path, ['source_amount', 'destination_amount']);
        }

        return $result;
    }

    private function condition(mixed $condition, string $path, int $depth): array
    {
        if ($depth > 12) {
            throw new RuntimeException('fee_response_invalid');
        }
        $known = ['operator', 'operands', 'sourceCurrency', 'destinationCurrency', 'sourceCountry', 'destinationCountry',
            'sourceAmount', 'destinationAmount', 'beneficiaryAccountType', 'remitterAccountType', 'payoutMethod',
            'payoutRail', 'swiftType', 'partnerBank', 'payMode', 'paymentMode', 'sweepType'];
        $this->object($condition, $path, $known);
        $result = [];
        if (property_exists($condition, 'operator') && ! property_exists($condition, 'operands')) {
            $this->missing[] = $path.'.operands';
        }
        if (property_exists($condition, 'operands') && ! property_exists($condition, 'operator')) {
            $this->missing[] = $path.'.operator';
        }
        foreach (get_object_vars($condition) as $field => $value) {
            if (! in_array($field, $known, true)) {
                continue;
            }
            $result[$field] = match ($field) {
                'operator' => $this->enum($condition, $field, $path, ['and', 'or']),
                'sourceCurrency', 'destinationCurrency' => $this->code($condition, $field, $path, 3),
                'sourceCountry', 'destinationCountry' => $this->code($condition, $field, $path, 2),
                'sourceAmount', 'destinationAmount' => $this->decimal($condition, $field, $path),
                'beneficiaryAccountType', 'remitterAccountType' => $this->enum($condition, $field, $path, ['INDIVIDUAL', 'CORPORATE']),
                'payoutMethod' => $this->enum($condition, $field, $path, ['LOCAL', 'SWIFT', 'WALLET', 'CASH', 'CARD', 'PROXY', 'CHECK']),
                'swiftType' => $this->enum($condition, $field, $path, ['SHA', 'OUR', 'BEN']),
                'operands' => array_map(fn ($item, $index) => $this->condition($item, $path.'.operands['.$index.']', $depth + 1),
                    $this->list($condition, $field, $path), array_keys($condition->operands)),
                default => $this->unresolved($path.'.'.$field, 'unverified_condition_value'),
            };
        }
        if ($result === []) {
            $this->unsupported[] = ['path' => $path, 'reason' => 'empty_or_unprojected_condition'];
        }

        return $result;
    }

    private function object(mixed $value, string $path, array $keys): void
    {
        if (! $value instanceof stdClass || ++$this->nodes > 10000) {
            throw new RuntimeException('fee_response_invalid');
        }
        $extra = count(array_diff(array_keys(get_object_vars($value)), $keys));
        if ($extra > 0) {
            $this->unsupported[] = ['path' => $path, 'reason' => 'unrecognized_members', 'count' => $extra];
        }
    }

    private function list(stdClass $parent, string $field, string $path): array
    {
        $list = $parent->{$field} ?? null;
        if (! is_array($list) || ! array_is_list($list) || count($list) > 500) {
            throw new RuntimeException('fee_response_invalid');
        }
        if ($list === []) {
            $this->missing[] = $path.'.'.$field.'[]';
        }

        return $list;
    }

    private function enum(stdClass $parent, string $field, string $path, array $allowed, bool $caseInsensitive = false): ?string
    {
        if (! property_exists($parent, $field)) {
            return $this->absent($path.'.'.$field);
        }
        $value = $parent->{$field};
        if (! is_string($value)) {
            throw new RuntimeException('fee_response_invalid');
        }
        if (! in_array($caseInsensitive ? strtolower($value) : $value, $allowed, true)) {
            return $this->unresolved($path.'.'.$field, 'unrecognized_value');
        }

        return $value;
    }

    private function decimal(stdClass $parent, string $field, string $path): ?string
    {
        if (! property_exists($parent, $field)) {
            return $this->absent($path.'.'.$field);
        }
        $value = $parent->{$field};
        if (! is_string($value) || strlen($value) > 64 || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw new RuntimeException('fee_response_invalid');
        }

        return $value;
    }

    private function code(stdClass $parent, string $field, string $path, int $length): ?string
    {
        if (! property_exists($parent, $field)) {
            return $this->absent($path.'.'.$field);
        }
        $value = $parent->{$field};
        if ($value === null || $value === 'ANY') {
            return $this->unresolved($path.'.'.$field, 'currency_or_country_unresolved');
        }
        if (! is_string($value) || preg_match('/^[A-Z]{'.$length.'}$/D', $value) !== 1) {
            throw new RuntimeException('fee_response_invalid');
        }

        return $value;
    }

    private function absent(string $path): null
    {
        $this->missing[] = $path;

        return null;
    }

    private function unresolved(string $path, string $reason): null
    {
        $this->unsupported[] = ['path' => $path, 'reason' => $reason];

        return null;
    }
}
