<?php

namespace Tests\Unit;

use App\Services\Nium\NiumBeneficiarySchemaPattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NiumBeneficiarySchemaPatternTest extends TestCase
{
    public function test_production_contact_pattern_compiles_to_explicit_ascii_digits(): void
    {
        $compiled = NiumBeneficiarySchemaPattern::compile('^\d{0,17}$');

        $this->assertNotNull($compiled);
        $this->assertStringContainsString('^[0-9]{0,17}$', $compiled);
        $this->assertStringNotContainsString('\\d', $compiled);
    }

    #[DataProvider('matchingCases')]
    public function test_supported_escapes_match_ecmascript_unicode_semantics(string $pattern, string $value, bool $expected): void
    {
        $this->assertNotNull(NiumBeneficiarySchemaPattern::compile($pattern));
        $this->assertSame($expected, NiumBeneficiarySchemaPattern::matches($pattern, $value));
    }

    public static function matchingCases(): array
    {
        return [
            ['^\d{0,17}$', '', true],
            ['^\d{0,17}$', '01234567890123456', true],
            ['^\d{0,17}$', '012345678901234567', false],
            ['^\d{0,17}$', '12a34', false],
            ['^\d{0,17}$', '+1234', false],
            ['^\d{0,17}$', '12 34', false],
            ['^\d{0,17}$', '١٢٣٤', false],
            ['^\d{0,17}$', '１２３４', false],
            ['^\d{0,17}$', '𝟙𝟚𝟛𝟜', false],
            ['^\d{0,17}$', "123\n", false],
            ['^\D+$', '١é😀+', true],
            ['^\D+$', 'a1', false],
            ['^\w+$', 'Az_019', true],
            ['^\w+$', 'é', false],
            ['^\w+$', 'K', false],
            ['^\w+$', 'ſ', false],
            ['^\W+$', 'éKſ١😀-', true],
            ['^\W+$', '_', false],
            ['^[\d]+$', '0123456789', true],
            ['^[\d]+$', '١', false],
            ['^[^\d]+$', '١a', true],
            ['^[\D]+$', '١é😀+', true],
            ['^[\D]+$', '1', false],
            ['^[^\D]+$', '0129', true],
            ['^[^\D]+$', '١', false],
            ['^[\w]+$', 'AZaz09_', true],
            ['^[\w]+$', 'é', false],
            ['^[\W]+$', "/:@[`{é😀\0", true],
            ['^[\W]+$', 'AZaz09_', false],
            ['^[^\W]+$', 'AZaz09_', true],
            ['^[^\W]+$', 'é', false],
            ['^[\dA-F]+$', '09AF', true],
            ['^[\dA-F]+$', 'G', false],
            ['^[\D5]+$', 'a5', true],
            ['^[\D5]+$', '4', false],
            ['^[\W_]+$', 'é_', true],
            ['^[\W_]+$', 'a', false],
            ['^[-\d]+$', '-12', true],
            ['^[^-\d]+$', 'a', true],
            ['^[\d-]+$', '-12', true],
            ['^[A\-\d]+$', 'A-12', true],
            ['^[\d\-A]+$', 'A-12', true],
            ['^[\\\\\d]+$', '\\12', true],
            ['^\\\\d$', '\\d', true],
            ['^\v$', "\x0b", true],
            ['^\v$', "\n", false],
            ['^[\v]$', "\x0b", true],
            ['^[\b]$', "\x08", true],
            ['^[\b]$', 'b', false],
            ['^\n\r\t\f$', "\n\r\t\x0c", true],
            ['^\s$', "\u{feff}", true],
            ['^\s$', "\u{0085}", false],
            ['^\S$', "\u{0085}", true],
            ['^[\s]$', "\u{feff}", true],
        ];
    }

    #[DataProvider('unsupportedPatterns')]
    public function test_unsupported_or_invalid_patterns_fail_closed(string $pattern): void
    {
        $this->assertNull(NiumBeneficiarySchemaPattern::compile($pattern));
        $this->assertFalse(NiumBeneficiarySchemaPattern::matches($pattern, 'anything'));
    }

    public static function unsupportedPatterns(): array
    {
        return array_map(static fn (string $pattern) => [$pattern], [
            '[', '\\', '\K', '\p{L}', '\u0030', '\x30', '\0', '\cA', '\bword\b', '\B', '(a)\1',
            '(?<=a)b', '(?i)a', 'a++', 'a{1,3}+', '[\S]',
            '[A-\d]', '[\d-A]', '[\d-\w]', '[a-\D]', '[\W-z]', '[a-\s]', '[\s-z]',
            '[\\\\-\d]',
        ]);
    }
}
