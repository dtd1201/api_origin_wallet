<?php

namespace App\Services\Nium;

use App\Models\Transfer;
use Brick\Math\BigDecimal;
use RuntimeException;

final class NiumSgdLocalPayoutAmounts
{
    public function __construct(private readonly NiumPayoutFxLockService $locks) {}

    /** Customer projection only: never infer fees, total debit or delivered credit. */
    public function forTransfer(Transfer $transfer): array
    {
        $amounts = [
            'source_currency' => $transfer->source_currency, 'destination_currency' => $transfer->target_currency,
            'source_amount' => $transfer->source_amount, 'total_wallet_debit' => null,
            'fx_rate' => null, 'fx_lock_expires_at' => null, 'conversion_amount' => null, 'transfer_fee' => null,
            'beneficiary_credit' => null,
            'estimated_conversion_amount' => null,
            'actual_debit' => null,
        ];
        if ($transfer->source_currency !== 'USD' || $transfer->target_currency !== 'SGD') {
            return $amounts;
        }
        $actual = $transfer->raw_data['nium_actual_debit'] ?? [];
        $amounts['actual_debit'] = array_intersect_key($actual, array_flip([
            'state', 'currency', 'principal_debit', 'observed_charge_debit', 'observed_total_debit', 'charge_completeness', 'checked_at',
            'pilot_charge_reserve_remaining', 'pilot_charge_ceiling_breached',
            'pilot_charge_exposure', 'pilot_additional_charges_observed',
        ])) ?: ($transfer->provider_operation_key !== null ? ['state' => 'awaiting_evidence'] : null);
        if (($transfer->raw_data['sgd_local_approval']['financial_terms']['policy'] ?? null) === NiumPayoutPilotPolicy::UNCAPPED_POLICY) {
            $amounts['actual_debit'] = array_merge($amounts['actual_debit'] ?? ['state' => 'awaiting_evidence'],
                ['pilot_charge_exposure' => 'uncapped']);
        }
        try {
            $terms = NiumSgdLocalPayoutGate::assertSubmissionAllowed($transfer);
            $amounts['transfer_fee'] = $terms['fee_amount'] === null ? null : ['amount' => $terms['fee_amount'], 'currency' => $terms['fee_currency']];
            $amounts['total_wallet_debit'] = $terms['total_wallet_debit'];
        } catch (RuntimeException) {
            // Business expectation and historical storage zeros are never provider evidence.
        }

        $transfer->loadMissing(['fxQuote', 'provider', 'user']);
        $quote = $transfer->fxQuote;
        if ($quote === null || $transfer->provider === null || $transfer->user === null) {
            return $amounts;
        }

        try {
            NiumPayoutFxLockContract::assertMatchesTransfer(
                $quote, $transfer, $this->locks->context($transfer->provider, $transfer->user),
            );
            $contract = $quote->raw_data['payout_fx_lock_contract'];
            $amounts['fx_rate'] = $contract['rate'];
            $amounts['fx_lock_expires_at'] = $contract['expires_at'];
            if (($transfer->raw_data['sgd_local_workflow']['version'] ?? null) === 1) {
                // Display-only estimate from NIUM's locked rate, never a conversion instruction or guaranteed credit.
                $amounts['estimated_conversion_amount'] = NiumPayoutFxLockContract::decimal(
                    (string) BigDecimal::of($contract['source_amount'])->multipliedBy($contract['rate']),
                );
            }
            // An explicit provider conversion amount is still not final beneficiary credit.
            $amounts['conversion_amount'] = isset($contract['conversion_amount'])
                ? NiumPayoutFxLockContract::decimal($contract['conversion_amount'])
                : null;
        } catch (RuntimeException) {
            // Invalid, stale, historical or incomplete lock evidence is unavailable.
        }

        return $amounts;
    }
}
