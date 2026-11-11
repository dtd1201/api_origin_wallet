<?php

declare(strict_types=1);

namespace NiumFeeEvidence;

use RuntimeException;
use stdClass;

/** A JSON number's original spelling; never converted to a PHP number. */
final readonly class V2JsonNumber
{
    public function __construct(public string $token) {}
}

/** Bounded JSON grammar with duplicate-member rejection and lossless numbers. */
final class V2StrictJson
{
    public const MAX_BYTES = 262144;

    private array $tokens = [];

    private int $position = 0;

    private int $nodes = 0;

    public function decode(string $raw): mixed
    {
        if (strlen($raw) > self::MAX_BYTES) {
            throw new RuntimeException('v2_json_rejected');
        }
        $this->tokens = [];
        $this->position = $this->nodes = 0;
        $offset = 0;
        // Anchoring every token prevents ignored garbage between valid tokens.
        $pattern = '~\G(?:[\x20\x09\x0a\x0d]+|"(?:[^"\\\\\x00-\x1f]|\\\\(?:["\\\\/bfnrt]|u[0-9a-fA-F]{4}))*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|true|false|null|[{}\[\]:,])~';
        while ($offset < strlen($raw)) {
            if (preg_match($pattern, $raw, $match, 0, $offset) !== 1) {
                throw new RuntimeException('v2_json_rejected');
            }
            $offset += strlen($match[0]);
            if (trim($match[0], " \t\r\n") !== '') {
                $this->tokens[] = $match[0];
            }
        }
        $value = $this->value(0);
        if ($this->position !== count($this->tokens)) {
            throw new RuntimeException('v2_json_rejected');
        }

        return $value;
    }

    private function value(int $depth): mixed
    {
        if ($depth > 40 || ++$this->nodes > 10000) {
            throw new RuntimeException('v2_json_rejected');
        }
        $token = $this->tokens[$this->position++] ?? '';
        if ($token === '{') {
            $object = new stdClass;
            if ($this->take('}')) {
                return $object;
            }
            do {
                $key = $this->tokens[$this->position++] ?? '';
                if (! str_starts_with($key, '"')) {
                    throw new RuntimeException('v2_json_rejected');
                }
                $key = json_decode($key, false, 2, JSON_THROW_ON_ERROR);
                if (property_exists($object, $key)) {
                    throw new RuntimeException('v2_json_rejected');
                }
                $this->expect(':');
                $object->{$key} = $this->value($depth + 1);
            } while ($this->take(','));
            $this->expect('}');

            return $object;
        }
        if ($token === '[') {
            $list = [];
            if ($this->take(']')) {
                return $list;
            }
            do {
                $list[] = $this->value($depth + 1);
            } while ($this->take(','));
            $this->expect(']');

            return $list;
        }
        if (str_starts_with($token, '"') || in_array($token, ['true', 'false', 'null'], true)) {
            // Only strings/booleans/null reach json_decode, never numeric tokens.
            return json_decode($token, false, 2, JSON_THROW_ON_ERROR);
        }
        if (preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D', $token) === 1) {
            return new V2JsonNumber($token);
        }

        throw new RuntimeException('v2_json_rejected');
    }

    private function take(string $token): bool
    {
        if (($this->tokens[$this->position] ?? null) !== $token) {
            return false;
        }
        ++$this->position;

        return true;
    }

    private function expect(string $token): void
    {
        if (! $this->take($token)) {
            throw new RuntimeException('v2_json_rejected');
        }
    }
}
