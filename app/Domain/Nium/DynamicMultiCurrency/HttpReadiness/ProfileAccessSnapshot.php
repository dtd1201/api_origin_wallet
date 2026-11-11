<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\HttpReadiness;

/** Internal local User/profile projection; never a request-provided assertion. */
final readonly class ProfileAccessSnapshot
{
    public function __construct(
        public string $userId,
        public ?string $kycStatus,
        public bool $profileExists,
        public ?string $userType,
    ) {}

    public function complete(): bool
    {
        // Preserve EnsureUserProfileIsComplete: verified KYC OR a populated
        // profile user_type. Neither branch establishes corridor entitlement.
        return in_array(strtolower((string) $this->kycStatus), ['approved', 'verified'], true)
            || ($this->profileExists && $this->userType !== null && trim($this->userType) !== '');
    }
}
