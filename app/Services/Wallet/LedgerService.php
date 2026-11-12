<?php

namespace App\Services\Wallet;

use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Services\Nium\NiumBalanceOwnership;
use App\Services\Nium\NiumTransferReviewService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LedgerService
{
    private const SCALE = 8;

    public function reserveTransfer(Transfer $transfer): ?LedgerEntry
    {
        if (! $this->enabled()) {
            return null;
        }

        return OwnerFirst::transfer($transfer, function () use ($transfer): LedgerEntry {
            $transfer = $this->lockTransfer($transfer);
            $reference = $this->reference($transfer, 'hold');
            $entries = $this->lifecycleEntries($transfer);
            $this->assertReservable($transfer);

            if ($entries->has('hold')) {
                $this->assertHeldLifecycle($transfer, $entries, reservation: true);

                return $entries->get('hold');
            }
            $this->assertNoMissingHold($transfer);

            $balance = $this->lockTransferBalance($transfer, reservation: true);
            $amount = $this->positiveAmount($transfer);
            $this->assertBooks($balance);

            if ($this->compare($balance->available_balance, $amount) < 0) {
                throw new RuntimeException('Insufficient available balance for this transfer.');
            }

            if (NiumTransferReviewService::applies($transfer)) {
                $providerAvailable = $balance->raw_data['provider_available_balance'] ?? null;
                if (! is_numeric($providerAvailable)) {
                    throw new RuntimeException('Nium provider available balance is required before reserving this transfer.');
                }

                // Admission only: an observation must never rebase Origin books.
                $providerSpendable = $this->subtract($providerAvailable, $balance->reserved_balance);
                if ($this->compare($providerSpendable, $amount) < 0) {
                    throw new RuntimeException('Insufficient available balance for this transfer.');
                }
            }

            $newAvailable = $this->subtract($balance->available_balance, $amount);
            $newReserved = $this->add($balance->reserved_balance, $amount);

            if (! $balance->update([
                'available_balance' => $newAvailable,
                'reserved_balance' => $newReserved,
                'as_of' => now(),
                'raw_data' => $this->withLedgerMetadata($balance->raw_data, [
                    'last_hold_reference' => $reference,
                    'last_hold_transfer_id' => $transfer->id,
                ]),
            ])) {
                throw new RuntimeException('Transfer wallet book update was not persisted.');
            }

            $entry = $this->createEntry(
                transfer: $transfer,
                balance: $balance->fresh(),
                reference: $reference,
                entryType: 'hold',
                amount: $this->negative($amount),
                balanceAfter: $newAvailable,
                description: 'Transfer funds reserved before provider submission.',
            );

            $this->mergeTransferWalletData($transfer, [
                'balance_id' => $balance->id,
                'hold_reference' => $reference,
                'held_amount' => $amount,
                'held_at' => now()->toISOString(),
            ]);

            return $entry;
        });
    }

    public function settleTransfer(Transfer $transfer): ?LedgerEntry
    {
        if (! $this->enabled()) {
            return null;
        }

        return OwnerFirst::transfer($transfer, function () use ($transfer): LedgerEntry {
            $transfer = $this->lockTransfer($transfer);
            $reference = $this->reference($transfer, 'debit');

            $entries = $this->lifecycleEntries($transfer);
            if ($entries->has('release')) {
                throw new RuntimeException('A released transfer hold cannot be settled.');
            }
            [$balance, $amount] = $this->assertHoldIdentity($transfer, $entries);
            if ($entries->has('debit')) {
                return $entries->get('debit');
            }

            if ($this->compare($balance->reserved_balance, $amount) < 0) {
                throw new RuntimeException('Reserved balance is not sufficient to settle this transfer.');
            }

            $newReserved = $this->subtract($balance->reserved_balance, $amount);
            $newLedger = $this->subtract($balance->ledger_balance, $amount);

            if (! $balance->update([
                'ledger_balance' => $newLedger,
                'reserved_balance' => $newReserved,
                'as_of' => now(),
                'raw_data' => $this->withLedgerMetadata($balance->raw_data, [
                    'last_debit_reference' => $reference,
                    'last_debit_transfer_id' => $transfer->id,
                ]),
            ])) {
                throw new RuntimeException('Transfer wallet book update was not persisted.');
            }

            $entry = $this->createEntry(
                transfer: $transfer,
                balance: $balance->fresh(),
                reference: $reference,
                entryType: 'debit',
                amount: $this->negative($amount),
                balanceAfter: $newLedger,
                description: 'Transfer settled after provider completion.',
            );

            $this->mergeTransferWalletData($transfer, [
                'debit_reference' => $reference,
                'settled_amount' => $amount,
                'settled_at' => now()->toISOString(),
            ]);

            return $entry;
        });
    }

    public function releaseTransferHold(Transfer $transfer, string $reason = 'Transfer hold released.'): ?LedgerEntry
    {
        if (! $this->enabled()) {
            return null;
        }

        return OwnerFirst::transfer($transfer, function () use ($transfer, $reason): ?LedgerEntry {
            $transfer = $this->lockTransfer($transfer);
            $reference = $this->reference($transfer, 'release');

            $entries = $this->lifecycleEntries($transfer);
            if ($entries->isEmpty()) {
                // A never-reserved instruction has nothing to release. Claimed
                // wallet receipts without their journal are not this no-op case.
                $this->assertNoMissingHold($transfer);

                return null;
            }
            if ($entries->has('debit')) {
                throw new RuntimeException('A debited transfer hold cannot be released.');
            }
            [$balance, $amount] = $this->assertHoldIdentity($transfer, $entries);
            if ($entries->has('release')) {
                return $entries->get('release');
            }
            if ($this->compare($balance->reserved_balance, $amount) < 0) {
                throw new RuntimeException('Reserved balance is not sufficient to release this transfer hold.');
            }

            $newAvailable = $this->add($balance->available_balance, $amount);
            $newReserved = $this->subtract($balance->reserved_balance, $amount);

            if (! $balance->update([
                'available_balance' => $newAvailable,
                'reserved_balance' => $newReserved,
                'as_of' => now(),
                'raw_data' => $this->withLedgerMetadata($balance->raw_data, [
                    'last_release_reference' => $reference,
                    'last_release_transfer_id' => $transfer->id,
                ]),
            ])) {
                throw new RuntimeException('Transfer wallet book update was not persisted.');
            }

            $entry = $this->createEntry(
                transfer: $transfer,
                balance: $balance->fresh(),
                reference: $reference,
                entryType: 'release',
                amount: $amount,
                balanceAfter: $newAvailable,
                description: $reason,
            );

            $this->mergeTransferWalletData($transfer, [
                'release_reference' => $reference,
                'released_amount' => $amount,
                'released_at' => now()->toISOString(),
            ]);

            return $entry;
        });
    }

    /** Local validation before an atomic administrative rejection receipt/status mutation. */
    public function assertTransferHoldReleasable(Transfer $transfer): void
    {
        OwnerFirst::current([$transfer->user_id]);
        OwnerFirst::transfer($transfer, function () use ($transfer): void {
            if (! $this->enabled()) return;
            $transfer = $this->lockTransfer($transfer);
            $entries = $this->lifecycleEntries($transfer);
            if ($entries->isEmpty()) {
                $this->assertNoMissingHold($transfer);
                return;
            }
            if ($entries->has('debit')) throw new RuntimeException('A debited transfer hold cannot be released.');
            [$balance, $amount] = $this->assertHoldIdentity($transfer, $entries);
            if (! $entries->has('release') && $this->compare($balance->reserved_balance, $amount) < 0) {
                throw new RuntimeException('Reserved balance is not sufficient to release this transfer hold.');
            }
        });
    }

    /** Called inside Nium's locked submission transaction; never creates a hold. */
    public function assertTransferReserved(Transfer $transfer, string $providerWallet): LedgerEntry
    {
        OwnerFirst::current([$transfer->user_id]);
        return OwnerFirst::transfer($transfer, function () use ($transfer, $providerWallet): LedgerEntry {
            if (! $this->enabled()) {
                throw new RuntimeException('An active wallet reservation transaction is required before Nium submission.');
            }
            $transfer = $this->lockTransfer($transfer);
            $this->assertReservable($transfer);
            $entries = $this->lifecycleEntries($transfer);
            [$balance] = $this->assertHeldLifecycle($transfer, $entries);
            if ($balance->external_account_id !== $providerWallet) {
                throw new RuntimeException('Transfer hold wallet does not match the Nium submission wallet.');
            }

            return $entries->get('hold');
        });
    }

    /**
     * Cleanup authority is local to the call that INSERTed this hold. A loaded
     * duplicate has wasRecentlyCreated=false and never grants cleanup authority.
     * The caller must additionally supply a conclusive typed no-wire outcome.
     */
    public function releaseOwnedTransferHoldBeforeWire(
        Transfer $transfer, LedgerEntry $createdHold, ?string $noWireOperationKey = null,
    ): ?LedgerEntry {
        if (! $this->enabled() || ! $createdHold->wasRecentlyCreated) {
            return null;
        }

        return OwnerFirst::transfer($transfer, function () use ($transfer, $createdHold, $noWireOperationKey): ?LedgerEntry {
            $transfer = $this->lockTransfer($transfer);
            if (filled($transfer->external_transfer_id) || filled($transfer->external_payment_id)
                || $transfer->submitted_at !== null || $transfer->provider_status_at !== null) {
                return null;
            }
            if ($noWireOperationKey === null) {
                // Preflight never committed a submission claim. A competing
                // request that did so fences cleanup even while its POST runs.
                if (! in_array($transfer->status, ['draft', 'approval_required', 'approved'], true)
                    || filled($transfer->provider_operation_key)) {
                    return null;
                }
            } elseif ($noWireOperationKey === '' || $transfer->provider_operation_key !== $noWireOperationKey
                || $transfer->status !== 'failed'
                || ! in_array($transfer->failure_code, ['remittance_dispatch_held', 'payout_terms_expired_or_changed'], true)) {
                return null;
            }
            $entries = $this->lifecycleEntries($transfer);
            $this->assertHeldLifecycle($transfer, $entries);
            if ((string) $entries->get('hold')->id !== (string) $createdHold->id) {
                throw new RuntimeException('Submission cleanup does not own this transfer hold.');
            }

            return $this->releaseTransferHold($transfer, 'Transfer hold released after proven no-wire submission failure.');
        });
    }

    /** Transfer row lock serializes all supported writers; financial journals are never rewritten. */
    private function lifecycleEntries(Transfer $transfer): Collection
    {
        $references = array_map(fn (string $event): string => $this->reference($transfer, $event), ['hold', 'debit', 'release']);
        $rows = LedgerEntry::query()->where(function ($query) use ($transfer, $references): void {
            $query->where(fn ($source) => $source->where('source_type', 'transfer')->where('source_id', (string) $transfer->id))
                ->orWhereIn('reference', $references);
        })->get();
        $entries = collect();
        foreach ($rows as $entry) {
            if (! in_array($entry->entry_type, ['hold', 'debit', 'release'], true)
                || $entry->reference !== $this->reference($transfer, $entry->entry_type)
                || $entry->source_type !== 'transfer' || $entry->source_id !== (string) $transfer->id
                || $entry->status !== 'posted' || $entries->has($entry->entry_type)) {
                throw new RuntimeException('Transfer reservation journal evidence is missing or contradictory.');
            }
            $entries->put($entry->entry_type, $entry);
        }
        if (($entries->isNotEmpty() && ! $entries->has('hold'))
            || ($entries->has('debit') && $entries->has('release'))) {
            throw new RuntimeException('Transfer reservation journal lifecycle is contradictory.');
        }

        return $entries;
    }

    private function assertNoMissingHold(Transfer $transfer): void
    {
        $wallet = (array) ($transfer->raw_data['wallet'] ?? []);
        foreach (['hold_reference', 'held_amount', 'debit_reference', 'release_reference'] as $field) {
            if (array_key_exists($field, $wallet)) {
                throw new RuntimeException('Transfer wallet receipt has no matching hold journal.');
            }
        }
    }

    private function assertReservable(Transfer $transfer): void
    {
        if (! in_array($transfer->status, ['draft', 'approval_required', 'approved'], true)
            || filled($transfer->provider_operation_key) || filled($transfer->external_transfer_id)
            || filled($transfer->external_payment_id) || $transfer->submitted_at !== null
            || $transfer->provider_status_at !== null) {
            throw new RuntimeException('Transfer has already entered submission or a terminal lifecycle.');
        }
    }

    /** @return array{Balance, string} */
    private function assertHeldLifecycle(Transfer $transfer, Collection $entries, bool $reservation = false): array
    {
        if ($entries->has('release') || $entries->has('debit')) {
            throw new RuntimeException('Released or debited transfer holds cannot be reused as active reservations.');
        }
        [$balance, $amount] = $this->assertHoldIdentity($transfer, $entries, $reservation);
        if ($this->compare($balance->reserved_balance, $amount) < 0) {
            throw new RuntimeException('Reserved balance is not sufficient for this transfer hold.');
        }

        return [$balance, $amount];
    }

    /**
     * Validate frozen journal ownership before any idempotent return or mutation.
     * balance_id is the original wallet binding for existing journals. New entries
     * additionally retain the external wallet ID; historical journals are not rewritten.
     *
     * @return array{Balance, string}
     */
    private function assertHoldIdentity(Transfer $transfer, Collection $entries, bool $reservation = false): array
    {
        $hold = $entries->get('hold');
        if ($hold === null) {
            throw new RuntimeException('An own hold journal is required for this transfer.');
        }
        $wallet = (array) ($transfer->raw_data['wallet'] ?? []);
        $amount = $this->positiveAmount($transfer);
        if ((string) ($wallet['balance_id'] ?? '') !== (string) $hold->balance_id
            || ($wallet['hold_reference'] ?? null) !== $hold->reference
            || $this->decimal($wallet['held_amount'] ?? null) !== $amount) {
            throw new RuntimeException('Transfer wallet receipt does not match its frozen hold.');
        }
        $balance = $this->lockTransferBalance($transfer, $reservation);
        $this->assertBooks($balance);
        foreach ($entries as $event => $entry) {
            $expected = $event === 'release' ? $amount : $this->negative($amount);
            if ((string) $entry->balance_id !== (string) $balance->id
                || (string) $entry->user_id !== (string) $transfer->user_id
                || (string) $entry->provider_id !== (string) $transfer->provider_id
                || $entry->currency !== strtoupper((string) $transfer->source_currency)
                || $this->decimal($entry->amount) !== $expected || $entry->balance_after === null
                || (array_key_exists('wallet_id', $entry->raw_data ?? [])
                    && $entry->raw_data['wallet_id'] !== $balance->external_account_id)) {
                throw new RuntimeException('Transfer financial identity or amount does not match its frozen journal.');
            }
        }

        return [$balance, $amount];
    }

    private function positiveAmount(Transfer $transfer): string
    {
        $amount = $this->decimal($transfer->source_amount);
        if ($this->compare($amount, '0') <= 0) {
            throw new RuntimeException('Transfer reservation amount must be positive.');
        }

        return $amount;
    }

    private function assertBooks(Balance $balance): void
    {
        if ($this->compare($balance->ledger_balance, $this->add($balance->available_balance, $balance->reserved_balance)) !== 0) {
            throw new RuntimeException(NiumBalanceOwnership::hasDynamicBooks($balance)
                ? 'Dynamic-history wallet book balances are inconsistent.' : 'Wallet book balances are inconsistent.');
        }
        if ($this->compare($balance->available_balance, '0') < 0
            || $this->compare($balance->reserved_balance, '0') < 0) {
            throw new RuntimeException('Wallet book balances cannot be negative.');
        }
    }

    public function applyTransferTerminalStatus(Transfer $transfer): void
    {
        if ($transfer->status === 'completed') {
            $this->settleTransfer($transfer);

            return;
        }

        if (in_array($transfer->status, ['failed', 'cancelled', 'rejected'], true)) {
            $this->releaseTransferHold($transfer, 'Transfer hold released after terminal status.');
        }
    }

    private function lockTransfer(Transfer $transfer): Transfer
    {
        OwnerFirst::current([$transfer->user_id]);
        $locked = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
        if ((string) $locked->user_id !== (string) $transfer->user_id
            || (string) $locked->provider_id !== (string) $transfer->provider_id) {
            throw new \App\Services\Wallet\Concurrency\OwnerBindingChanged('Transfer financial binding changed.');
        }

        return $locked;
    }

    private function lockTransferBalance(Transfer $transfer, bool $reservation = false): Balance
    {
        $wallet = (array) (($transfer->raw_data ?? [])['wallet'] ?? []);
        $balanceId = $wallet['balance_id'] ?? null;

        $query = Balance::query()
            ->where('user_id', $transfer->user_id)
            ->where('provider_id', $transfer->provider_id)
            ->where('currency', strtoupper((string) $transfer->source_currency))
            ->lockForUpdate();

        if (NiumTransferReviewService::applies($transfer)) {
            $account = $transfer->user->providerAccounts()->where('provider_id', $transfer->provider_id)->lockForUpdate()->firstOrFail();
            $boundWallet = $transfer->fxQuote?->raw_data['provider_context']['wallet'] ?? $account->external_account_id;
            if ($reservation && (! is_string($account->external_account_id) || $account->external_account_id === ''
                || $boundWallet !== $account->external_account_id)) {
                throw new RuntimeException('Nium reservation wallet binding does not match the provider account.');
            }
            $walletId = $balanceId !== null
                ? $boundWallet
                : $account->external_account_id;
            $query->where('external_account_id', $walletId);
        }

        if ($reservation && ! NiumTransferReviewService::applies($transfer)
            && strtolower((string) $transfer->provider?->code) === 'nium') {
            $account = $transfer->user->providerAccounts()->where('provider_id', $transfer->provider_id)->lockForUpdate()->firstOrFail();
            if (! is_string($account->external_account_id) || $account->external_account_id === '') {
                throw new RuntimeException('Nium reservation wallet binding is missing.');
            }
            $query->where('external_account_id', $account->external_account_id);
        }

        if ($balanceId !== null) {
            $query->whereKey($balanceId);
        } else {
            $query->latest('as_of')->latest('id');
        }

        // J's provider FK must not first request its KEY SHARE after holding B.
        \App\Models\IntegrationProvider::query()->whereKey($transfer->provider_id)->lock('for key share')->firstOrFail();
        $balance = $query->first();

        if ($balance === null) {
            throw new RuntimeException('A synced wallet balance is required before submitting this transfer.');
        }

        return $balance;
    }

    private function createEntry(
        Transfer $transfer,
        Balance $balance,
        string $reference,
        string $entryType,
        string $amount,
        string $balanceAfter,
        string $description,
    ): LedgerEntry {
        OwnerFirst::current([$transfer->user_id]);
        $entry = LedgerEntry::query()->create([
            'balance_id' => $balance->id,
            'user_id' => $transfer->user_id,
            'provider_id' => $transfer->provider_id,
            'reference' => $reference,
            'entry_type' => $entryType,
            'status' => 'posted',
            'currency' => strtoupper((string) $transfer->source_currency),
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'source_type' => 'transfer',
            'source_id' => (string) $transfer->id,
            'description' => $description,
            'posted_at' => now(),
            'raw_data' => [
                'wallet_id' => $balance->external_account_id,
                'transfer_no' => $transfer->transfer_no,
                'external_transfer_id' => $transfer->external_transfer_id,
                'external_payment_id' => $transfer->external_payment_id,
            ],
        ]);
        if (! $entry->exists) {
            throw new RuntimeException('Transfer journal entry was not persisted.');
        }

        return $entry;
    }

    private function mergeTransferWalletData(Transfer $transfer, array $walletData): void
    {
        $rawData = (array) ($transfer->raw_data ?? []);
        $rawData['wallet'] = array_merge((array) ($rawData['wallet'] ?? []), $walletData);

        if (! $transfer->update(['raw_data' => $rawData])) {
            throw new RuntimeException('Transfer wallet receipt was not persisted.');
        }
    }

    private function withLedgerMetadata(?array $rawData, array $metadata): array
    {
        $rawData ??= [];
        $rawData['_origin_ledger'] = array_merge((array) ($rawData['_origin_ledger'] ?? []), $metadata);

        return $rawData;
    }

    private function reference(Transfer $transfer, string $event): string
    {
        return "transfer:{$transfer->id}:{$event}";
    }

    private function enabled(): bool
    {
        return (bool) config('wallet.ledger.enabled', true);
    }

    private function decimal(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value))
            || preg_match('/^-?[0-9]+(?:\.[0-9]{1,8})?$/D', (string) $value) !== 1) {
            throw new RuntimeException('Wallet ledger amounts must be exact decimals with at most eight places.');
        }
        $normalized = (string) $value;

        return bcadd($normalized, '0', self::SCALE);
    }

    private function add(mixed $left, mixed $right): string
    {
        return bcadd($this->decimal($left), $this->decimal($right), self::SCALE);
    }

    private function subtract(mixed $left, mixed $right): string
    {
        return bcsub($this->decimal($left), $this->decimal($right), self::SCALE);
    }

    private function negative(mixed $value): string
    {
        return bcsub('0', $this->decimal($value), self::SCALE);
    }

    private function compare(mixed $left, mixed $right): int
    {
        return bccomp($this->decimal($left), $this->decimal($right), self::SCALE);
    }
}
