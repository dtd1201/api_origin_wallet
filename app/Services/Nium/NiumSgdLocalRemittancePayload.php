<?php

namespace App\Services\Nium;

use App\Models\Transfer;
use Brick\Math\BigDecimal;
use RuntimeException;

/** One registered-beneficiary contract for review, dispatch and captured recovery. */
final class NiumSgdLocalRemittancePayload
{
    public function __construct(private readonly NiumSgdLocalPayoutAuthorization $authorization) {}

    /** Refuse a JSON numeric representation that silently changes the approved decimal. */
    public static function wireAmount(mixed $value): int|float
    {
        $amount = NiumPayoutFxLockContract::decimal($value);
        $number = str_contains($amount, '.') ? (float) $amount : (int) $amount;
        $json = json_encode($number, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (! BigDecimal::of($json)->isEqualTo($amount) || $amount === '0') {
            throw new RuntimeException('The source principal cannot be represented exactly in the NIUM request.');
        }

        return $number;
    }

    public static function fromSnapshot(array $snapshot): array
    {
        $id = $snapshot['external_beneficiary_id'] ?? null;
        $purpose = $snapshot['purpose_code'] ?? null;
        if (! is_string($id) || trim($id) === '' || ! is_string($purpose) || trim($purpose) === ''
            || ($snapshot['source_currency'] ?? null) !== 'USD' || ($snapshot['destination_currency'] ?? null) !== 'SGD'
            || ! NiumPayoutAuditId::isValid($snapshot['audit_id'] ?? null)) {
            throw new RuntimeException('A complete approved registered LOCAL intent is required.');
        }

        return ['beneficiary' => ['id' => $id],
            'payout' => ['sourceAmount' => self::wireAmount($snapshot['source_amount'] ?? null),
                'sourceCurrency' => $snapshot['source_currency'], 'auditId' => (int) $snapshot['audit_id']],
            'purposeCode' => $purpose, 'sourceOfFunds' => NiumTransferPolicy::SOURCE_OF_FUNDS];
    }

    public function providerPayload(Transfer $transfer, array $supportedPurposes): array
    {
        $this->authorization->assertApproved($transfer);
        $this->assertPurpose($transfer, $supportedPurposes);

        return self::fromSnapshot($transfer->raw_data['sgd_local_approval']);
    }

    /** Review only; never POST, reserve funds, create a lock or grant admission. */
    public function forReview(Transfer $transfer, array $supportedPurposes): array
    {
        $snapshot = $this->authorization->snapshot($transfer);
        $this->assertPurpose($transfer, $supportedPurposes);

        return self::fromSnapshot($snapshot);
    }

    private function assertPurpose(Transfer $transfer, array $supportedPurposes): void
    {
        if (! collect($supportedPurposes)->contains('code', $transfer->purpose_code)) {
            throw new RuntimeException('Select an eligible supported NIUM payment purpose.');
        }
    }
}
