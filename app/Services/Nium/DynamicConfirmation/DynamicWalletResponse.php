<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use RuntimeException;
use stdClass;

/** Pure response parser. Only WalletSnapshot::refresh establishes authenticated scope. */
final class DynamicWalletResponse
{
    public static function available(string $body, array $identity): string
    {
        if (strlen($body) > 1048576) {
            throw new RuntimeException('dynamic_wallet_authenticated_snapshot_unavailable');
        }
        // Quote ORIGINAL numeric tokens before ANY JSON decoding. Reuse the
        // shared duplicate-key/syntax checks without relaxing its object contract
        // or ever passing Wallet monetary numbers through a PHP float.
        $json = preg_replace_callback('~"(?:[^"\\\\]|\\\\.)*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?~s',
            static fn (array $m): string => str_starts_with($m[0], '"') ? $m[0] : json_encode($m[0], JSON_THROW_ON_ERROR), $body);
        ExactProviderJson::decode('{"walletResponse":'.$json.'}');
        // Objects remain objects: a JSON object with numeric keys is not a list.
        $data = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        $rootArray = is_array($data);
        if ($data instanceof stdClass) {
            self::identity($data, $identity);
            if (property_exists($data, 'data')) {
                if (property_exists($data, 'balances') || property_exists($data, 'currencies') || ! ($data->data instanceof stdClass)) {
                    throw new RuntimeException('dynamic_wallet_response_contract_unavailable');
                }
                $data = $data->data;
                self::identity($data, $identity);
            }
            if (property_exists($data, 'data') || property_exists($data, 'balances') === property_exists($data, 'currencies')) {
                throw new RuntimeException('dynamic_wallet_response_contract_unavailable');
            }
            $data = $data->balances ?? $data->currencies ?? null;
        }
        if (! is_array($data)) {
            throw new RuntimeException('dynamic_wallet_response_contract_unavailable');
        }

        $usd = [];
        foreach ($data as $row) {
            if (! ($row instanceof stdClass) || property_exists($row, 'data')
                || property_exists($row, 'balances') || property_exists($row, 'currencies')) {
                throw new RuntimeException('dynamic_wallet_response_contract_unavailable');
            }
            self::identity($row, $identity);
            $currencies = [];
            foreach (['currency', 'currencyCode', 'curSymbol', 'isoCode'] as $field) {
                if (property_exists($row, $field)) {
                    if (! is_string($row->$field) || preg_match('/^[A-Z]{3}$/D', $row->$field) !== 1) {
                        throw new RuntimeException('dynamic_wallet_currency_unverified');
                    }
                    $currencies[] = $row->$field;
                }
            }
            if ($currencies === [] || count(array_unique($currencies)) !== 1) {
                throw new RuntimeException('dynamic_wallet_currency_unverified');
            }
            $amounts = [];
            foreach (['balance', 'availableBalance', 'withHoldingBalance'] as $field) {
                if (property_exists($row, $field)) {
                    if (! is_string($row->$field)) {
                        throw new RuntimeException('dynamic_wallet_usd_availability_unavailable');
                    }
                    $amounts[$field] = DynamicReservationLedger::storage($row->$field);
                }
            }
            if (isset($amounts['balance'], $amounts['availableBalance'])
                && $amounts['balance'] !== $amounts['availableBalance']) {
                throw new RuntimeException('dynamic_wallet_available_balance_conflict');
            }
            if ($currencies[0] === 'USD') {
                // Observed root arrays require balance. Wrapped legacy responses
                // require availableBalance. If both occur they must agree.
                // Operator contract: balance IS spendable; never subtract withholding.
                $available = $rootArray ? ($amounts['balance'] ?? null)
                    : ($amounts['availableBalance'] ?? null);
                if ($available === null) {
                    throw new RuntimeException('dynamic_wallet_usd_availability_unavailable');
                }
                $usd[] = $available;
            }
        }
        // default/pocketName/mccData provide no evidenced USD disambiguation rule.
        if (count($usd) !== 1) {
            throw new RuntimeException('dynamic_wallet_usd_availability_unavailable');
        }

        return $usd[0];
    }

    private static function identity(stdClass $object, array $identity): void
    {
        foreach (['clientHashId' => 'client', 'customerHashId' => 'customer', 'walletHashId' => 'wallet'] as $field => $key) {
            if (property_exists($object, $field) && (! is_string($object->$field) || $object->$field !== ($identity[$key] ?? null))) {
                throw new RuntimeException('dynamic_wallet_response_scope_unverified');
            }
        }
    }
}
