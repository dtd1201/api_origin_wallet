<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Options;

use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RevocationStatus;
use DateTimeImmutable;
use InvalidArgumentException;

/** Minimal server-owned projection. No beneficiary identifiers or bank details. */
final readonly class BeneficiarySnapshot
{
    public function __construct(
        public CustomerAccountScope $scope,
        public string $destinationCountry,
        public string $destinationCurrency,
        public string $payoutMethod,
        public string $beneficiaryType,
        public EvidenceState $state,
        public bool $registered,
        public DateTimeImmutable $observedAt,
        public DateTimeImmutable $expiresAt,
        public RevocationStatus $revocation,
    ) {
        if (preg_match('/^[A-Z]{2}$/D', $destinationCountry) !== 1 || preg_match('/^[A-Z]{3}$/D', $destinationCurrency) !== 1
            || preg_match('/^[A-Z][A-Z0-9_]*$/D', $payoutMethod) !== 1 || preg_match('/^[A-Z][A-Z0-9_]*$/D', $beneficiaryType) !== 1) {
            throw new InvalidArgumentException('options_beneficiary_invalid');
        }
    }
}
