<?php

namespace App\Services\Nium;

use App\Models\Transfer;
use Illuminate\Support\Carbon;
use RuntimeException;

/** Operator-activated business terms, never a provider pricing response or financial evidence. */
final class NiumSgdLocalZeroFeePolicy
{
    public const BASIS = 'business_owner_confirmation';

    public const TERMS = [
        'source_currency' => 'USD', 'target_currency' => 'SGD',
        'destination_country' => 'SG', 'payout_method' => 'LOCAL',
        'nium_fee_amount' => '0', 'origin_fee_amount' => '0',
        'additional_debit_amount' => '0', 'fee_currency' => 'USD',
        'total_wallet_debit_basis' => 'exact_source_principal',
    ];

    /** Local authorization only. Existing receipt/approval/hold snapshots retain this record. */
    public function terms(Transfer $transfer, array $intent): array
    {
        $policy = config('services.nium.sgd_local_zero_fee');
        if (! is_array($policy) || ($policy['enabled'] ?? null) !== true
            || ! NiumPayoutFxLockContract::sameBinding($policy['terms'] ?? null, self::TERMS)) {
            throw new RuntimeException('The SGD zero-fee policy is disabled or invalid.');
        }
        app(NiumSgdLocalCugScope::class)->assertTransfer($transfer);
        $context = [];
        foreach (['base_url', 'client', 'customer', 'wallet'] as $field) {
            $context[$field] = $this->text($policy['provider_context'][$field] ?? null);
        }
        if (! NiumPayoutFxLockContract::sameBinding($context, $intent['provider_context'] ?? null)) {
            throw new RuntimeException('The SGD zero-fee policy does not cover this provider wallet.');
        }
        $confirmedAt = NiumPayoutFxLockContract::expiry($policy['confirmed_at'] ?? null);
        $effectiveFrom = NiumPayoutFxLockContract::expiry($policy['effective_from'] ?? null);
        $expiresAt = NiumPayoutFxLockContract::expiry($policy['expires_at'] ?? null);
        if (Carbon::parse($confirmedAt)->greaterThan(Carbon::parse($effectiveFrom))
            || Carbon::parse($effectiveFrom)->greaterThan(now())
            || Carbon::parse($expiresAt)->lessThanOrEqualTo(now())
            || Carbon::parse($expiresAt)->lessThanOrEqualTo(Carbon::parse($effectiveFrom))) {
            throw new RuntimeException('The SGD zero-fee confirmation is not currently effective.');
        }
        $record = [
            'basis' => self::BASIS, 'version' => $this->text($policy['version'] ?? null),
            'confirmation_reference' => $this->text($policy['confirmation_reference'] ?? null),
            'confirmed_by' => $this->text($policy['confirmed_by'] ?? null),
            'confirmed_at' => $confirmedAt, 'effective_from' => $effectiveFrom, 'expires_at' => $expiresAt,
            'provider_context' => $context, 'terms' => self::TERMS,
        ];
        $principal = NiumSgdLocalCugScope::principal($transfer->source_amount);

        return [
            'admission_basis' => self::BASIS,
            'business_confirmation' => $record,
            // Every confirmation/scope/period edit changes the reviewed terms, even without a version bump.
            'pricing_version' => 'business-zero-fee:'.hash('sha256', json_encode($record, JSON_THROW_ON_ERROR)),
            'expires_at' => $expiresAt,
            'intent_sha256' => hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR)),
            'fee_amount' => '0', 'fee_currency' => 'USD', 'total_wallet_debit' => $principal,
        ];
    }

    private function text(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '' || trim($value) !== $value
            || strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new RuntimeException('The SGD zero-fee policy requires a complete business confirmation and scope.');
        }

        return $value;
    }
}
