<?php

namespace App\Services\Nium;

use InvalidArgumentException;

/** Version-specific wire contracts; a funding rail is local intent for V1. */
final class NiumVirtualAccountAssignmentContract
{
    public static function isConfirmedV1Corridor(string $currency, string $category, string $rail, ?string $bank): bool
    {
        return $currency === 'USD' && $category === 'SELF_FUNDING_ACCOUNT'
            && $rail === 'WIRES' && ($bank === null || $bank === 'JPM_SG');
    }

    public static function endpointTemplate(string $version): string
    {
        self::assertVersion($version);

        return '/api/'.$version.'/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentId';
    }

    public static function payload(string $version, string $currency, string $category, string $rail, ?string $bank): array
    {
        self::assertVersion($version);
        $payload = $version === 'v1'
            ? ['currencyCode' => $currency, 'accountCategory' => $category, 'bankName' => $bank]
            : ['currency' => $currency, 'accountCategory' => $category, 'accountType' => $rail, 'bankName' => $bank];

        return array_filter($payload, static fn ($value) => $value !== null && $value !== '');
    }

    public static function assertPayload(string $version, array $payload, ?string $fundingRail): void
    {
        self::assertVersion($version);
        $currencyField = $version === 'v1' ? 'currencyCode' : 'currency';
        $allowed = $version === 'v1'
            ? ['currencyCode', 'accountCategory', 'bankName']
            : ['currency', 'accountCategory', 'accountType', 'bankName'];
        // The V1 literal "Null" has no defined business use here and remains disabled.
        $categories = $version === 'v1'
            ? ['SELF_FUNDING_ACCOUNT', 'COLLECTION_ACCOUNT']
            : ['SELF_FUNDING_ACCOUNT', 'COLLECTION_ACCOUNT', 'SELF_FUNDING_AND_COLLECTION_ACCOUNT'];
        if (array_diff(array_keys($payload), $allowed) !== []
            || ! is_string($payload[$currencyField] ?? null) || preg_match('/^[A-Z]{3}$/D', $payload[$currencyField]) !== 1
            || array_filter($payload, fn ($value) => ! is_string($value) || trim($value) !== $value || $value === '') !== []
            || ! in_array($payload['accountCategory'] ?? null, $categories, true)
            || ! in_array($version === 'v1' ? $fundingRail : ($payload['accountType'] ?? null), ['LOCAL', 'WIRES', 'LOCAL_AND_WIRES'], true)
            || ($version === 'v1' && ! isset($payload['bankName']))
            || (isset($payload['bankName']) && strlen($payload['bankName']) > 100)
            || ($version === 'v2' && $fundingRail !== null)) {
            throw new InvalidArgumentException('Invalid canonical assignment payload.');
        }
    }

    public static function assertVersion(string $version): void
    {
        if (! in_array($version, ['v1', 'v2'], true)) {
            throw new InvalidArgumentException('Unsupported assignment API version.');
        }
    }
}
