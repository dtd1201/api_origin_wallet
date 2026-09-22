<?php

namespace App\Services\Wallet;

use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\UserProviderAccount;
use Illuminate\Support\Collection;

class TransactionWalletContextService
{
    private const SCALE = 8;

    /** @var array<int, Collection<int, LedgerEntry>> */
    private array $transferEntries = [];

    /** @var array<int, Collection<int, LedgerEntry>> */
    private array $balanceEntries = [];

    /** @var array<int, Balance|null> */
    private array $balances = [];

    /** @var array<string, UserProviderAccount|null> */
    private array $providerAccounts = [];

    public function context(Transaction $transaction): ?array
    {
        $currency = strtoupper((string) $transaction->currency);
        $historical = $this->historicalBalance($transaction);

        $rawData = (array) ($transaction->raw_data ?? []);

        $walletHashId = $rawData['wallet_hash_id']
            ?? $rawData['walletHashId']
            ?? ($historical['external_account_id'] ?? null);

        $providerAccount = $this->providerAccount(
            userId: (int) $transaction->user_id,
            providerId: (int) $transaction->provider_id,
            walletHashId: filled($walletHashId) ? (string) $walletHashId : null,
        );

        if ($providerAccount === null && $historical === null) {
            return null;
        }

        $virtualAccount = null;

        if ($providerAccount !== null) {
            $virtualAccounts = $providerAccount->niumVirtualAccounts()
                ->where('currency', $currency)
                ->get();

            // Never guess when the same wallet/currency has multiple VAs.
            if ($virtualAccounts->count() === 1) {
                $virtualAccount = $virtualAccounts->first();
            }
        }

        return [
            'provider_account_id' => $providerAccount?->id,

            'virtual_account_id' => $virtualAccount?->id,
            'virtual_account_reference' => $virtualAccount?->virtual_account_reference,
            'provider_payment_id' => $virtualAccount?->provider_payment_id,
            'virtual_account_currency' => $virtualAccount?->currency,
            'account_category' => $virtualAccount?->account_category,
            'account_type' => $virtualAccount?->account_type,
            'virtual_account_status' => $virtualAccount?->status,

            'balance_after_transaction' => $historical === null ? null : [
                'currency' => $historical['currency'],
                'available_balance' => $historical['available_balance'],
                'reserved_balance' => $historical['reserved_balance'],
                'ledger_balance' => $historical['ledger_balance'],
                'source' => 'origin_ledger',
                'entry_type' => $historical['entry_type'],
                'posted_at' => $historical['posted_at'],
            ],
        ];
    }

    private function historicalBalance(Transaction $transaction): ?array
    {
        $entry = $this->targetLedgerEntry($transaction);

        if ($entry === null || $entry->balance_id === null || $entry->balance_after === null) {
            return null;
        }

        $balance = $this->balance((int) $entry->balance_id);

        if ($balance === null) {
            return null;
        }

        $entries = $this->entriesForBalance((int) $entry->balance_id);

        /*
         * Infer the reserved balance before the first stored ledger entry from
         * the current reserved balance and the complete stored ledger delta.
         * Provider syncs do not own Origin reserved_balance.
         */
        $netReservedDelta = '0.00000000';

        foreach ($entries as $ledgerEntry) {
            $netReservedDelta = $this->applyReservedDelta(
                $netReservedDelta,
                $ledgerEntry
            );
        }

        $reserved = bcsub(
            $this->decimal($balance->reserved_balance),
            $netReservedDelta,
            self::SCALE
        );

        foreach ($entries as $ledgerEntry) {
            $reserved = $this->applyReservedDelta($reserved, $ledgerEntry);

            if ((int) $ledgerEntry->id === (int) $entry->id) {
                break;
            }
        }

        $balanceAfter = $this->decimal($entry->balance_after);
        $entryType = strtolower((string) $entry->entry_type);

        if (in_array($entryType, ['hold', 'release'], true)) {
            $available = $balanceAfter;
            $ledger = bcadd($available, $reserved, self::SCALE);
        } elseif ($entryType === 'debit') {
            $ledger = $balanceAfter;
            $available = bcsub($ledger, $reserved, self::SCALE);
        } else {
            return null;
        }

        return [
            'balance_id' => $balance->id,
            'external_account_id' => $balance->external_account_id,
            'currency' => strtoupper((string) $entry->currency),
            'available_balance' => $available,
            'reserved_balance' => $reserved,
            'ledger_balance' => $ledger,
            'entry_type' => $entryType,
            'posted_at' => $entry->posted_at?->toISOString(),
        ];
    }

    private function targetLedgerEntry(Transaction $transaction): ?LedgerEntry
    {
        if ($this->isReversal($transaction)) {
            $baseTransactionId = preg_replace(
                '/R$/i',
                '',
                (string) $transaction->external_transaction_id
            );

            $original = Transaction::query()
                ->where('provider_id', $transaction->provider_id)
                ->where('external_transaction_id', $baseTransactionId)
                ->first();

            if ($original?->transfer_id) {
                return $this->entriesForTransfer((int) $original->transfer_id)
                    ->where('entry_type', 'release')
                    ->last();
            }

            return null;
        }

        if (! $transaction->transfer_id) {
            return null;
        }

        $entries = $this->entriesForTransfer((int) $transaction->transfer_id);

        if ($entries->isEmpty()) {
            return null;
        }

        if (
            strtolower((string) $transaction->status) === 'completed'
            && $entries->contains('entry_type', 'debit')
        ) {
            return $entries->where('entry_type', 'debit')->last();
        }

        if (
            in_array(
                strtolower((string) $transaction->status),
                ['failed', 'cancelled', 'rejected'],
                true
            )
            && ! $this->hasSeparateReversal($transaction)
            && $entries->contains('entry_type', 'release')
        ) {
            return $entries->where('entry_type', 'release')->last();
        }

        return $entries->where('entry_type', 'hold')->last()
            ?? $entries->last();
    }

    private function isReversal(Transaction $transaction): bool
    {
        return strtolower((string) $transaction->direction) === 'credit'
            && str_contains(
                strtolower((string) $transaction->transaction_type),
                'reversal'
            )
            && preg_match('/R$/i', (string) $transaction->external_transaction_id) === 1;
    }

    private function hasSeparateReversal(Transaction $transaction): bool
    {
        if (! filled($transaction->external_transaction_id)) {
            return false;
        }

        return Transaction::query()
            ->where('provider_id', $transaction->provider_id)
            ->where(
                'external_transaction_id',
                (string) $transaction->external_transaction_id.'R'
            )
            ->exists();
    }

    private function entriesForTransfer(int $transferId): Collection
    {
        return $this->transferEntries[$transferId] ??=
            LedgerEntry::query()
                ->where('source_type', 'transfer')
                ->where('source_id', (string) $transferId)
                ->where('status', 'posted')
                ->orderBy('posted_at')
                ->orderBy('id')
                ->get();
    }

    private function entriesForBalance(int $balanceId): Collection
    {
        return $this->balanceEntries[$balanceId] ??=
            LedgerEntry::query()
                ->where('balance_id', $balanceId)
                ->where('status', 'posted')
                ->orderBy('posted_at')
                ->orderBy('id')
                ->get();
    }

    private function balance(int $balanceId): ?Balance
    {
        if (! array_key_exists($balanceId, $this->balances)) {
            $this->balances[$balanceId] = Balance::query()->find($balanceId);
        }

        return $this->balances[$balanceId];
    }

    private function providerAccount(
        int $userId,
        int $providerId,
        ?string $walletHashId,
    ): ?UserProviderAccount {
        if (! filled($walletHashId)) {
            return null;
        }

        $key = $userId.'|'.$providerId.'|'.$walletHashId;

        if (! array_key_exists($key, $this->providerAccounts)) {
            $this->providerAccounts[$key] = UserProviderAccount::query()
                ->where('user_id', $userId)
                ->where('provider_id', $providerId)
                ->where('external_account_id', $walletHashId)
                ->first();
        }

        return $this->providerAccounts[$key];
    }

    private function applyReservedDelta(string $reserved, LedgerEntry $entry): string
    {
        $amount = $this->absolute($entry->amount);

        return match (strtolower((string) $entry->entry_type)) {
            'hold' => bcadd($reserved, $amount, self::SCALE),
            'debit', 'release' => bcsub($reserved, $amount, self::SCALE),
            default => $reserved,
        };
    }

    private function absolute(mixed $value): string
    {
        $value = $this->decimal($value);

        return bccomp($value, '0', self::SCALE) < 0
            ? bcsub('0', $value, self::SCALE)
            : $value;
    }

    private function decimal(mixed $value): string
    {
        $normalized = str_replace(',', '', (string) ($value ?? 0));

        return is_numeric($normalized)
            ? bcadd($normalized, '0', self::SCALE)
            : bcadd('0', '0', self::SCALE);
    }
}
