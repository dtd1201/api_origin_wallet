<?php

namespace App\Services\Nium;

use App\Models\Transfer;
use RuntimeException;

final class NiumSgdLocalPayoutAmounts
{
    public function __construct(private readonly NiumPayoutFxLockService $locks) {}

    /** Customer projection only: never infer fees, total debit or delivered credit. */
    public function forTransfer(Transfer $transfer): array
    {
        $amounts = [
            'source_currency' => 'USD', 'destination_currency' => 'SGD',
            'source_amount' => $transfer->source_amount, 'total_wallet_debit' => null,
            'fx_rate' => null, 'conversion_amount' => null, 'transfer_fee' => null,
            'fx_lock_expires_at' => null,
            'beneficiary_credit' => null,
            'estimated_conversion_amount' => null,
        ];
        if ($transfer->source_currency !== 'USD' || $transfer->target_currency !== 'SGD') {
            return $amounts;
        }

        $transfer->loadMissing(['fxQuote', 'provider', 'user']);
        $quote = $transfer->fxQuote;
        if ($quote === null || $transfer->provider === null || $transfer->user === null) {
            return $amounts;
        }

        try {
            $modern = ($quote->raw_data['provider_fx_type'] ?? null) === 'modern_quote';
            if ($modern) {
                // Historical terms may be shown with their expiry. This does not authorize dispatch.
                NiumPayoutQuoteContract::assertMatchesTransfer($quote, $transfer, $this->locks->context($transfer->provider, $transfer->user), false);
            } else {
                NiumPayoutFxLockContract::assertMatchesTransfer($quote, $transfer, $this->locks->context($transfer->provider, $transfer->user));
            }
            $contract = $quote->raw_data[$modern ? 'payout_quote_contract' : 'payout_fx_lock_contract'];
            $amounts['fx_rate'] = $contract['rate'];
            $amounts['fx_lock_expires_at'] = $quote->expires_at->toISOString();
            // An explicit provider conversion amount is still not final beneficiary credit.
            $amounts['conversion_amount'] = isset($contract['conversion_amount'])
                ? NiumPayoutFxLockContract::decimal($contract['conversion_amount'])
                : null;
            if ($modern) {
                $amounts['estimated_conversion_amount'] = bcmul((string) $transfer->source_amount, $contract['rate'], 18);
            }
            if (($transfer->raw_data['sgd_local_workflow']['version'] ?? null) === 1) {
                app(NiumSgdLocalCugScope::class)->assertTransfer($transfer);
                app(NiumSgdLocalPayoutWorkflow::class)->assertIntent($transfer);
                $terms = app(NiumPayoutFinancialControls::class)->assertFor($transfer);
                $amounts['transfer_fee'] = ['amount' => $terms['fee_amount'], 'currency' => $terms['fee_currency']];
                $amounts['total_wallet_debit'] = $terms['total_wallet_debit'];
            } else {
                // Read historical evidence without converting an old receipt into new authority.
                app(NiumTransferReviewService::class)->assertReview($transfer, false);
                $terms = $quote->raw_data['payment_review']['terms'];
                $amounts['transfer_fee'] = ['amount' => $terms['fee_amount'], 'currency' => $terms['currency']];
                $amounts['total_wallet_debit'] = $terms['total_wallet_debit'];
            }
        } catch (RuntimeException) {
            // Invalid, stale, historical or incomplete lock evidence is unavailable.
        }

        return $amounts;
    }
}
