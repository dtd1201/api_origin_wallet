<?php

namespace App\Services\Nium;

use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Internal accounting expectations only. Never implements NiumRemittancePricing. */
final class NiumProvisionalPayoutPricing
{
    public const POLICY_ID = 'transfer-3-provisional-pricing-v1';

    private const POLICY = [
        'id' => self::POLICY_ID, 'version' => 1,
        'provenance' => 'user_approved_scoped_provisional_nium_pricing_policy',
        'basis' => 'internal_provisional_assumptions',
        'provider_verified' => false, 'financial_authorization' => false,
        'intended_scope' => ['transfer_id' => 3, 'source_currency' => 'USD', 'target_currency' => 'SGD',
            'source_amount' => '5', 'payout_method' => 'LOCAL'],
        'expected' => ['customer_plan' => 'DEFAULT', 'fx_markup_percent' => '0.13',
            'fx_markup_treatment' => 'already_in_locked_rate', 'additional_fee_amount' => '0',
            'fee_currency' => 'USD', 'total_wallet_debit' => '5'],
        'uncertainty' => 'customer_assignment_complete_charges_and_wallet_allocation_unverified',
        'reconciliation_required' => true,
        'reconciliation_basis' => 'authenticated_wallet_activity_only_including_delayed_fees',
        'validity' => 'attached_fx_lock_only_fresh_confirmation_required_after_restart',
    ];

    /** Append metadata after explicit authenticated owner confirmation; never change transfer state. */
    public function record(Transfer $transfer, User $maker, int $fxQuoteId): Transfer
    {
        return DB::transaction(function () use ($transfer, $maker, $fxQuoteId): Transfer {
            $locked = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($locked->id !== 3 || $locked->user_id !== $maker->id || $maker->status !== 'active'
                || ($locked->raw_data['sgd_local_workflow']['maker_user_id'] ?? null) !== $maker->id
                || $locked->status !== 'draft' || $locked->provider_operation_key !== null
                || filled($locked->external_transfer_id) || $locked->fx_quote_id !== $fxQuoteId
                || ($locked->raw_data['sgd_local_workflow']['state'] ?? null) !== 'locked'
                || NiumPayoutFxLockContract::decimal($locked->source_amount) !== '5'
                || LedgerEntry::query()->where('source_type', 'transfer')->where('source_id', '3')->exists()) {
                throw new RuntimeException('Provisional expectations require the unchanged, owned Transfer 3 draft and its current FX lock.');
            }
            // Validates NIUM/provider/beneficiary ownership, original intent and the current FX contract.
            // This snapshot does not call financial admission or interpret pricing as verified.
            $scope = app(NiumSgdLocalPayoutAuthorization::class)->snapshot($locked);
            $raw = $locked->raw_data;
            $history = $raw['nium_provisional_pricing_history'] ?? [];
            if (! is_array($history) || ! array_is_list($history)) {
                throw new RuntimeException('Provisional pricing history is invalid.');
            }
            foreach ($history as $entry) {
                $this->verify($entry);
            }
            $previous = $history === [] ? null : $history[array_key_last($history)];
            if ($previous !== null && NiumPayoutFxLockContract::sameBinding($previous['scope'], $scope)) {
                return $locked; // Repeating the same confirmation never rewrites history.
            }
            $record = ['policy' => self::POLICY, 'transfer_id' => $locked->id, 'scope' => $scope,
                'approval' => ['user_id' => $maker->id, 'confirmed_at' => now()->toISOString(),
                    'basis' => 'authenticated_owner_explicit_confirmation_of_accounting_expectations_only'],
                'expires_at' => $scope['expires_at']];
            $record['seal'] = $this->seal($record);
            $raw['nium_provisional_pricing_history'][] = $record;
            $locked->update(['raw_data' => $raw]);

            return $locked;
        });
    }

    public function presentation(Transfer $transfer): ?array
    {
        if ($transfer->id !== 3) {
            return null;
        }
        $history = $transfer->raw_data['nium_provisional_pricing_history'] ?? [];
        if ($history === []) {
            return null;
        }
        try {
            if (! is_array($history) || ! array_is_list($history)) {
                throw new RuntimeException('Invalid history.');
            }
            foreach ($history as $entry) {
                $this->verify($entry);
            }
            $record = $history[array_key_last($history)];
            $status = 'binding_mismatch';
            if (Carbon::parse($record['expires_at'])->lessThanOrEqualTo(now())) {
                $status = 'expired';
            } else {
                try {
                    $snapshot = app(NiumSgdLocalPayoutAuthorization::class)->snapshot($transfer);
                    if (NiumPayoutFxLockContract::sameBinding($record['scope'], $snapshot)) {
                        $status = 'current';
                    }
                } catch (Throwable) {
                    // Changed/consumed/provider-invalid locks cannot make an expectation current.
                }
            }

            return [...self::POLICY, 'status' => $status, 'approval' => $record['approval'],
                'expires_at' => $record['expires_at'], 'comparison' => $this->comparison($transfer, $record)];
        } catch (Throwable) {
            return ['id' => self::POLICY_ID, 'status' => 'invalid', 'provider_verified' => false,
                'financial_authorization' => false, 'reconciliation_required' => true];
        }
    }

    private function comparison(Transfer $transfer, array $record): array
    {
        $waiting = ['status' => 'awaiting_authenticated_actual_debit'];
        $approved = $transfer->raw_data['sgd_local_approval'] ?? [];
        // Historical comparisons use the original dispatched review even after FX/policy expiry.
        if (! NiumPayoutFxLockContract::sameBinding($record['scope'], array_intersect_key($approved, $record['scope']))) {
            return $transfer->provider_operation_key === null ? $waiting : ['status' => 'dispatched_review_mismatch'];
        }
        $actual = $transfer->raw_data['nium_actual_debit'] ?? [];
        if (! in_array($actual['state'] ?? null, ['principal_reconciled', 'unexpected_charges_reconciled'], true)
            || ($actual['currency'] ?? null) !== 'USD') {
            return $waiting;
        }
        try {
            foreach (['principal_debit', 'observed_charge_debit', 'observed_total_debit'] as $key) {
                if (! is_string($actual[$key] ?? null)) {
                    return $waiting;
                }
                NiumPayoutFxLockContract::decimal($actual[$key]);
            }
        } catch (Throwable) {
            return ['status' => 'actual_debit_inconsistent'];
        }
        $fees = BigDecimal::of($actual['observed_charge_debit']);
        $total = BigDecimal::of($actual['observed_total_debit']);
        if (! BigDecimal::of($actual['principal_debit'])->isEqualTo('5')
            || ! $total->isEqualTo(BigDecimal::of($actual['principal_debit'])->plus($fees))) {
            return ['status' => 'actual_debit_inconsistent'];
        }

        return ['status' => $fees->isZero() && $total->isEqualTo('5') ? 'observed_match' : 'observed_discrepancy',
            'currency' => 'USD', 'observed_fee_amount' => NiumPayoutFxLockContract::decimal((string) $fees),
            'observed_wallet_debit' => NiumPayoutFxLockContract::decimal((string) $total),
            'fee_difference' => NiumPayoutFxLockContract::decimal((string) $fees->minus(self::POLICY['expected']['additional_fee_amount'])),
            'wallet_debit_difference' => NiumPayoutFxLockContract::decimal((string) $total->minus(self::POLICY['expected']['total_wallet_debit'])),
            'charge_completeness' => 'not_proven_by_absence_of_fee_rows'];
    }

    private function verify(mixed $record): void
    {
        if (! is_array($record) || ! is_string($record['seal'] ?? null)) {
            throw new RuntimeException('Invalid provisional record.');
        }
        $seal = $record['seal'];
        unset($record['seal']);
        if (! hash_equals($seal, $this->seal($record))
            || ! NiumPayoutFxLockContract::sameBinding($record['policy'] ?? null, self::POLICY)
            || ($record['transfer_id'] ?? null) !== 3
            || ($record['approval']['user_id'] ?? null) !== ($record['scope']['user_id'] ?? null)
            || ($record['expires_at'] ?? null) !== ($record['scope']['expires_at'] ?? null)) {
            throw new RuntimeException('Provisional record integrity or scope changed.');
        }
    }

    /** Local integrity seal, never a NIUM signature. Canonicalize JSON object key order. */
    private function seal(array $record): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Provisional recording requires the application key.');
        }

        return hash_hmac('sha256', json_encode($this->canonical($record), JSON_THROW_ON_ERROR), $key);
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }
}
