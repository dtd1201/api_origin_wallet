<?php

declare(strict_types=1);

namespace App\Domain\Nium\DynamicMultiCurrency\Financial;

use InvalidArgumentException;

/** Server-validated remittance fields covered by the customer's financial fingerprint. */
final readonly class PayoutInstruction
{
    public function __construct(public string $purposeCode, public string $sourceOfFunds, public string $comment)
    {
        foreach ([$purposeCode, $sourceOfFunds, $comment] as $value) {
            if ($value === '' || trim($value) !== $value || strlen($value) > 255
                || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new InvalidArgumentException('dynamic_payout_instruction_invalid');
            }
        }
    }

    public function snapshot(): array
    {
        return ['purposeCode' => $this->purposeCode, 'sourceOfFunds' => $this->sourceOfFunds,
            'customerComments' => $this->comment];
    }
}
