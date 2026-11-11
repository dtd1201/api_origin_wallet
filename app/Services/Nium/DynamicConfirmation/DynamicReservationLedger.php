<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Domain\Nium\DynamicMultiCurrency\Financial\Decimal;
use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicReservation;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Uses the shared Balance and LedgerEntry primitives without constructing a Transfer. */
final class DynamicReservationLedger
{
    public function reserve(NiumDynamicIntent $intent, Balance $wallet, string $reservationId): LedgerEntry
    {
        $this->assertTransaction();
        $wallet = Balance::query()->lockForUpdate()->findOrFail($wallet->id);
        if ((string) $wallet->id !== (string) $intent->balance_id
            || (string) $wallet->user_id !== (string) $intent->user_id
            || (string) $wallet->provider_id !== (string) $intent->provider_id
            || $wallet->currency !== $intent->source_currency || $wallet->external_account_id !== $intent->source_wallet) {
            throw new RuntimeException('dynamic_reservation_wallet_ownership_unverified');
        }
        $amount = self::storage((string) $intent->total_debit, true);
        $reserved = BigDecimal::of(self::storage((string) $wallet->reserved_balance));
        $available = $this->available($wallet, $reserved);

        if ($available->isLessThan($amount)) {
            throw new RuntimeException('dynamic_insufficient_available_funds');
        }

        $after = self::storage((string) $available->minus($amount));
        $newReserved = self::storage((string) $reserved->plus($amount));
        if (! $wallet->update(['available_balance' => $after, 'reserved_balance' => $newReserved])) {
            throw new RuntimeException('dynamic_wallet_reservation_not_persisted');
        }

        return $this->entry($intent, $wallet, $reservationId, 'hold',
            (string) BigDecimal::of($amount)->negated()->toScale(8), $after);
    }

    public function release(NiumDynamicIntent $intent, NiumDynamicReservation $reservation, Balance $wallet): LedgerEntry
    {
        $this->assertTransaction();
        $wallet = Balance::query()->lockForUpdate()->findOrFail($wallet->id);
        $amount = self::storage((string) $intent->total_debit, true);
        $hold = LedgerEntry::query()->lockForUpdate()->findOrFail($reservation->hold_entry_id);

        if ($reservation->state !== 'held' || $reservation->intent_id !== $intent->id
            || (string) $reservation->balance_id !== (string) $wallet->id
            || (string) $intent->balance_id !== (string) $wallet->id
            || (string) $wallet->user_id !== (string) $intent->user_id
            || (string) $wallet->provider_id !== (string) $intent->provider_id
            || $wallet->external_account_id !== $intent->source_wallet
            || $reservation->currency !== $intent->source_currency || $wallet->currency !== $intent->source_currency
            || self::storage((string) $reservation->amount) !== $amount
            || $hold->reference !== 'dynamic:'.$reservation->id.':hold'
            || $hold->source_type !== 'nium_dynamic_reservation' || $hold->source_id !== $reservation->id
            || (string) $hold->balance_id !== (string) $wallet->id || $hold->currency !== $wallet->currency
            || (string) $hold->user_id !== (string) $intent->user_id
            || (string) $hold->provider_id !== (string) $intent->provider_id
            || $hold->status !== 'posted' || $hold->entry_type !== 'hold'
            || ! BigDecimal::of((string) $hold->amount)->isEqualTo(BigDecimal::of($amount)->negated())
            || ($hold->raw_data['intent_id'] ?? null) !== $intent->id
            || ($hold->raw_data['terms_fingerprint'] ?? null) !== $intent->terms_fingerprint
            || LedgerEntry::query()->where('source_type', 'nium_dynamic_reservation')
                ->where('source_id', $reservation->id)->where('id', '!=', $hold->id)->exists()) {
            throw new RuntimeException('dynamic_reservation_release_ownership_unverified');
        }

        $reserved = BigDecimal::of(self::storage((string) $wallet->reserved_balance));
        if ($reserved->isLessThan($amount)) {
            throw new RuntimeException('dynamic_reservation_balance_inconsistent');
        }

        $remaining = $reserved->minus($amount);
        // Provider sync may have reset available while retaining local reserves.
        // Never add a release on top of an already reset provider balance.
        $after = BigDecimal::of(self::storage((string) $wallet->available_balance))->plus($amount);
        $ceiling = $this->providerAvailable($wallet)->minus($remaining);
        if ($ceiling->isNegative()) {
            throw new RuntimeException('dynamic_reservation_balance_inconsistent');
        }
        $after = $after->isGreaterThan($ceiling) ? $ceiling : $after;
        if (! $wallet->update(['available_balance' => self::storage((string) $after),
            'reserved_balance' => self::storage((string) $remaining)])) {
            throw new RuntimeException('dynamic_wallet_release_not_persisted');
        }

        return $this->entry($intent, $wallet, $reservation->id, 'release', $amount, self::storage((string) $after));
    }

    public static function storage(string $amount, bool $positive = false): string
    {
        $decimal = new Decimal($amount);
        if (! $decimal->fitsScale(8) || strlen(explode('.', $decimal->value)[0]) > 12
            || ($positive && $decimal->isZero())) {
            throw new RuntimeException('dynamic_amount_not_exactly_representable_in_wallet_ledger');
        }

        return (string) BigDecimal::of($decimal->value)->toScale(8);
    }

    /** Consume exactly the accepted customer debit once after correlated settled provider accounting. */
    public function consume(NiumDynamicIntent $intent, NiumDynamicReservation $reservation, Balance $wallet): void
    {
        $this->assertTransaction();
        $wallet = Balance::query()->lockForUpdate()->findOrFail($wallet->id);
        if ($reservation->state === 'consumed' && $reservation->debit_entry_id !== null) {
            return;
        }
        $hold = LedgerEntry::query()->lockForUpdate()->findOrFail($reservation->hold_entry_id);
        $amount = BigDecimal::of(self::storage((string) $intent->total_debit, true));
        $reserved = BigDecimal::of(self::storage((string) $wallet->reserved_balance));
        if ($reservation->state !== 'held' || $reservation->intent_id !== $intent->id
            || $reservation->release_entry_id !== null || $reservation->debit_entry_id !== null
            || (string) $wallet->id !== (string) $intent->balance_id
            || (string) $reservation->balance_id !== (string) $wallet->id
            || (string) $wallet->user_id !== (string) $intent->user_id
            || (string) $wallet->provider_id !== (string) $intent->provider_id
            || $wallet->external_account_id !== $intent->source_wallet || $wallet->currency !== 'USD'
            || $reservation->currency !== 'USD' || ! $amount->isEqualTo($reservation->amount)
            || $reserved->isLessThan($amount) || $hold->source_id !== $reservation->id
            || $hold->source_type !== 'nium_dynamic_reservation' || $hold->entry_type !== 'hold'
            || $hold->reference !== 'dynamic:'.$reservation->id.':hold'
            || ! BigDecimal::of($hold->amount)->negated()->isEqualTo($amount)
            || ($hold->raw_data['terms_fingerprint'] ?? null) !== $intent->terms_fingerprint
            || LedgerEntry::query()->where('source_type', 'nium_dynamic_reservation')
                ->where('source_id', $reservation->id)->where('id', '!=', $hold->id)->exists()) {
            throw new RuntimeException('dynamic_settlement_ownership_or_reservation_unverified');
        }
        $remaining = $reserved->minus($amount);
        $ledger = BigDecimal::of(self::storage((string) $wallet->ledger_balance))->minus($amount);
        if ($ledger->isNegative()) {
            throw new RuntimeException('dynamic_settlement_balance_inconsistent');
        }
        $available = BigDecimal::of(self::storage((string) $wallet->available_balance));
        $ceiling = $ledger->minus($remaining);
        if ($ceiling->isNegative()) {
            throw new RuntimeException('dynamic_settlement_balance_inconsistent');
        }
        $available = $available->isGreaterThan($ceiling) ? $ceiling : $available;
        DynamicPayoutDispatcher::persist($wallet->forceFill(['reserved_balance' => self::storage((string) $remaining),
            'ledger_balance' => self::storage((string) $ledger), 'available_balance' => self::storage((string) $available)]));
        $entry = $this->entry($intent, $wallet, $reservation->id, 'debit',
            (string) $amount->negated()->toScale(8), self::storage((string) $ledger));
        if (! $entry->exists) {
            throw new RuntimeException('dynamic_settlement_debit_not_persisted');
        }
        DynamicPayoutDispatcher::persist($reservation->forceFill(['state' => 'consumed', 'debit_entry_id' => $entry->id]));
    }

    private function available(Balance $wallet, BigDecimal $reserved): BigDecimal
    {
        $available = BigDecimal::of(self::storage((string) $wallet->available_balance));
        $providerSpendable = $this->providerAvailable($wallet)->minus($reserved);

        return $available->isLessThan($providerSpendable) ? $available : $providerSpendable;
    }

    private function providerAvailable(Balance $wallet): BigDecimal
    {
        $amount = $wallet->raw_data['provider_available_balance'] ?? null;
        if (! is_string($amount)) {
            throw new RuntimeException('dynamic_authoritative_wallet_balance_unavailable');
        }

        return BigDecimal::of(self::storage($amount));
    }

    private function entry(NiumDynamicIntent $intent, Balance $wallet, string $reservationId,
        string $type, string $amount, string $after): LedgerEntry
    {
        $entry = LedgerEntry::query()->create([
            'balance_id' => $wallet->id, 'user_id' => $intent->user_id, 'provider_id' => $intent->provider_id,
            'reference' => 'dynamic:'.$reservationId.':'.$type, 'entry_type' => $type, 'status' => 'posted',
            'currency' => $wallet->currency, 'amount' => $amount, 'balance_after' => $after,
            'source_type' => 'nium_dynamic_reservation', 'source_id' => $reservationId,
            'description' => $type === 'debit' ? 'Immutable customer-authorized dynamic payout debit.'
                : 'Dynamic payout reservation '.$type.'.',
            'posted_at' => now(), 'raw_data' => ['intent_id' => $intent->id, 'terms_fingerprint' => $intent->terms_fingerprint],
        ]);
        if (! $entry->exists) {
            throw new RuntimeException('dynamic_reservation_ledger_entry_not_persisted');
        }

        return $entry;
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('dynamic_reservation_requires_confirmation_transaction');
        }
    }
}
