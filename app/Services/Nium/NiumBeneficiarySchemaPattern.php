<?php

namespace App\Services\Nium;

/** The ECMAScript Unicode regex subset used by beneficiary JSON Schemas. */
final class NiumBeneficiarySchemaPattern
{
    public const DIALECT = 'ecma262-unicode';

    // ECMAScript WhiteSpace + LineTerminator, including BOM and excluding U+0085.
    private const SPACE = '\x{0009}-\x{000d}\x{0020}\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}';

    // With Unicode mode and no ignoreCase flag, ECMAScript digits/words remain ASCII.
    // Explicit complements also work as members of a larger (possibly negated) class.
    private const CHARACTER_CLASSES = [
        'd' => '0-9',
        'D' => '\x{0000}-\x{002f}\x{003a}-\x{10ffff}',
        'w' => 'A-Za-z0-9_',
        'W' => '\x{0000}-\x{002f}\x{003a}-\x{0040}\x{005b}-\x{005e}\x{0060}\x{007b}-\x{10ffff}',
        's' => self::SPACE,
    ];

    public static function compile(string $pattern): ?string
    {
        $expression = '';
        $inClass = false;
        $classStart = 0;
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '\\') {
                if (++$i >= $length) {
                    return null;
                }
                $escape = $pattern[$i];
                if ($inClass && str_contains('dDwWsS', $escape)
                    && self::isClassRangeEndpoint($pattern, $i, $classStart)) {
                    // ECMAScript /u forbids a character-set escape as either range endpoint.
                    return null;
                }
                if (isset(self::CHARACTER_CLASSES[$escape])) {
                    $characters = self::CHARACTER_CLASSES[$escape];
                    $expression .= $inClass ? $characters : '['.$characters.']';
                } elseif ($escape === 'S' && ! $inClass) {
                    $expression .= '[^'.self::SPACE.']';
                } elseif ($escape === 'v') {
                    // PCRE's \v includes more characters than ECMAScript's vertical tab.
                    $expression .= '\x{000b}';
                } elseif ($escape === 'b' && $inClass) {
                    $expression .= '\x{0008}';
                } elseif (str_contains('\\^$.*+?()[]{}|/nrtf', $escape) || ($escape === '-' && $inClass)) {
                    $expression .= '\\'.$escape;
                } else {
                    // Do not interpret PCRE-only escapes or unimplemented ECMAScript escapes.
                    return null;
                }
            } elseif ($char === '[') {
                if ($inClass) {
                    return null;
                }
                $inClass = true;
                $classStart = $i;
                $expression .= $char;
            } elseif ($char === ']') {
                if (! $inClass) {
                    return null;
                }
                $inClass = false;
                $expression .= $char;
            } elseif (! $inClass && $char === '.') {
                // ECMAScript dot excludes all four line terminators, rather than only LF.
                $expression .= '[^\x{000a}\x{000d}\x{2028}\x{2029}]';
            } elseif (! $inClass && $char === '{') {
                if (preg_match('/\A\{[0-9]+(?:,[0-9]*)?\}/', substr($pattern, $i), $quantifier) !== 1) {
                    return null;
                }
                $expression .= $quantifier[0];
                $i += strlen($quantifier[0]) - 1;
                if (($pattern[$i + 1] ?? '') === '+') {
                    return null;
                }
            } elseif (! $inClass && $char === '}') {
                return null;
            } elseif (! $inClass && $char === '(' && ($pattern[$i + 1] ?? '') === '?'
                && ! in_array($pattern[$i + 2] ?? '', [':', '=', '!'], true)) {
                return null;
            } elseif (! $inClass && $char === '(' && ($pattern[$i + 1] ?? '') === '*') {
                return null;
            } elseif (! $inClass && in_array($char, ['*', '+', '?'], true) && ($pattern[$i + 1] ?? '') === '+') {
                return null;
            } else {
                $expression .= $char;
            }
        }
        if ($inClass) {
            return null;
        }
        foreach (['~', '#', '%', '!', '@', ';', '`'] as $delimiter) {
            if (! str_contains($expression, $delimiter)) {
                // D gives non-multiline ECMAScript $ semantics: absolute end of input.
                $compiled = $delimiter.$expression.$delimiter.'uD';

                return @preg_match($compiled, '') === false ? null : $compiled;
            }
        }

        return null;
    }

    /** $escapeIndex points at the letter after the backslash in the original pattern. */
    private static function isClassRangeEndpoint(string $pattern, int $escapeIndex, int $classStart): bool
    {
        if (($pattern[$escapeIndex + 1] ?? '') === '-'
            && ($pattern[$escapeIndex + 2] ?? '') !== ']') {
            return true;
        }
        $hyphen = $escapeIndex - 2;
        $firstMember = $classStart + (($pattern[$classStart + 1] ?? '') === '^' ? 2 : 1);
        if ($hyphen <= $firstMember || $pattern[$hyphen] !== '-') {
            return false;
        }
        $backslashes = 0;
        for ($i = $hyphen - 1; $i > $classStart && $pattern[$i] === '\\'; $i--) {
            $backslashes++;
        }

        return $backslashes % 2 === 0;
    }

    public static function matches(string $pattern, string $value): bool
    {
        $compiled = self::compile($pattern);

        return $compiled !== null && @preg_match($compiled, $value) === 1;
    }
}
