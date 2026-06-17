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

        if (is_string($value)) {
            return $this->redactConfiguredSecretValues($value);
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
            'secretkey',
            'apikey',
            'apisecret',
            'xapikey',
            'xapisecret',
            'headervalue',
            'authheadervalue',
            'webhooksecret',
            'privatetoken',
            'privatekey',
            'accesskey',
            'awsaccesskeyid',
            'awssecretaccesskey',
            'signature',
        ], true)) {
            return true;
        }

        return str_contains($normalizedKey, 'authorization')
            || str_contains($normalizedKey, 'apikey')
            || str_contains($normalizedKey, 'apisecret')
            || str_contains($normalizedKey, 'secret')
            || ($normalizedKey !== 'tokentype' && str_ends_with($normalizedKey, 'token'));
    }

    private function redactConfiguredSecretValues(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        foreach ($this->configuredSecretValues() as $secretValue) {
            $value = str_replace($secretValue, '[REDACTED]', $value);
        }

        return $value;
    }

    private function configuredSecretValues(): array
    {
        $secretValues = [];

        $walk = function (mixed $value, ?string $key = null) use (&$walk, &$secretValues): void {
            if (is_array($value)) {
                foreach ($value as $nestedKey => $nestedValue) {
                    $walk($nestedValue, is_string($nestedKey) ? $nestedKey : null);
                }

                return;
            }

            if (! is_string($value) || $value === '' || $key === null || ! $this->isSensitiveKey($key)) {
                return;
            }

            if (strlen($value) < 6) {
                return;
            }

            $secretValues[] = $value;
        };

        $walk((array) config('services', []));

        $secretValues = array_values(array_unique($secretValues));
        usort($secretValues, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $secretValues;
    }
}
