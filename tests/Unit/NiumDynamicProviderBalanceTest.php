<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nium\DynamicConfirmation\DynamicProviderBalance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NiumDynamicProviderBalanceTest extends TestCase
{
    public static function exactAmounts(): array
    {
        return [
            'zero integer' => [0, '0.00000000'],
            'whole major units' => [42, '42.00000000'],
            'integer string' => ['42', '42.00000000'],
            'decimal string' => ['42.12', '42.12000000'],
            'smallest exact increment' => ['0.00000001', '0.00000001'],
            'storage limit' => ['999999999999.12345678', '999999999999.12345678'],
            'exact trailing zeroes' => ['42.120000000', '42.12000000'],
        ];
    }

    #[DataProvider('exactAmounts')]
    public function test_exact_amounts_preserve_major_units_without_rounding(mixed $value, string $expected): void
    {
        $this->assertSame($expected, DynamicProviderBalance::normalize($value));
    }

    public static function invalidAmounts(): array
    {
        return array_map(static fn (mixed $value): array => [$value], [
            -1, 42.0, 42.12, NAN, INF, true, false, null, [], new \stdClass,
            '', ' 42', '42 ', '+42', '042', '-0', '-1', '1e2', '1E-2', 'NaN', 'Infinity',
            '.12', '42.', '42,12', '0.000000001', '1000000000000', PHP_INT_MAX,
        ]);
    }

    #[DataProvider('invalidAmounts')]
    public function test_unsafe_types_syntax_and_precision_fail_closed(mixed $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dynamic_authoritative_wallet_balance_unavailable');
        DynamicProviderBalance::normalize($value);
    }
}
