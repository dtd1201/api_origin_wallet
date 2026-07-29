<?php

namespace App\Services\Nium;

use JsonException;

class NiumCustomerPayloadHashVerifier
{
    public const APPROVED_SHA256 = '157398db48ce68929749db78304b012845dc62c6ac5bc53eab1909874c08c0e3';

    public function matchesApproved(array $payload): bool
    {
        try {
            return hash_equals(self::APPROVED_SHA256, $this->sha256($payload));
        } catch (JsonException) {
            return false;
        }
    }

    public function sha256(array $payload): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION,
            ),
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);

        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }
}
