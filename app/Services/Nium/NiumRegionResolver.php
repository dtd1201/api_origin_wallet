<?php

namespace App\Services\Nium;

use RuntimeException;

final class NiumRegionResolver
{
    public const INVALID_REGION = 'nium_region_invalid';

    private const EUROPEAN_COUNTRIES = [
        'AT',
        'BE',
        'BG',
        'HR',
        'CY',
        'CZ',
        'DE',
        'DK',
        'EE',
        'ES',
        'FI',
        'FR',
        'GR',
        'HU',
        'IE',
        'IS',
        'IT',
        'LI',
        'LT',
        'LU',
        'LV',
        'MT',
        'NO',
        'PL',
        'PT',
        'RO',
        'SE',
        'SI',
        'SK',
    ];

    private const DIRECTLY_SUPPORTED_COUNTRIES = [
        'SG',
        'US',
        'AU',
        'NZ',
        'CA',
        'HK',
        'JP',
        'MX',
        'BR',
        'ID',
    ];

    private const SUPPORTED_EXPLICIT_REGIONS = [
        'SG',
        'UK',
        'EU',
        'NL',
        'US',
        'AU',
        'NZ',
        'CA',
        'HK',
        'JP',
        'MX',
        'BR',
        'ID',
    ];

    public function resolve(
        mixed $explicitRegion,
        mixed $registeredCountry,
        mixed $residenceCountry,
        mixed $country,
    ): string {
        if ($explicitRegion !== null) {
            $normalizedRegion = $this->normalizeString($explicitRegion);

            if (
                $normalizedRegion === null
                || $normalizedRegion === ''
                || ! in_array($normalizedRegion, self::SUPPORTED_EXPLICIT_REGIONS, true)
            ) {
                throw new RuntimeException(self::INVALID_REGION);
            }

            return $normalizedRegion;
        }

        $resolvedCountry = $this->firstCountry(
            $registeredCountry,
            $residenceCountry,
            $country,
        );

        if ($resolvedCountry === 'GB') {
            return 'UK';
        }

        if ($resolvedCountry === 'NL') {
            return 'NL';
        }

        if (in_array($resolvedCountry, self::EUROPEAN_COUNTRIES, true)) {
            return 'EU';
        }

        return in_array($resolvedCountry, self::DIRECTLY_SUPPORTED_COUNTRIES, true)
            ? $resolvedCountry
            : 'SG';
    }

    public function resolveForValidation(
        mixed $explicitRegion,
        mixed $registeredCountry,
        mixed $residenceCountry,
        mixed $country,
    ): string {
        if ($explicitRegion !== null && ! $this->isSupportedExplicitRegion($explicitRegion)) {
            return 'SG';
        }

        return $this->resolve(
            $explicitRegion,
            $registeredCountry,
            $residenceCountry,
            $country,
        );
    }

    public function isSupportedExplicitRegion(mixed $value): bool
    {
        $normalized = $this->normalizeString($value);

        return $normalized !== null
            && $normalized !== ''
            && in_array($normalized, self::SUPPORTED_EXPLICIT_REGIONS, true);
    }

    private function firstCountry(mixed ...$values): string
    {
        foreach ($values as $value) {
            $normalized = $this->normalizeString($value);

            if ($normalized !== null && $normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function normalizeString(mixed $value): ?string
    {
        return is_string($value)
            ? strtoupper(trim($value))
            : null;
    }
}
