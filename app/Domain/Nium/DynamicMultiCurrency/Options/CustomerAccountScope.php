<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Options;

use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RevocationStatus;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Server-supplied ownership projection, never accepted from an HTTP request body.
 * Ownership permits disclosure only; it is not payout entitlement or activation.
 * A future authenticated adapter must verify these bindings from owned records.
 */
final readonly class CustomerAccountScope
{
    public array $fundingWallets;

    public function __construct(
        public string $ownerUserId,
        public string $provider,
        public string $environment,
        public string $client,
        public string $legalEntity,
        public string $customer,
        public string $account,
        public string $customerType,
        public string $profileVersion,
        array $fundingWallets,
        public EvidenceState $ownership,
        public DateTimeImmutable $validFrom,
        public DateTimeImmutable $expiresAt,
        public RevocationStatus $revocation,
    ) {
        foreach ([$ownerUserId, $provider, $environment, $client, $legalEntity, $customer, $account, $customerType, $profileVersion] as $value) {
            if (trim($value) === '' || trim($value) !== $value || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new InvalidArgumentException('options_scope_invalid');
            }
        }
        if (! in_array($environment, ['sandbox', 'production'], true) || preg_match('/^[A-Z][A-Z0-9_]*$/D', $customerType) !== 1) {
            throw new InvalidArgumentException('options_scope_invalid');
        }
        foreach ($fundingWallets as $currency => $wallet) {
            if (! is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || ! is_string($wallet)
                || trim($wallet) === '' || trim($wallet) !== $wallet || preg_match('/[\x00-\x1F\x7F]/', $wallet)) {
                throw new InvalidArgumentException('options_scope_invalid');
            }
        }
        ksort($fundingWallets, SORT_STRING);
        $this->fundingWallets = $fundingWallets;
    }

    public function authorizes(string $authenticatedUserId, string $routeUserId, DateTimeImmutable $asOf): bool
    {
        return $authenticatedUserId !== '' && $authenticatedUserId === $routeUserId && $routeUserId === $this->ownerUserId
            && $this->provider === 'nium' && $this->ownership === EvidenceState::Verified
            && $this->revocation === RevocationStatus::NotRevoked && $this->validFrom <= $asOf && $asOf < $this->expiresAt;
    }

    public function equals(self $other): bool
    {
        // Dates compare by instant, while all identity strings remain strictly equal.
        $left = get_object_vars($this);
        $right = get_object_vars($other);
        unset($left['validFrom'], $left['expiresAt'], $right['validFrom'], $right['expiresAt']);

        return $left === $right && $this->validFrom == $other->validFrom && $this->expiresAt == $other->expiresAt;
    }

    public function matches(CorridorIdentity $corridor): bool
    {
        return $this->provider === $corridor->provider && $this->environment === $corridor->environment
            && $this->client === $corridor->client && $this->legalEntity === $corridor->legalEntity
            && $this->customer === $corridor->customer && $this->account === $corridor->account
            && $this->customerType === $corridor->customerType && $this->profileVersion === $corridor->profileVersion
            && ($this->fundingWallets[$corridor->sourceCurrency] ?? null) === $corridor->fundingWallet;
    }
}
