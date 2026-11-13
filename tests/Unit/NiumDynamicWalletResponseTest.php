<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nium\DynamicConfirmation\DynamicWalletResponse;
use App\Services\Nium\DynamicConfirmation\ExactProviderJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NiumDynamicWalletResponseTest extends TestCase
{
    private const IDENTITY = ['client' => 'client', 'customer' => 'customer', 'wallet' => 'wallet'];

    public static function validResponses(): array
    {
        return [
            'observed fields and nonzero withholding' => ['[{"curSymbol":"USD","isoCode":"USD","balance":1000.01,"withHoldingBalance":100,"pocketName":"synthetic","mccData":[],"default":true}]', '1000.01000000'],
            'maximum exact storage precision' => ['[{"curSymbol":"USD","balance":999999999999.12345678}]', '999999999999.12345678'],
            'smallest increment' => ['[{"curSymbol":"USD","balance":0.00000001}]', '0.00000001'],
            'zero balance with withholding' => ['[{"curSymbol":"USD","balance":0,"withHoldingBalance":2}]', '0.00000000'],
            'multiple currencies' => ['[{"curSymbol":"EUR","isoCode":"EUR","balance":20},{"curSymbol":"USD","isoCode":"USD","balance":30}]', '30.00000000'],
            'consistent aliases' => ['[{"currency":"USD","currencyCode":"USD","curSymbol":"USD","isoCode":"USD","balance":1}]', '1.00000000'],
            'explicit matching IDs' => ['[{"walletHashId":"wallet","clientHashId":"client","customerHashId":"customer","curSymbol":"USD","balance":2}]', '2.00000000'],
            'both amounts numerically identical' => ['[{"curSymbol":"USD","balance":1.10,"availableBalance":"1.10000000"}]', '1.10000000'],
            'safe string decimal' => ['[{"curSymbol":"USD","balance":"1.01"}]', '1.01000000'],
            'trailing zeroes require no rounding' => ['[{"curSymbol":"USD","balance":1.000000000}]', '1.00000000'],
            'wrapped currencies numeric availability' => ['{"walletHashId":"wallet","currencies":[{"currencyCode":"USD","availableBalance":3.01}]}', '3.01000000'],
            'wrapped balances string availability' => ['{"walletHashId":"wallet","balances":[{"currency":"USD","availableBalance":"3.01"}]}', '3.01000000'],
            'data wrapper' => ['{"clientHashId":"client","data":{"walletHashId":"wallet","customerHashId":"customer","balances":[{"currency":"USD","availableBalance":4}]}}', '4.00000000'],
            'wrapped equal amounts and withholding' => ['{"currencies":[{"currencyCode":"USD","balance":4,"availableBalance":4,"withHoldingBalance":1}]}', '4.00000000'],
        ];
    }

    #[DataProvider('validResponses')]
    public function test_supported_shapes_preserve_exact_available_amounts(string $body, string $expected): void
    {
        $this->assertSame($expected, DynamicWalletResponse::available($body, self::IDENTITY));
    }

    public static function invalidResponses(): array
    {
        $cases = [
            'empty' => '', 'malformed' => '[', 'null root' => 'null', 'scalar root' => '1',
            'empty list' => '[]', 'empty object' => '{}',
            'missing USD' => '[{"curSymbol":"EUR","balance":1}]',
            'duplicate USD despite default' => '[{"curSymbol":"USD","balance":1,"default":true},{"curSymbol":"USD","balance":2,"default":false}]',
            'missing balance' => '[{"curSymbol":"USD"}]',
            'root availableBalance alone unsupported' => '[{"curSymbol":"USD","availableBalance":1}]',
            'wrapped balance alone unsupported' => '{"currencies":[{"currencyCode":"USD","balance":1}]}',
            'conflicting currency IDs' => '[{"curSymbol":"USD","isoCode":"EUR","balance":1}]',
            'invalid currency type' => '[{"curSymbol":"USD","isoCode":840,"balance":1}]',
            'missing currency' => '[{"balance":1}]',
            'lowercase currency' => '[{"curSymbol":"usd","balance":1}]',
            'conflicting availability' => '[{"curSymbol":"USD","balance":10,"availableBalance":9}]',
            'wrapped conflict' => '{"balances":[{"currency":"USD","balance":10,"availableBalance":9}]}',
            'two collections' => '{"balances":[],"currencies":[{"currency":"USD","balance":1}]}',
            'competing data wrapper' => '{"data":{"balances":[]},"currencies":[{"currency":"USD","balance":1}]}',
            'unsupported wrapped list' => '{"data":[{"curSymbol":"USD","balance":1}]}',
            'recursive wrapper' => '{"data":{"data":{},"balances":[{"currency":"USD","availableBalance":1}]}}',
            'numeric object keys are not a list' => '{"0":{"curSymbol":"USD","balance":1}}',
            'numeric collection keys are not a list' => '{"balances":{"0":{"curSymbol":"USD","balance":1}}}',
            'nested pocket collection' => '[{"curSymbol":"USD","balance":1,"balances":[]}]',
            'nonobject row' => '[[],{"curSymbol":"USD","balance":1}]',
            'malformed other pocket' => '[{"balance":1},{"curSymbol":"USD","balance":1}]',
            'duplicate field' => '[{"curSymbol":"USD","balance":1,"balance":2}]',
            'escaped duplicate field' => '[{"curSymbol":"USD","balance":1,"balanc\u0065":2}]',
            'duplicate nested metadata field' => '[{"curSymbol":"USD","balance":1,"mccData":{"x":1,"x":2}}]',
            'duplicate collection' => '{"balances":[],"balances":[{"currency":"USD","availableBalance":1}]}',
            'negative withholding' => '[{"curSymbol":"USD","balance":1,"withHoldingBalance":-1}]',
            'null withholding' => '[{"curSymbol":"USD","balance":1,"withHoldingBalance":null}]',
            'unsupported negative other pocket' => '[{"curSymbol":"EUR","balance":-1},{"curSymbol":"USD","balance":1}]',
            'null competing amount' => '[{"curSymbol":"USD","balance":1,"availableBalance":null}]',
        ];
        foreach (['-1', '-0', '1e2', '1E-2', '1.000000001', '1000000000000', 'NaN', 'Infinity',
            'null', 'true', '[]', '{}', '""', '" 1"', '"+1"', '"01"', '"1e2"', '"NaN"', '"Infinity"'] as $value) {
            $cases['invalid money '.$value] = '[{"curSymbol":"USD","balance":'.$value.'}]';
        }
        foreach (['walletHashId', 'customerHashId', 'clientHashId'] as $field) {
            foreach (['"other"', 'null', 'false', '{}'] as $value) {
                $cases[$field.' row '.$value] = '[{"'.$field.'":'.$value.',"curSymbol":"USD","balance":1}]';
                $cases[$field.' envelope '.$value] = '{"'.$field.'":'.$value.',"currencies":[{"currency":"USD","availableBalance":1}]}';
            }
            $cases[$field.' outer conflict'] = '{"'.$field.'":"other","data":{"balances":[{"currency":"USD","availableBalance":1}]}}';
        }

        return array_map(static fn (string $body): array => [$body], $cases);
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_ambiguous_or_unbound_response_fails_closed(string $body): void
    {
        $this->expectException(\Exception::class);
        DynamicWalletResponse::available($body, self::IDENTITY);
    }

    public function test_response_size_limit_is_enforced_before_decoding(): void
    {
        $this->expectExceptionMessage('dynamic_wallet_authenticated_snapshot_unavailable');
        DynamicWalletResponse::available(str_repeat(' ', 1048577), self::IDENTITY);
    }

    public function test_wallet_support_does_not_relax_the_shared_object_decoder(): void
    {
        $this->expectExceptionMessage('dynamic_provider_response_invalid');
        ExactProviderJson::decode('[{"balance":1}]');
    }
}
