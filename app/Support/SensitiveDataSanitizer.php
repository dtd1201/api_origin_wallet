<?php

namespace App\Support;

class SensitiveDataSanitizer
{
    public function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $nestedKey => $nestedValue) {
                $sanitized[$nestedKey] = $this->sanitize(
                    $nestedValue,
                    is_string($nestedKey) ? $nestedKey : null
                );
            }

            return $sanitized;
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalizedKey = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));

        if ($normalizedKey === '') {
            return false;
        }

        if (in_array($normalizedKey, [
            'authorization',
            'cookie',
            'setcookie',
            'password',
            'passwordconfirmation',
            'passwordhash',
            'verificationcode',
            'token',
            'idtoken',
            'accesstoken',
            'refreshtoken',
            'clientsecret',
            'apikey',
            'apisecret',
            'xapikey',
            'xapisecret',
            'signature',
        ], true)) {
            return true;
        }

        return str_contains($normalizedKey, 'secret')
            || ($normalizedKey !== 'tokentype' && str_ends_with($normalizedKey, 'token'));
    }
}
