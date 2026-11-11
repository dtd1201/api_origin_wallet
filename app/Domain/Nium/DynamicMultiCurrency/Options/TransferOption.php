<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Options;

use App\Domain\Nium\DynamicMultiCurrency\CorridorIdentity;
use InvalidArgumentException;
use JsonSerializable;

/** Public projection only. Phase 4A has no preparation/dispatch activation path. */
final readonly class TransferOption implements JsonSerializable
{
    private string $fundingCurrency;

    private string $destinationCountry;

    private string $destinationCurrency;

    private string $payoutMethod;

    private array $reasonCodes;

    public function __construct(
        CorridorIdentity $corridor,
        private string $beneficiaryCompatibility,
        private string $financialReadiness,
        string ...$reasonCodes,
    ) {
        if (! in_array($beneficiaryCompatibility, ['COMPATIBLE', 'INCOMPATIBLE', 'UNKNOWN'], true)
            || ! in_array($financialReadiness, ['VERIFIED', 'DENIED', 'UNKNOWN'], true)) {
            throw new InvalidArgumentException('options_status_invalid');
        }
        $this->fundingCurrency = $corridor->sourceCurrency;
        $this->destinationCountry = $corridor->destinationCountry;
        $this->destinationCurrency = $corridor->destinationCurrency;
        $this->payoutMethod = $corridor->payoutMethod;
        $this->reasonCodes = OptionsReason::publicCodes(...[...$reasonCodes,
            OptionsReason::PreparationClosed->value, OptionsReason::DispatchDisabled->value]);
    }

    public function toArray(): array
    {
        return [
            'funding_currency' => $this->fundingCurrency, 'destination_country' => $this->destinationCountry,
            'destination_currency' => $this->destinationCurrency, 'payout_method' => $this->payoutMethod,
            'beneficiary_compatibility' => $this->beneficiaryCompatibility,
            'preparation_eligibility' => 'DENIED', 'financial_readiness' => $this->financialReadiness,
            'dispatch_eligibility' => 'DENIED', 'enabled' => false, 'reason_codes' => $this->reasonCodes,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
