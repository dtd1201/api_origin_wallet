<?php

namespace Tests\Unit;

use App\Services\Nium\NiumPaymentIdValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NiumPaymentIdValidatorTest extends TestCase
{
    #[DataProvider('invalidIdentifiers')]
    public function test_rejects_unsafe_identifiers_without_coercion(mixed $value): void
    {
        $this->assertFalse(NiumPaymentIdValidator::isValid($value));
    }

    public static function invalidIdentifiers(): array
    {
        $cases = [
            'reported newline' => ["VA\nINVALID"],
            'empty' => [''],
            'spaces' => ['   '],
            'leading space' => [' VA-123'],
            'trailing space' => ['VA-123 '],
            'initialized' => ['INITIALIZED'],
            'mixed case initialized' => ['InItIaLiZeD'],
            'null' => [null],
            'integer' => [123],
            'float' => [123.45],
            'true' => [true],
            'false' => [false],
            'array' => [['VA-123']],
            'object' => [(object) ['id' => 'VA-123']],
            'unicode whitespace only' => ["\u{00a0}\u{2003}"],
            'leading unicode whitespace' => ["\u{00a0}VA-123"],
            'trailing unicode whitespace' => ["VA-123\u{2003}"],
            'unicode line separator suffix' => ["VA-123\u{2028}"],
            'invalid UTF-8' => ["VA-\xff"],
            'existing storage bound exceeded' => [str_repeat('V', 256)],
        ];
        foreach (array_merge(range(0, 31), [127]) as $code) {
            $cases['embedded ASCII control '.$code] = ['VA'.chr($code).'INVALID'];
        }

        return $cases;
    }

    #[DataProvider('validLookingIdentifiers')]
    public function test_does_not_invent_a_bank_specific_format(string $value): void
    {
        $this->assertTrue(NiumPaymentIdValidator::isValid($value));
    }

    public static function validLookingIdentifiers(): array
    {
        return [
            ['VA-LEDGER-ONE'], ['0123456789'], ['11111111-1111-4111-8111-111111111111'],
            ['VA.123/ABC:+_='], ['VA INTERNAL SPACE'], ['Référence-支付-123'], [str_repeat('V', 255)],
        ];
    }
}
