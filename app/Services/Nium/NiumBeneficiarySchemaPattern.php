<?php

namespace App\Services\Nium;

/** The ECMAScript Unicode regex subset used by beneficiary JSON Schemas. */
final class NiumBeneficiarySchemaPattern
{
    public const DIALECT = 'ecma262-unicode';

    // ECMAScript WhiteSpace + LineTerminator, including BOM and excluding U+0085.
    private const SPACE = '\x{0009}-\x{000d}\x{0020}\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}';

    public static function compile(string $pattern): ?string
    {
        $expression = '';
        $inClass = false;
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '\\') {
                if (++$i >= $length) {
                    return null;
                }
                $escape = $pattern[$i];
                if ($escape === 's') {
                    $expression .= $inClass ? self::SPACE : '['.self::SPACE.']';
                } elseif ($escape === 'S' && ! $inClass) {
                    $expression .= '[^'.self::SPACE.']';
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

    public static function matches(string $pattern, string $value): bool
    {
        $compiled = self::compile($pattern);

        return $compiled !== null && @preg_match($compiled, $value) === 1;
    }
}
