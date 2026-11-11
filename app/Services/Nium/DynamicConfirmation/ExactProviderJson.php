<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use RuntimeException;

/** Preserve original number tokens. Decoding to floats must never price or reconcile money. */
final class ExactProviderJson
{
    public static function decode(string $body): array
    {
        if (strlen($body) > 10000000) {
            throw new RuntimeException('dynamic_provider_response_too_large');
        }
        $shape = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($shape) || array_is_list($shape)) {
            throw new RuntimeException('dynamic_provider_response_invalid');
        }
        $pattern = '~"(?:[^"\\\\]|\\\\.)*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|[{}\[\]:,]|true|false|null~s';
        preg_match_all($pattern, $body, $tokens);
        $stack = [];
        $previous = null;
        foreach ($tokens[0] as $token) {
            $last = count($stack) - 1;
            if ($last >= 0 && $stack[$last] !== null && str_starts_with($token, '"')
                && in_array($previous, ['{', ','], true)) {
                $key = json_decode($token, true, 64, JSON_THROW_ON_ERROR);
                if (isset($stack[$last][$key])) {
                    throw new RuntimeException('dynamic_provider_duplicate_field');
                }
                $stack[$last][$key] = true;
            }
            if ($token === '{' || $token === '[') {
                $stack[] = $token === '{' ? [] : null;
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            }
            $previous = $token;
        }
        $json = preg_replace_callback('~"(?:[^"\\\\]|\\\\.)*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?~s',
            static fn (array $m): string => str_starts_with($m[0], '"') ? $m[0] : json_encode($m[0], JSON_THROW_ON_ERROR), $body);

        return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    }
}
