<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutConfirmation;
use DateTimeImmutable;
use RuntimeException;

/** Admission provenance around the EXISTING confirmation; no new financial model. */
final readonly class DynamicPayoutEvidence
{
    public function __construct(
        public PayoutConfirmation $review,
        public string $registeredReceivingAccountSha256,
        public string $quoteConsumptionRule,
        public array $versions,
        public DateTimeImmutable $checkedAt,
        public DateTimeImmutable $validUntil,
    ) {}

    public function assertCurrent(DateTimeImmutable $at): void
    {
        if ($this->review->synthetic || $this->review->confirmedAt !== null
            || $this->checkedAt != $at || $this->validUntil <= $at
            || preg_match('/^[a-f0-9]{64}$/D', $this->registeredReceivingAccountSha256) !== 1
            || ! in_array($this->quoteConsumptionRule, ['LOCAL_SINGLE_INTENT_PER_QUOTE', 'SINGLE_USE_PROVIDER_CLIENT_CUSTOMER'], true)) {
            throw new RuntimeException('dynamic_authoritative_admission_evidence_unavailable');
        }

        foreach (['fees', 'wallet_debit', 'quote_consumption', 'corridor', 'account_eligibility',
            'compliance', 'registered_receiving_account', 'wallet_availability'] as $key) {
            $version = $this->versions[$key] ?? null;
            if (! is_string($version) || $version === '' || trim($version) !== $version
                || strlen($version) > 255 || preg_match('/[\x00-\x1F\x7F]/', $version)) {
                throw new RuntimeException('dynamic_authoritative_admission_evidence_unavailable');
            }
        }
    }

    public function snapshot(): array
    {
        $versions = $this->versions;
        ksort($versions, SORT_STRING);

        return ['version' => 'nium-dynamic-admission-v1', 'versions' => $versions,
            'registered_receiving_account_sha256' => $this->registeredReceivingAccountSha256,
            'quote_consumption_rule' => $this->quoteConsumptionRule,
            'checked_at' => $this->checkedAt->format('Y-m-d\TH:i:s.uP'),
            'valid_until' => $this->validUntil->format('Y-m-d\TH:i:s.uP')];
    }
}
