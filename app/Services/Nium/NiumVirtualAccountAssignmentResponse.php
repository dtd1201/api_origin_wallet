<?php

namespace App\Services\Nium;

/** Optional response fields are evidence, never replacements for the frozen target. */
final class NiumVirtualAccountAssignmentResponse
{
    public static function matchesTarget(array $response, NiumVirtualAccountRequestIdentity $identity): bool
    {
        $snapshot = $identity->snapshot();
        $payload = $snapshot['payload'];

        // Each response adapter uses its own frozen request contract. Optional aliases
        // are evidence only; no nested/display-name/generic "id" aliases are guessed.
        $dimensions = match ($snapshot['api_version']) {
            'v1' => self::v1Dimensions($payload, $identity->fundingRail()),
            'v2' => self::v2Dimensions($payload),
        };
        foreach ($dimensions + [
            'clientHashId' => $snapshot['client_id'],
            'walletHashId' => $snapshot['wallet_id'],
            'customerHashId' => $snapshot['customer_id'],
            'bankName' => $payload['bankName'] ?? null,
        ] as $field => $expected) {
            if (array_key_exists($field, $response)
                && (! is_string($response[$field]) || $response[$field] !== $expected)) {
                // Also fail closed for a reported bank when no bank was frozen. Its
                // equivalence cannot be established here. Do not trim, cast or case-fold.
                return false;
            }
        }

        return true;
    }

    private static function v1Dimensions(array $payload, string $fundingRail): array
    {
        return [
            'currencyCode' => $payload['currencyCode'], 'currency' => $payload['currencyCode'],
            'accountCategory' => $payload['accountCategory'],
            // This is a consistency check if returned, not a claim V1 accepts accountType.
            'accountType' => $fundingRail,
        ];
    }

    private static function v2Dimensions(array $payload): array
    {
        return [
            'currencyCode' => $payload['currency'], 'currency' => $payload['currency'],
            'accountCategory' => $payload['accountCategory'], 'accountType' => $payload['accountType'],
        ];
    }

    public static function isDelayed(array $response, NiumVirtualAccountRequestIdentity $identity, int $status): bool
    {
        // Both adapters recognize only the existing INITIALIZED sentinel. No inferred
        // acceptance from 202, status/message strings, or undocumented response shapes.
        return match ($identity->snapshot()['api_version']) {
            'v1', 'v2' => $status === 200 && ! isset($response['errors']) && self::matchesTarget($response, $identity)
                && is_string($response['uniquePaymentId'] ?? null) && strcasecmp($response['uniquePaymentId'], 'INITIALIZED') === 0,
        };
    }
}
