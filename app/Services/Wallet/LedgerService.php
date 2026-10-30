<?php

namespace App\Services\Wallet;

use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Services\Nium\NiumPayoutFxLockContract;
use App\Services\Nium\NiumPayoutPilotPolicy;
use App\Services\Nium\NiumSgdLocalPayoutAuthorization;
use App\Services\Nium\NiumSgdLocalPayoutGate;
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

        return DB::transaction(function () use ($transfer): LedgerEntry {
            $transfer = $this->lockTransfer($transfer);
            $financialTerms = null;
            $nium = $this->isNium($transfer);
            if ($nium) {
                if (! in_array($transfer->status, ['draft', 'approval_required', 'approved'], true)
                    || LedgerEntry::query()->where('source_type', 'transfer')->where('source_id', (string) $transfer->id)->whereIn('entry_type', ['release', 'debit'])->exists()) {
                    throw new RuntimeException('A dispatched or terminal NIUM reservation cannot be reserved again.');
                }
                if ($transfer->target_currency === 'SGD') {
                    $financialTerms = NiumSgdLocalPayoutGate::assertSubmissionAllowed($transfer);
                    app(NiumSgdLocalPayoutAuthorization::class)->assertApproved($transfer);
                    if ($transfer->provider_operation_key === null) {
                        throw new RuntimeException('SGD funding requires the atomic dispatch operation claim.');
                    }
                }
            }
            $reference = $this->reference($transfer, 'hold');

            $existing = LedgerEntry::query()->where('reference', $reference)->first();

            if ($existing !== null) {
                if ($financialTerms !== null && (! NiumPayoutFxLockContract::sameBinding($existing->raw_data['financial_authorization'] ?? null, $financialTerms)
                    || $this->compare($this->negative($existing->amount), $financialTerms['reservation_amount'] ?? $financialTerms['total_wallet_debit']) !== 0)) {
                    throw new RuntimeException('The existing SGD reservation does not match the approved all-in debit.');
                }
                if ($nium) {
                    $owner = $existing->raw_data['operation_key'] ?? null;
                    if ($owner !== null && $owner !== $transfer->provider_operation_key) {
                        throw new RuntimeException('Reservation belongs to another NIUM operation.');
                    }
                    if ($owner === null && $transfer->provider_operation_key !== null) {
                        // Adopt a legacy pre-dispatch hold under the same transfer lock.
                        $existing->update(['raw_data' => array_merge($existing->raw_data ?? [], ['operation_key' => $transfer->provider_operation_key])]);
                    }
                }

                return $existing;
            }

            $balance = $this->lockTransferBalance($transfer);
            $amount = $this->decimal($financialTerms['reservation_amount'] ?? $financialTerms['total_wallet_debit'] ?? $transfer->source_amount);

            if ($this->compare($balance->available_balance, $amount) < 0) {
                throw new RuntimeException('Insufficient available balance for this transfer.');
            }

            $newAvailable = $this->subtract($balance->available_balance, $amount);
            $newReserved = $this->add($balance->reserved_balance, $amount);

            $balance->update([
                'available_balance' => $newAvailable,
                'reserved_balance' => $newReserved,
                'as_of' => now(),
                'raw_data' => $this->withLedgerMetadata($balance->raw_data, [
                    'last_hold_reference' => $reference,
                    'last_hold_transfer_id' => $transfer->id,
                ]),
            ]);

            $entry = $this->createEntry(
                transfer: $transfer,
                balance: $balance->fresh(),
                reference: $reference,
                entryType: 'hold',
                amount: $this->negative($amount),
                balanceAfter: $newAvailable,
                description: 'Transfer funds reserved before provider submission.',
            );
            if ($financialTerms !== null) {
                $entry->update(['raw_data' => array_merge($entry->raw_data, ['financial_authorization' => $financialTerms])]);
                app(NiumPayoutPilotPolicy::class)->consume($transfer, $financialTerms);
            }

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

        $expectedOperation = $transfer->provider_operation_key;

        return DB::transaction(function () use ($transfer, $expectedOperation): ?LedgerEntry {
            $transfer = $this->lockTransfer($transfer);
            $this->assertNiumTerminalOwner($transfer, $expectedOperation, ['completed']);
            $reference = $this->reference($transfer, 'debit');

            $existing = LedgerEntry::query()->where('reference', $reference)->first();

            if ($existing !== null) {
                return $existing;
            }

            // Remittance status proves payout lifecycle, not the USD wallet debit.
            // The authenticated wallet-activity reconciler owns SGD accounting.
            if ($this->isNium($transfer) && $transfer->target_currency === 'SGD') {
                return null;
            }

            $balance = $this->lockTransferBalance($transfer);
            $amount = $this->reservationAmount($transfer);

            if ($this->compare($balance->reserved_balance, $amount) < 0) {
                throw new RuntimeException('Reserved balance is not sufficient to settle this transfer.');
            }

            $newReserved = $this->subtract($balance->reserved_balance, $amount);
            $newLedger = $this->subtract($balance->ledger_balance, $amount);

            $balance->update([
                'ledger_balance' => $newLedger,
                'reserved_balance' => $newReserved,
                'as_of' => now(),
                'raw_data' => $this->withLedgerMetadata($balance->raw_data, [
                    'last_debit_reference' => $reference,
                    'last_debit_transfer_id' => $transfer->id,
                ]),
            ]);

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

        $expectedOperation = $transfer->provider_operation_key;

        return DB::transaction(function () use ($transfer, $reason, $expectedOperation): ?LedgerEntry {
            $transfer = $this->lockTransfer($transfer);
            $this->assertNiumTerminalOwner($transfer, $expectedOperation, ['failed', 'cancelled', 'rejected']);
            $reference = $this->reference($transfer, 'release');

            $existing = LedgerEntry::query()->where('reference', $reference)->first();

            if ($existing !== null) {
                return $existing;
            }

            $holdEntry = LedgerEntry::query()
                ->where('reference', $this->reference($transfer, 'hold'))
                ->first();

            if ($holdEntry === null) {
                return null;
            }

            if ($this->isNium($transfer) && $transfer->target_currency === 'SGD'
                && isset($transfer->raw_data['nium_execution']['dispatch_started_at'])) {
                // Rejection/return does not prove that principal and fees were refunded.
                return null;
            }

            $balance = $this->lockTransferBalance($transfer);
            $amount = $this->reservationAmount($transfer);
            if ($this->isNium($transfer) && $this->compare($balance->reserved_balance, $amount) < 0) {
                throw new RuntimeException('NIUM reservation is incomplete; reconciliation is required.');
            }
            $releasableAmount = $this->min($amount, $balance->reserved_balance);

            if ($this->compare($releasableAmount, '0') <= 0) {
                return null;
            }

            $newAvailable = $this->add($balance->available_balance, $releasableAmount);
            $newReserved = $this->subtract($balance->reserved_balance, $releasableAmount);

            $balance->update([
                'available_balance' => $newAvailable,
                'reserved_balance' => $newReserved,
                'as_of' => now(),
                'raw_data' => $this->withLedgerMetadata($balance->raw_data, [
                    'last_release_reference' => $reference,
                    'last_release_transfer_id' => $transfer->id,
                ]),
            ]);

            $entry = $this->createEntry(
                transfer: $transfer,
                balance: $balance->fresh(),
                reference: $reference,
                entryType: 'release',
                amount: $releasableAmount,
                balanceAfter: $newAvailable,
                description: $reason,
            );

            $this->mergeTransferWalletData($transfer, [
                'release_reference' => $reference,
                'released_amount' => $releasableAmount,
                'released_at' => now()->toISOString(),
            ]);

            return $entry;
        });
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

    private function isNium(Transfer $transfer): bool
    {
        return strtolower((string) $transfer->provider()->value('code')) === 'nium';
    }

    private function reservationAmount(Transfer $transfer): string
    {
        if (! $this->isNium($transfer)) {
            return $this->decimal($transfer->source_amount);
        }
        $hold = LedgerEntry::query()->where('reference', $this->reference($transfer, 'hold'))->first();
        if ($hold === null) {
            throw new RuntimeException('NIUM terminal accounting requires the original reservation.');
        }

        return $this->negative($hold->amount);
    }

    private function assertNiumTerminalOwner(Transfer $transfer, ?string $expectedOperation, array $statuses): void
    {
        if (! $this->isNium($transfer)) {
            return;
        }
        if ($transfer->provider_operation_key !== $expectedOperation || ! in_array($transfer->status, $statuses, true)) {
            throw new RuntimeException('Only the current terminal NIUM operation may change its reservation.');
        }
        $hold = LedgerEntry::query()->where('reference', $this->reference($transfer, 'hold'))->first();
        $owner = $hold?->raw_data['operation_key'] ?? null;
        if ($owner !== null && ($owner !== $expectedOperation
            || ($transfer->raw_data['nium_execution']['operation_key'] ?? null) !== $owner
            || ($transfer->raw_data['nium_execution']['terminal_status'] ?? null) !== $transfer->status)) {
            throw new RuntimeException('NIUM terminal evidence does not authorize this reservation owner.');
        }
        $opposite = $transfer->status === 'completed' ? 'release' : 'debit';
        if (LedgerEntry::query()->where('reference', $this->reference($transfer, $opposite))->exists()) {
            throw new RuntimeException('NIUM reservation already has a conflicting terminal entry.');
        }
    }

    private function lockTransfer(Transfer $transfer): Transfer
    {
        return Transfer::query()
            ->whereKey($transfer->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockTransferBalance(Transfer $transfer): Balance
    {
        $wallet = (array) (($transfer->raw_data ?? [])['wallet'] ?? []);
        $balanceId = $wallet['balance_id'] ?? null;

        $query = Balance::query()
            ->where('user_id', $transfer->user_id)
            ->where('provider_id', $transfer->provider_id)
            ->where('currency', strtoupper((string) $transfer->source_currency))
            ->lockForUpdate();

        if ($balanceId !== null) {
            $query->whereKey($balanceId);
        } else {
            $query->latest('as_of')->latest('id');
        }
        if ($this->isNium($transfer) && $transfer->target_currency === 'SGD') {
            $walletId = $transfer->raw_data['sgd_local_approval']['provider_context']['wallet'] ?? null;
            if (! is_string($walletId) || $walletId === '') {
                throw new RuntimeException('SGD reservation requires the approved source wallet.');
            }
            $query->where('external_account_id', $walletId);
        }

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
        return LedgerEntry::query()->create([
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
                'operation_key' => $transfer->provider_operation_key,
                'transfer_no' => $transfer->transfer_no,
                'external_transfer_id' => $transfer->external_transfer_id,
                'external_payment_id' => $transfer->external_payment_id,
            ],
        ]);
    }

    private function mergeTransferWalletData(Transfer $transfer, array $walletData): void
    {
        $rawData = (array) ($transfer->raw_data ?? []);
        $rawData['wallet'] = array_merge((array) ($rawData['wallet'] ?? []), $walletData);

        $transfer->update(['raw_data' => $rawData]);
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
        if ($value === null || $value === '') {
            return bcadd('0', '0', self::SCALE);
        }

        $normalized = str_replace(',', '', (string) $value);

        if (! is_numeric($normalized)) {
            return bcadd('0', '0', self::SCALE);
        }

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

    private function min(mixed $left, mixed $right): string
    {
        return $this->compare($left, $right) <= 0 ? $this->decimal($left) : $this->decimal($right);
    }
}
