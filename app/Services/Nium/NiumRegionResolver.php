<?php

namespace App\Services\Nium;

use RuntimeException;

final class NiumRegionResolver
{
    public const INVALID_REGION = 'nium_region_invalid';

    public const REGION_MISMATCH = 'nium_regulatory_region_mismatch';

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
        $configuredRegion = config('services.nium.regulatory_region');
        $normalizedConfiguredRegion = null;

        if ($configuredRegion !== null) {
            $normalizedConfiguredRegion = $this->supportedRegion($configuredRegion);
        }

        if ($explicitRegion !== null) {
            $normalizedRegion = $this->supportedRegion($explicitRegion);

            if (
                $normalizedConfiguredRegion !== null
                && $normalizedRegion !== $normalizedConfiguredRegion
            ) {
                throw new RuntimeException(self::REGION_MISMATCH);
            }

            return $normalizedRegion;
        }

        if ($normalizedConfiguredRegion !== null) {
            return $normalizedConfiguredRegion;
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

        if (in_array($resolvedCountry, self::DIRECTLY_SUPPORTED_COUNTRIES, true)) {
            return $resolvedCountry;
        }

        throw new RuntimeException(self::INVALID_REGION);
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

        try {
            return $this->resolve(
                $explicitRegion,
                $registeredCountry,
                $residenceCountry,
                $country,
            );
        } catch (RuntimeException $exception) {
            return $exception->getMessage() === self::INVALID_REGION
                ? 'SG'
                : self::INVALID_REGION;
        }
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

    private function supportedRegion(mixed $value): string
    {
        $normalized = $this->normalizeString($value);

        if (
            $normalized === null
            || $normalized === ''
            || ! in_array($normalized, self::SUPPORTED_EXPLICIT_REGIONS, true)
        ) {
            throw new RuntimeException(self::INVALID_REGION);
        }

        return $normalized;
    }
}
