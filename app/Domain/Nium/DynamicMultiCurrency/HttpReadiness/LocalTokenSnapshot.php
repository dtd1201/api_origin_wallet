<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\HttpReadiness;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * Internal projection of a freshly read ApiToken and its attached User, never
 * request data. Does not prove that a token still exists or read authentication
 * storage. A future adapter must query the token/user on EVERY request, without
 * caching positive snapshots (logout/password reset revoke tokens by deletion).
 * Never serialize or log this object, its hash or its identifiers.
 */
final readonly class LocalTokenSnapshot
{
    public function __construct(
        #[SensitiveParameter] private string $storedHash,
        public ?string $tokenUserId,
        public ?string $relatedUserId,
        public ?DateTimeImmutable $expiresAt,
    ) {}

    public function matches(#[SensitiveParameter] string $bearerToken): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $this->storedHash) === 1
            && hash_equals($this->storedHash, hash('sha256', $bearerToken));
    }
}
