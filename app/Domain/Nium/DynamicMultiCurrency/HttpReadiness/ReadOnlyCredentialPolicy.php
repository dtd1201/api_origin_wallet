<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\HttpReadiness;

use DateTimeImmutable;
use SensitiveParameter;

/** Pure credential comparison, not a registered authenticator or token lookup. */
final class ReadOnlyCredentialPolicy
{
    public function authenticatedUserId(
        #[SensitiveParameter] ?string $bearerToken,
        ?LocalTokenSnapshot $token,
        DateTimeImmutable $asOf,
    ): ?string {
        if ($bearerToken === null || $bearerToken === '' || $token === null
            || $token->tokenUserId === null || $token->relatedUserId === null
            || preg_match('/^[1-9][0-9]*$/D', $token->tokenUserId) !== 1
            || $token->tokenUserId !== $token->relatedUserId || ! $token->matches($bearerToken)
            || ($token->expiresAt !== null && $token->expiresAt <= $asOf)) {
            return null;
        }

        // No trimming, case folding, sliding expiry, last-used writes or deletion.
        // NULL expiry is explicitly supported by the existing ApiToken contract.
        // Equality at expiry is deliberately closed (Carbon isPast is strict <).
        return $token->relatedUserId;
    }
}
