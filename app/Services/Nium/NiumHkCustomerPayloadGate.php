<?php

namespace App\Services\Nium;

use RuntimeException;

final class NiumHkCustomerPayloadGate
{
    public static function assertRegions(
        mixed $configuredRegion,
        mixed $registeredCountry,
        mixed $profileCountry,
        mixed $metadataRegion,
        mixed $payloadRegion,
        mixed $payloadRegisteredCountry,
    ): void {
        if (
            $configuredRegion !== 'HK'
            || $registeredCountry !== 'HK'
            || $profileCountry !== 'HK'
            || $metadataRegion !== 'HK'
            || $payloadRegion !== 'HK'
            || $payloadRegisteredCountry !== 'HK'
        ) {
            throw new RuntimeException('HK configured, factual, and payload regions must match.');
        }
    }
}
