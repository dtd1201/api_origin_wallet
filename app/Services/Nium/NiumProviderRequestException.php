<?php

namespace App\Services\Nium;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use RuntimeException;

final class NiumProviderRequestException extends RuntimeException
{
    public function __construct(
        string $publicMessage,
        public readonly ?string $providerCode,
        public readonly ?string $providerField,
        public readonly ?string $providerPath,
    ) {
        parent::__construct($publicMessage);
    }

    public static function fromResponse(Response $response, string $publicMessage): self
    {
        $data = $response->json();
        $data = is_array($data) ? $data : [];
        $error = Arr::get($data, 'errors.0');
        $error = is_array($error) ? $error : [];

        return new self(
            $publicMessage,
            self::safeCode(
                $error['code']
                    ?? $error['errorCode']
                    ?? $data['errorCode']
                    ?? $data['code']
                    ?? null,
            ),
            self::safePath($error['field'] ?? null),
            self::safePath($error['path'] ?? $error['parameter'] ?? null),
        );
    }

    private static function safeCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== ''
            && strlen($value) <= 80
            && preg_match('/^[A-Za-z0-9_.-]+$/', $value) === 1
                ? $value
                : null;
    }

    private static function safePath(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (
            $value === ''
            || strlen($value) > 180
            || preg_match(
                '/^[A-Za-z][A-Za-z0-9_]*(?:(?:\[(?:\d+|\*)\])|(?:\.(?:[A-Za-z][A-Za-z0-9_]*|\d+)))*$/',
                $value,
            ) !== 1
        ) {
            return null;
        }

        return $value;
    }
}
