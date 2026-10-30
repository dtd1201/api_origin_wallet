<?php

namespace App\Services\Nium;

use App\Models\Beneficiary;
use App\Models\Transfer;
use RuntimeException;

/** Registered LOCAL ownership and corridor checks; legacy name retained for compatibility. */
final class NiumSgdLocalCugScope
{
    // Persisted workflow identifier, not a customer/amount admission policy.
    public const RELEASE = 'phase43-usd5-sgd-local';

    public function __construct(
        private readonly NiumProviderAccountStateService $accounts,
        private readonly NiumSgdLocalPayoutGate $gate,
    ) {}

    public static function principal(mixed $value): string
    {
        $amount = NiumPayoutFxLockContract::decimal($value);
        [$whole, $fraction] = array_pad(explode('.', $amount), 2, '');
        if ($amount === '0' || strlen($whole) > 12 || strlen($fraction) > 8) {
            throw new RuntimeException('A positive source amount within wallet precision is required.');
        }
        NiumSgdLocalRemittancePayload::wireAmount($amount);

        return $amount;
    }

    /** The reviewed evidence must bind these actual identities and financial intent. */
    public function assertTransfer(Transfer $transfer): array
    {
        $transfer->loadMissing(['user', 'provider', 'beneficiary']);
        if ($transfer->user === null || $transfer->provider === null || $transfer->beneficiary === null
            || $transfer->user->id !== $transfer->user_id || $transfer->provider->id !== $transfer->provider_id
            || $transfer->beneficiary->id !== $transfer->beneficiary_id
            || $transfer->beneficiary->user_id !== $transfer->user_id
            || $transfer->beneficiary->provider_id !== $transfer->provider_id
            || $transfer->transfer_type !== 'payout' || $transfer->source_currency !== 'USD'
            || $transfer->target_currency !== 'SGD' || $transfer->source_bank_account_id !== null
            || $transfer->target_amount !== null) {
            throw new RuntimeException('Registered SGD LOCAL requires an owned beneficiary and a source-funded USD payout.');
        }
        $amount = self::principal($transfer->source_amount);
        $transfer->provider->assertSupportsCapability('transfer');
        $account = $this->accounts->assertEligible($transfer->user);
        if ($account->provider_id !== $transfer->provider_id || $account->user_id !== $transfer->user_id) {
            throw new RuntimeException('The verified provider account does not belong to this payout customer and provider.');
        }
        $this->gate->assertLocalCandidate($transfer->user, $transfer->provider, $transfer->beneficiary, $transfer->target_currency);

        return ['user_id' => $transfer->user_id, 'provider_id' => $transfer->provider_id,
            'account_id' => $account->id, 'beneficiary_id' => $transfer->beneficiary_id,
            'source_currency' => $transfer->source_currency, 'target_currency' => $transfer->target_currency,
            'source_amount' => $amount, 'payout_method' => 'LOCAL', 'purpose_code' => $transfer->purpose_code];
    }

    public function canPrepare(Beneficiary $beneficiary): bool
    {
        try {
            $beneficiary->loadMissing(['user', 'provider']);
            if ($beneficiary->user === null || $beneficiary->provider === null) {
                return false;
            }
            $beneficiary->provider->assertSupportsCapability('transfer');
            $this->gate->assertLocalCandidate($beneficiary->user, $beneficiary->provider, $beneficiary, $beneficiary->currency);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
