<?php

namespace App\Services\Nium;

use App\Models\{BankAccount, IntegrationProvider, Transaction, Transfer};
use App\Services\Wallet\Concurrency\OwnerFirst;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Both Nium ingestion paths share the same absent-row exclusion and immutable ownership. */
final class NiumTransactionWriter
{
    public function save(IntegrationProvider $provider, int $owner, string $externalId, array $values): Transaction
    {
        OwnerFirst::current([$owner]);
        // Serialize Nium transaction writes per provider, including inserts with no row to lock.
        // Existing provider/external-id uniqueness remains the final database constraint.
        DB::selectOne('SELECT pg_advisory_xact_lock(?, ?)', [1313428820, $provider->id], false);
        $existing = Transaction::query()->where('provider_id', $provider->id)
            ->where('external_transaction_id', $externalId)->lockForUpdate()->first();
        if ($existing !== null && (string) $existing->user_id !== (string) $owner) {
            throw new RuntimeException('Nium transaction ownership conflict.');
        }
        if ($existing !== null && in_array($existing->status, ['completed', 'failed', 'cancelled'], true)
            && ($values['status'] ?? null) === 'pending') {
            $values['status'] = $existing->status;
        }
        foreach (['transfer_id' => Transfer::class, 'bank_account_id' => BankAccount::class] as $field => $model) {
            $incoming = $values[$field] ?? null;
            $verified = $existing?->{$field};
            if ($verified !== null && $incoming !== null && (string) $verified !== (string) $incoming) {
                throw new RuntimeException('Nium transaction verified linkage conflict.');
            }
            $linked = $incoming ?? $verified;
            if ($linked !== null && ! $model::query()->whereKey($linked)->where('user_id', $owner)
                ->where('provider_id', $provider->id)->exists()) {
                throw new RuntimeException('Nium transaction linkage ownership conflict.');
            }
            $values[$field] = $linked;
        }
        $values['raw_data'] = array_replace((array) ($existing?->raw_data ?? []), (array) ($values['raw_data'] ?? []));
        $record = $existing ?? new Transaction(['provider_id' => $provider->id, 'external_transaction_id' => $externalId]);
        $record->fill([...$values, 'user_id' => $owner]);
        if (! $record->save()) throw new RuntimeException('Nium transaction was not persisted.');
        return $record;
    }

    public function findTransfer(IntegrationProvider $provider, int $owner, array $references): ?Transfer
    {
        $references = array_values(array_unique(array_filter($references, static fn ($r) => is_string($r) && trim($r) !== '')));
        if ($references === []) return null;
        $matches = Transfer::query()->where('provider_id', $provider->id)->where('user_id', $owner)
            ->where(function ($q) use ($references): void {
                foreach (['external_transfer_id', 'external_payment_id', 'transfer_no', 'client_reference', 'provider_operation_key'] as $field) {
                    $q->orWhereIn($field, $references);
                }
            })->limit(2)->get();
        if ($matches->count() > 1) throw new RuntimeException('Nium transfer reference is ambiguous.');
        return $matches->first();
    }
}
