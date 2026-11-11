<?php

namespace App\Services\Nium;

use App\Models\Transfer;
use RuntimeException;
use Throwable;

/** Standard SGD uses only scoped business confirmation; no pricing or pilot fallback. */
final class NiumPayoutFinancialControls
{
    public const BLOCK_REASON = 'NIUM remittance is blocked because the exact total wallet debit is unverified: effective customer pricing, all applicable charges and source-wallet debit allocation must establish a debit matching the principal-only funding policy.';

    public function __construct(private readonly NiumPayoutFxLockService $locks) {}

    public function intent(Transfer $transfer): array
    {
        $transfer->loadMissing(['provider', 'user', 'beneficiary']);
        if ($transfer->provider === null || $transfer->user === null || $transfer->beneficiary === null
            || strtolower((string) $transfer->provider->code) !== 'nium'
            || $transfer->beneficiary->user_id !== $transfer->user_id
            || $transfer->beneficiary->provider_id !== $transfer->provider_id) {
            throw new RuntimeException(self::BLOCK_REASON);
        }

        return ['user_id' => $transfer->user_id, 'provider_id' => $transfer->provider_id,
            'provider_context' => $this->locks->context($transfer->provider, $transfer->user),
            'beneficiary_id' => $transfer->beneficiary_id,
            'external_beneficiary_id' => $transfer->beneficiary->external_beneficiary_id,
            'source_currency' => $transfer->source_currency, 'target_currency' => $transfer->target_currency,
            'source_amount' => NiumPayoutFxLockContract::decimal($transfer->source_amount),
            'payout_method' => $transfer->beneficiary->payout_method, 'purpose_code' => $transfer->purpose_code];
    }

    /** Application policy only: never evidence of provider fees or actual debit. */
    public function businessPolicy(Transfer $transfer): array
    {
        $principal = NiumSgdLocalCugScope::principal($transfer->source_amount);

        return ['basis' => 'internal_business_policy', 'currency' => $transfer->source_currency,
            'reservation_amount' => $principal, 'expected_principal_debit' => $principal,
            'expected_fee_amount' => '0'];
    }

    public function assertFor(Transfer $transfer): array
    {
        try {
            $intent = $this->intent($transfer);
            if ($transfer->source_currency === 'USD' && $transfer->target_currency === 'SGD'
                && config('services.nium.sgd_local_zero_fee.enabled', false) !== false) {
                // This is a separate authorization basis, not a confirmed provider quote.
                // Malformed/expired activation cannot fall back to another admission mechanism.
                return [...app(NiumSgdLocalZeroFeePolicy::class)->terms($transfer, $intent),
                    'business_policy' => $this->businessPolicy($transfer)];
            }
            throw new RuntimeException(self::BLOCK_REASON);
        } catch (Throwable) {
            throw new RuntimeException(self::BLOCK_REASON);
        }
    }
}
