<?php

namespace App\Services\Nium;

use App\Contracts\NiumRemittancePricing;
use App\Models\NiumPayoutPilotAuthorization;
use App\Models\Transfer;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/** Transaction-level authorization from a verified provider pricing adapter; no release files. */
final class NiumPayoutFinancialControls
{
    public const BLOCK_REASON = 'NIUM remittance is blocked because the exact total wallet debit is unverified: effective customer pricing, all applicable charges and source-wallet debit allocation must establish a debit matching the principal-only funding policy.';

    public function __construct(private readonly NiumRemittancePricing $pricing, private readonly NiumPayoutFxLockService $locks) {}

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
            if (config('services.nium.payout_pilot.enabled', false) === true
                && NiumPayoutPilotAuthorization::query()
                    ->whereKey(config('services.nium.payout_pilot.authorization_id'))->where('transfer_id', $transfer->id)->exists()) {
                return app(NiumPayoutPilotPolicy::class)->terms($transfer);
            }
            $quote = $this->pricing->quote($transfer);
            $fee = $this->money($quote['fee_amount'] ?? null);
            $total = $this->money($quote['total_wallet_debit'] ?? null);
            if (($quote['status'] ?? null) !== 'confirmed'
                || ! NiumPayoutFxLockContract::sameBinding($quote['intent'] ?? null, $intent)
                || ! is_string($quote['pricing_version'] ?? null) || trim($quote['pricing_version']) === ''
                || Carbon::parse(NiumPayoutFxLockContract::expiry($quote['expires_at'] ?? null))->lessThanOrEqualTo(now())
                || ($quote['charges_complete'] ?? null) !== true
                || ($quote['debit_basis'] ?? null) !== 'principal_plus_source_wallet_fees'
                || ($quote['fee_currency'] ?? null) !== $intent['source_currency']
                || ! BigDecimal::of($intent['source_amount'])->isGreaterThan('0')
                || ! BigDecimal::of($total)->isEqualTo(BigDecimal::of($intent['source_amount'])->plus($fee))
                // The CUG policy funds principal only; it cannot absorb an extra source-wallet charge.
                || ! BigDecimal::of($fee)->isZero()
                || ! BigDecimal::of($total)->isEqualTo($intent['source_amount'])) {
                throw new RuntimeException;
            }

            return ['pricing_version' => $quote['pricing_version'], 'expires_at' => $quote['expires_at'],
                'intent_sha256' => hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR)),
                'fee_amount' => $fee, 'fee_currency' => $quote['fee_currency'], 'total_wallet_debit' => $total,
                'business_policy' => $this->businessPolicy($transfer)];
        } catch (Throwable) {
            throw new RuntimeException(self::BLOCK_REASON);
        }
    }

    private function money(mixed $value): string
    {
        if (! is_string($value)) {
            throw new RuntimeException;
        }
        $amount = NiumPayoutFxLockContract::decimal($value);
        [$whole, $fraction] = array_pad(explode('.', $amount), 2, '');
        if (strlen($whole) > 12 || strlen($fraction) > 8) {
            throw new RuntimeException;
        }

        return $amount;
    }
}
