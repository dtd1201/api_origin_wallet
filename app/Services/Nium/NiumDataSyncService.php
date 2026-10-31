<?php

namespace App\Services\Nium;

use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Integrations\Contracts\DataSyncProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NiumDataSyncService implements DataSyncProvider
{
    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumCustomerOnboardingService $customerOnboardingService,
    ) {}

    public function syncAccounts(IntegrationProvider $provider, User $user): array
    {
        $providerAccount = $this->customerOnboardingService->syncUser($provider, $user);

        return [
            'synced_accounts' => 1,
            'provider_account_status' => $providerAccount->status,
            'provider_status' => $providerAccount->provider_status,
            'provider_sub_status' => $providerAccount->provider_sub_status,
        ];
    }

    /** The optional source snapshot enables strict, single-wallet payout-review verification. */
    public function syncBalances(IntegrationProvider $provider, User $user, ?Balance $sourceBalance = null): array
    {
        $endpoint = (string) config('services.nium.wallet_balance_endpoint', '');

        if ($endpoint === '') {
            throw new RuntimeException('NIUM_WALLET_BALANCE_ENDPOINT is not configured.');
        }

        $context = null;
        if ($sourceBalance !== null) {
            if (DB::transactionLevel() !== 0) {
                throw new RuntimeException('Source-wallet refresh requires committed state outside a transaction.');
            }
            $context = app(NiumPayoutFxLockService::class)->context($provider, $user);
            $this->assertSourceIdentity($sourceBalance, $provider, $user, $context);
            // Missing response identity is acceptable only on this exact authenticated wallet URL.
            foreach (['client', 'customer', 'wallet'] as $identity) {
                if (! str_contains($endpoint, '{'.$identity.'}') && ! str_contains($endpoint, '{'.$identity.'HashId}')) {
                    throw new RuntimeException('Source-wallet balance endpoint must bind client, customer and wallet.');
                }
            }
        }
        $response = $this->niumService->get(
            path: $this->niumService->path($endpoint, [
                'client' => $this->niumService->clientId(),
                'customer' => $this->niumService->customerId($user),
                'wallet' => $this->niumService->walletId($user),
            ]),
            user: $user,
            operation: $sourceBalance === null ? null : 'payout_source_balance',
        );

        $data = $this->successfulJson($response, 'Nium balance sync failed.');
        $items = $this->balanceItems($data);
        if ($sourceBalance !== null) {
            $items = [$this->verifiedSourceItem($data, $items, $sourceBalance, $context)];
        }
        $count = 0;

        DB::transaction(function () use ($items, $provider, $user, $sourceBalance, $context, &$count): void {
            if ($context !== null && ! NiumPayoutFxLockContract::sameBinding($context, app(NiumPayoutFxLockService::class)->context($provider, $user))) {
                throw new RuntimeException('The verified source-wallet identity changed during balance refresh.');
            }
            foreach ($items as $item) {
                $currency = strtoupper((string) $this->value($item, [
                    'currency',
                    'currencyCode',
                    'currency_code',
                    'curSymbol',
                ]));

                if ($currency === '') {
                    continue;
                }

                $externalAccountId = (string) (
                    $this->value($item, ['walletHashId', 'wallet_hash_id', 'walletId', 'wallet_id'])
                    ?: $this->niumService->walletId($user)
                );
                $available = $this->numericValue($item, [
                    'availableBalance',
                    'available_balance',
                    'available',
                    'availableAmount',
                    'balance',
                    'amount',
                ]);
                $providerLedger = $this->numericValue($item, [
                    'ledgerBalance',
                    'ledger_balance',
                    'currentBalance',
                    'current_balance',
                    'balance',
                    'amount',
                ], $available);
                $providerReserved = $this->numericValue($item, [
                    'reservedBalance',
                    'reserved_balance',
                    'withHoldingBalance',
                    'with_holding_balance',
                    'holdBalance',
                    'hold_balance',
                    'blockedBalance',
                    'blocked_balance',
                ]);

                $existing = $sourceBalance !== null ? Balance::query()->whereKey($sourceBalance->id)->lockForUpdate()->firstOrFail() : Balance::query()
                    ->where('provider_id', $provider->id)
                    ->where('external_account_id', $externalAccountId)
                    ->where('currency', $currency)
                    ->lockForUpdate()
                    ->first();

                if ($sourceBalance !== null) {
                    $this->assertSourceIdentity($existing, $provider, $user, $context);
                }

                // reserved_balance is owned by Origin's transfer ledger.
                // Provider-side reserved/blocked amounts are informational only.
                $localReserved = $existing?->reserved_balance ?? 0;
                $localAvailable = $available ?? ($existing?->available_balance ?? 0);
                $localLedger = bcadd(
                    (string) $localAvailable,
                    (string) $localReserved,
                    8
                );
                if ($existing !== null && $existing->user_id !== $user->id) {
                    throw new RuntimeException('NIUM balance snapshot belongs to another wallet owner.');
                }
                $managedByLedger = $existing !== null && LedgerEntry::query()->where('balance_id', $existing->id)->exists();
                if ($sourceBalance !== null && $managedByLedger && bccomp((string) $available, (string) $existing->available_balance, 8) !== 0) {
                    throw new RuntimeException('The source-wallet balance requires audited reconciliation before review.');
                }
                if ($managedByLedger) {
                    // A provider snapshot may already include a debit awaiting event reconciliation.
                    // Preserve audited local balances; record the snapshot for discrepancy review.
                    $localAvailable = $existing->available_balance;
                    $localLedger = $existing->ledger_balance;
                }

                $rawData = array_merge(
                    (array) ($existing?->raw_data ?? []),
                    array_filter([
                        'wallet_id' => $externalAccountId,
                        'currency' => $currency,
                        'provider_status' => $this->value($item, ['status']),
                        'provider_available_balance' => $available,
                        'provider_ledger_balance' => $providerLedger,
                        'provider_reserved_balance' => $providerReserved,
                        'provider_balance_requires_audited_reconciliation' => $managedByLedger,
                    ], static fn ($value) => $value !== null && $value !== '')
                );

                $fields = [
                    'user_id' => $user->id,
                    'available_balance' => $localAvailable,
                    'ledger_balance' => $localLedger,
                    'reserved_balance' => $localReserved,
                    'as_of' => $this->value($item, ['asOf', 'as_of', 'updatedAt', 'updated_at']) ?? now(),
                    'raw_data' => $rawData,
                ];
                if ($sourceBalance !== null) {
                    $existing->update($fields);
                } else {
                    Balance::query()->updateOrCreate(
                        [
                            'provider_id' => $provider->id,
                            'external_account_id' => $externalAccountId,
                            'currency' => $currency,
                        ],
                        $fields,
                    );
                }

                $count++;
            }
        });

        return ['synced_balances' => $count];
    }

    private function assertSourceIdentity(Balance $balance, IntegrationProvider $provider, User $user, array $context): void
    {
        if ($balance->user_id !== $user->id || $balance->provider_id !== $provider->id
            || $balance->external_account_id !== $context['wallet'] || $balance->currency !== 'USD'
            || $balance->bank_account_id !== null) {
            throw new RuntimeException('Source-wallet balance identity does not match the authenticated NIUM wallet.');
        }
    }

    private function verifiedSourceItem(array $data, array $items, Balance $balance, array $context): array
    {
        $identities = ['client' => ['clientHashId', 'client_hash_id', 'clientId', 'client_id'],
            'customer' => ['customerHashId', 'customer_hash_id', 'customerId', 'customer_id'],
            'wallet' => ['walletHashId', 'wallet_hash_id', 'walletId', 'wallet_id']];
        $containers = [$data, $data['data'] ?? [], $data['wallet'] ?? [], $data['data']['wallet'] ?? []];
        foreach ([...$containers, ...$items] as $container) {
            if (! is_array($container)) {
                continue;
            }
            foreach ($identities as $identity => $aliases) {
                foreach ($aliases as $alias) {
                    if (array_key_exists($alias, $container) && $container[$alias] !== $context[$identity]) {
                        throw new RuntimeException('NIUM returned a different source-wallet identity.');
                    }
                }
            }
        }
        $matching = array_values(array_filter($items, fn ($item) => strtoupper((string) $this->value($item, ['currency', 'currencyCode', 'currency_code', 'curSymbol'])) === $balance->currency));
        if (count($matching) !== 1) {
            throw new RuntimeException('NIUM did not return a unique source-currency balance.');
        }
        $item = $matching[0];
        foreach (['currency', 'currencyCode', 'currency_code', 'curSymbol'] as $alias) {
            if (isset($item[$alias]) && strtoupper((string) $item[$alias]) !== $balance->currency) {
                throw new RuntimeException('NIUM returned conflicting source currencies.');
            }
        }
        $available = $this->value($item, ['availableBalance', 'available_balance', 'available', 'availableAmount', 'balance', 'amount']);
        if ((! is_string($available) && ! is_int($available) && ! is_float($available))
            || ! preg_match('/^-?\d{1,12}(?:\.\d{1,8})?$/D', (string) $available)) {
            throw new RuntimeException('NIUM did not return a verified available source-wallet balance.');
        }
        $timestamp = $this->value($item, ['asOf', 'as_of', 'updatedAt', 'updated_at']);
        foreach ($containers as $container) {
            if ($timestamp === null && is_array($container)) {
                $timestamp = $this->value($container, ['asOf', 'as_of', 'updatedAt', 'updated_at']);
            }
        }
        $asOf = $timestamp === null ? now() : Carbon::parse(NiumPayoutFxLockContract::expiry($timestamp));
        if ($asOf->isFuture() || $asOf->lessThanOrEqualTo(now()->subMinutes(5))) {
            throw new RuntimeException('NIUM returned an expired or future-dated source-wallet snapshot.');
        }

        // Normalize only this verified row; omitted amounts never re-date an old local balance.
        return [...$item, 'walletHashId' => $context['wallet'], 'currency' => $balance->currency,
            'availableBalance' => (string) $available, 'asOf' => $asOf->toISOString()];
    }

    public function syncTransactions(IntegrationProvider $provider, User $user): array
    {
        $endpoint = (string) config('services.nium.wallet_transactions_endpoint', '');

        if ($endpoint === '') {
            throw new RuntimeException('NIUM_WALLET_TRANSACTIONS_ENDPOINT is not configured.');
        }

        $account = $user->providerAccounts()->where('provider_id', $provider->id)->firstOrFail();
        $startAt = $account->transactions_last_synced_at?->copy()->subDay()
            ?? now()->subDays((int) config('services.nium.transaction_sync_days', 30));
        $pageSize = min(max((int) config('services.nium.transaction_sync_page_size', 100), 1), 200);
        $maxPages = min(max((int) config('services.nium.transaction_sync_max_pages', 20), 1), 100);
        $items = [];

        for ($page = 0; $page < $maxPages; $page++) {
            $response = $this->niumService->get(
                path: $this->niumService->path($endpoint, [
                    'client' => $this->niumService->clientId(),
                    'customer' => $this->niumService->customerId($user),
                    'wallet' => $this->niumService->walletId($user),
                ]),
                query: [
                    'startDate' => $startAt->toDateString(),
                    'endDate' => now()->toDateString(),
                    'page' => $page,
                    'size' => $pageSize,
                    'order' => 'DESC',
                ],
                user: $user,
            );

            $data = $this->successfulJson($response, 'Nium transaction sync failed.');
            $pageItems = $this->items($data, ['transactions', 'data.transactions', 'wallet.transactions', 'content', 'data']);
            $items = [...$items, ...$pageItems];

            $totalPages = (int) (Arr::get($data, 'totalPages') ?? Arr::get($data, 'data.totalPages') ?? 0);
            if ($pageItems === [] || count($pageItems) < $pageSize || ($totalPages > 0 && $page + 1 >= $totalPages)) {
                break;
            }
        }
        $count = 0;

        DB::transaction(function () use ($items, $provider, $user, &$count): void {
            foreach ($items as $item) {
                $externalTransactionId = $this->value($item, [
                    // transactionId is shared by Nium transaction sync and
                    // remittance webhooks, so use it as the canonical identity
                    // whenever the provider supplies it.
                    'transactionId',
                    'transaction_id',
                    'transactionHashId',
                    'transaction_hash_id',
                    'systemReferenceNumber',
                    'system_reference_number',
                    'systemTraceAuditNumber',
                    'retrievalReferenceNumber',
                    'authCode',
                    'id',
                ]);

                if (! filled($externalTransactionId)) {
                    continue;
                }

                $transferReference = $this->value($item, [
                    'systemReferenceNumber',
                    'system_reference_number',
                    'systemTraceAuditNumber',
                    'retrievalReferenceNumber',
                    'authCode',
                    'paymentId',
                    'payment_id',
                    'clientReference',
                    'client_reference',
                ]);
                $transfer = $this->findTransfer($provider, $transferReference);
                $currency = strtoupper((string) ($this->value($item, ['currency', 'currencyCode']) ?: $transfer?->source_currency ?: 'USD'));
                $amount = $this->numericValue($item, ['amount', 'transactionAmount', 'sourceAmount', 'cardTransactionAmount', 'authAmount', 'billingAmount'], 0);

                Transaction::query()->updateOrCreate(
                    [
                        'provider_id' => $provider->id,
                        'external_transaction_id' => (string) $externalTransactionId,
                    ],
                    [
                        'user_id' => $user->id,
                        'bank_account_id' => null,
                        'transfer_id' => $transfer?->id,
                        'transaction_type' => $this->value($item, ['type', 'transactionType']),
                        'direction' => $this->direction($item, $amount),
                        'currency' => $currency,
                        'amount' => abs((float) $amount),
                        'fee_amount' => $this->numericValue($item, ['fee', 'feeAmount', 'markupAmount'], 0),
                        'description' => $this->value($item, ['description', 'remarks', 'narration']),
                        'reference_text' => $this->value($item, ['reference', 'clientReference', 'customerComments']),
                        'status' => $transfer !== null
                            ? $this->normalizeTransactionStatus($transfer->status)
                            : $this->normalizeTransactionStatus($this->value($item, ['status'])),
                        'booked_at' => $this->value($item, ['dateTime', 'createdAt', 'transactionDate']) ?? now(),
                        'value_date' => $this->value($item, ['valueDate', 'date']) ?? now(),
                        'raw_data' => array_filter([
                            'external_transaction_id' => (string) $externalTransactionId,
                            'provider_request_id' => $this->value($item, ['requestId', 'request_id']),
                            'provider_status' => $this->value($item, ['status']),
                            'settlement_status' => $this->value($item, ['settlementStatus']),
                            'compliance_status' => $this->value($item, ['complianceStatus']),
                            'transaction_id' => $this->value($item, [
                                'transactionId',
                                'transaction_id',
                            ]),
                            'transaction_hash_id' => $this->value($item, [
                                'transactionHashId',
                                'transaction_hash_id',
                            ]),
                            'auth_code' => $this->value($item, ['authCode']),
                            'wallet_hash_id' => $this->value($item, ['walletHashId']),
                            'customer_hash_id' => $this->value($item, ['customerHashId']),
                            'system_reference_number' => $this->value($item, [
                                'systemReferenceNumber',
                                'system_reference_number',
                            ]),
                            'rfi_details' => $item['rfiDetails'] ?? [],
                        ], static fn ($value) => $value !== null && $value !== ''),
                    ],
                );

                $count++;
            }
        });

        $account->update(['transactions_last_synced_at' => now()]);

        return ['synced_transactions' => $count];
    }

    private function successfulJson(Response $response, string $message): array
    {
        $data = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful()) {
            throw new RuntimeException((string) ($data['message'] ?? $data['error'] ?? $message));
        }

        return is_array($data) ? $data : [];
    }

    private function balanceItems(array $data): array
    {
        $items = $this->items($data, [
            'balances',
            'data.balances',
            'wallet.balances',
            'data.wallet.balances',
            'currencies',
            'data',
        ]);

        if ($items !== []) {
            return $items;
        }

        return $this->value($data, ['currency', 'currencyCode', 'curSymbol']) !== null ? [$data] : [];
    }

    private function items(array $data, array $paths): array
    {
        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        foreach ($paths as $path) {
            $value = Arr::get($data, $path);

            if (is_array($value) && $value !== []) {
                if (array_is_list($value)) {
                    return array_values(array_filter($value, 'is_array'));
                }

                return collect($value)
                    ->map(function ($item, $key): array {
                        $item = is_array($item) ? $item : ['amount' => $item];

                        return is_string($key) ? ['currency' => $key, ...$item] : $item;
                    })
                    ->values()
                    ->all();
            }
        }

        return [];
    }

    private function value(array $item, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = Arr::get($item, $path);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function numericValue(array $item, array $paths, mixed $default = null): mixed
    {
        $value = $this->value($item, $paths);

        return is_numeric($value) ? $value : $default;
    }

    private function findTransfer(IntegrationProvider $provider, mixed $reference): ?Transfer
    {
        if (! filled($reference)) {
            return null;
        }

        return Transfer::query()
            ->where('provider_id', $provider->id)
            ->where(function ($query) use ($reference): void {
                $query->where('external_transfer_id', $reference)
                    ->orWhere('external_payment_id', $reference)
                    ->orWhere('transfer_no', $reference)
                    ->orWhere('client_reference', $reference);
            })
            ->first();
    }

    private function direction(array $item, mixed $amount): string
    {
        $direction = strtolower(trim((string) $this->value(
            $item,
            ['direction', 'debitCreditIndicator']
        )));

        if (in_array($direction, ['debit', 'outbound'], true)) {
            return 'debit';
        }

        if (in_array($direction, ['credit', 'inbound'], true)) {
            return 'credit';
        }

        $transactionType = strtolower((string) $this->value(
            $item,
            ['transactionType', 'type']
        ));

        if (str_contains($transactionType, 'debit')) {
            return 'debit';
        }

        if (str_contains($transactionType, 'credit')) {
            return 'credit';
        }

        return (float) $amount < 0 ? 'debit' : 'credit';
    }

    private function normalizeTransactionStatus(mixed $status): string
    {
        return match (strtolower((string) $status)) {
            'completed',
            'success',
            'paid',
            'posted',
            'approved',
            'settled' => 'completed',

            'failed',
            'rejected',
            'returned',
            'error',
            'declined' => 'failed',

            'cancelled',
            'canceled',
            'voided' => 'cancelled',

            default => 'pending',
        };
    }
}
