<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use App\Domain\Nium\DynamicMultiCurrency\EvidenceState;
use App\Domain\Nium\DynamicMultiCurrency\Options\BeneficiarySnapshot;
use App\Domain\Nium\DynamicMultiCurrency\Registry\RevocationStatus;
use DateTimeImmutable;
use InvalidArgumentException;

/** Server-owned projections only. These values do not authenticate their own provenance. */
final readonly class PayoutQuoteContext
{
    public function __construct(
        public CorridorIdentity $corridor,
        public BeneficiarySnapshot $beneficiary,
        public string $providerId,
        public string $providerAccountId,
        public string $beneficiaryId,
        public string $externalBeneficiaryId,
        public string $receivingAccountFingerprint,
        public ?string $providerOrigin = null,
    ) {
        foreach ([$providerId, $providerAccountId, $beneficiaryId, $externalBeneficiaryId] as $id) {
            if ($id === '' || strlen($id) > 255 || trim($id) !== $id || preg_match('/[\x00-\x1F\x7F]/', $id)) {
                throw new InvalidArgumentException('payout_identity_invalid');
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $receivingAccountFingerprint) !== 1) {
            throw new InvalidArgumentException('payout_receiving_account_binding_invalid');
        }
        if ($providerOrigin !== null && (filter_var($providerOrigin, FILTER_VALIDATE_URL) === false
            || parse_url($providerOrigin, PHP_URL_SCHEME) !== 'https')) {
            throw new InvalidArgumentException('payout_provider_origin_invalid');
        }
    }

    public function assertUsable(string $actor, DateTimeImmutable $asOf): void
    {
        $c = $this->corridor;
        $b = $this->beneficiary;
        if (! $b->scope->authorizes($actor, $actor, $asOf) || ! $b->scope->matches($c)
            || ! $b->registered || $b->state !== EvidenceState::Verified
            || $b->revocation !== RevocationStatus::NotRevoked
            || $b->observedAt > $asOf || $b->expiresAt <= $asOf || $b->expiresAt <= $b->observedAt
            || $b->destinationCountry !== $c->destinationCountry || $b->destinationCurrency !== $c->destinationCurrency
            || $b->payoutMethod !== $c->payoutMethod || $b->beneficiaryType !== $c->beneficiaryType) {
            throw new InvalidArgumentException('payout_context_unavailable');
        }
    }

    public function snapshot(): array
    {
        $b = $this->beneficiary;

        return [
            ...($this->providerOrigin === null ? [] : ['provider_origin' => $this->providerOrigin]),
            'corridor' => get_object_vars($this->corridor), 'owner' => $b->scope->ownerUserId,
            'provider_id' => $this->providerId, 'provider_account_id' => $this->providerAccountId,
            'beneficiary_id' => $this->beneficiaryId, 'external_beneficiary_id' => $this->externalBeneficiaryId,
            'receiving_account_sha256' => $this->receivingAccountFingerprint,
            'ownership_valid_from' => $b->scope->validFrom->format('Y-m-d\TH:i:s.uP'),
            'ownership_expires_at' => $b->scope->expiresAt->format('Y-m-d\TH:i:s.uP'),
            'beneficiary_observed_at' => $b->observedAt->format('Y-m-d\TH:i:s.uP'),
            'beneficiary_expires_at' => $b->expiresAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->snapshot(), JSON_THROW_ON_ERROR));
    }
}
