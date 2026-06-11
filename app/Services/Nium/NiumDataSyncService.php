<?php

namespace App\Services\Nium;

use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Integrations\Contracts\DataSyncProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NiumDataSyncService implements DataSyncProvider
{
    public function __construct(
        private readonly NiumService $niumService,
    ) {}

    public function syncAccounts(IntegrationProvider $provider, User $user): array
    {
        $endpoint = (string) config('services.nium.customer_endpoint', '');
        $providerAccount = $this->providerAccount($provider, $user);

        if ($endpoint === '') {
            return [
                'synced_accounts' => $providerAccount !== null ? 1 : 0,
                'skipped' => 'NIUM_CUSTOMER_ENDPOINT is not configured.',
            ];
        }

        $response = $this->niumService->get(
            path: $this->niumService->path($endpoint, [
                'client' => $this->niumService->clientId(),
                'customer' => $this->niumService->customerId($user),
                'wallet' => $this->niumService->walletId($user),
            ]),
            user: $user,
        );

        $data = $this->successfulJson($response, 'Nium account sync failed.');
        $resource = $this->resource($data);

        DB::transaction(function () use ($provider, $resource, $user): void {
            UserProviderAccount::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'provider_id' => $provider->id,
                    'external_account_id' => (string) (
                        $this->value($resource, ['walletHashId', 'wallet_hash_id', 'walletId', 'wallet_id'])
                        ?: $this->niumService->walletId($user)
                    ),
                ],
                [
                    'external_customer_id' => (string) (
                        $this->value($resource, ['customerHashId', 'customer_hash_id', 'customerId', 'customer_id'])
                        ?: $this->niumService->customerId($user)
                    ),
                    'account_name' => $this->value($resource, ['name', 'customerName', 'fullName']) ?? $user->full_name,
                    'status' => $this->normalizeAccountStatus($this->value($resource, ['status', 'complianceStatus'])),
                    'metadata' => array_merge($resource, [
                        'synced_at' => now()->toISOString(),
                    ]),
                ],
            );
        });

        return ['synced_accounts' => 1];
    }

    public function syncBalances(IntegrationProvider $provider, User $user): array
    {
        $endpoint = (string) config('services.nium.wallet_balance_endpoint', '');

        if ($endpoint === '') {
            throw new RuntimeException('NIUM_WALLET_BALANCE_ENDPOINT is not configured.');
        }

        $response = $this->niumService->get(
            path: $this->niumService->path($endpoint, [
                'client' => $this->niumService->clientId(),
                'customer' => $this->niumService->customerId($user),
                'wallet' => $this->niumService->walletId($user),
            ]),
            user: $user,
        );

        $data = $this->successfulJson($response, 'Nium balance sync failed.');
        $items = $this->balanceItems($data);
        $count = 0;

        DB::transaction(function () use ($items, $provider, $user, &$count): void {
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
                $ledger = $this->numericValue($item, [
                    'ledgerBalance',
                    'ledger_balance',
                    'currentBalance',
                    'current_balance',
                    'balance',
                    'amount',
                ], $available);
                $reserved = $this->numericValue($item, [
                    'reservedBalance',
                    'reserved_balance',
                    'holdBalance',
                    'hold_balance',
                    'blockedBalance',
                    'blocked_balance',
                ]);

                $existing = Balance::query()
                    ->where('provider_id', $provider->id)
                    ->where('external_account_id', $externalAccountId)
                    ->where('currency', $currency)
                    ->lockForUpdate()
                    ->first();

                Balance::query()->updateOrCreate(
                    [
                        'provider_id' => $provider->id,
                        'external_account_id' => $externalAccountId,
                        'currency' => $currency,
                    ],
                    [
                        'user_id' => $user->id,
                        'available_balance' => $available,
                        'ledger_balance' => $ledger,
                        'reserved_balance' => $reserved ?? ($existing?->reserved_balance ?? 0),
                        'as_of' => $this->value($item, ['asOf', 'as_of', 'updatedAt', 'updated_at']) ?? now(),
                        'raw_data' => $item,
                    ],
                );

                $count++;
            }
        });

        return ['synced_balances' => $count];
    }

    public function syncTransactions(IntegrationProvider $provider, User $user): array
    {
        $endpoint = (string) config('services.nium.wallet_transactions_endpoint', '');

        if ($endpoint === '') {
            throw new RuntimeException('NIUM_WALLET_TRANSACTIONS_ENDPOINT is not configured.');
        }

        $response = $this->niumService->get(
            path: $this->niumService->path($endpoint, [
                'client' => $this->niumService->clientId(),
                'customer' => $this->niumService->customerId($user),
                'wallet' => $this->niumService->walletId($user),
            ]),
            query: [
                'fromDate' => now()->subDays((int) config('services.nium.transaction_sync_days', 30))->toDateString(),
                'toDate' => now()->toDateString(),
            ],
            user: $user,
        );

        $data = $this->successfulJson($response, 'Nium transaction sync failed.');
        $items = $this->items($data, [
            'transactions',
            'data.transactions',
            'wallet.transactions',
            'data',
            'content',
        ]);
        $count = 0;

        DB::transaction(function () use ($items, $provider, $user, &$count): void {
            foreach ($items as $item) {
                $externalTransactionId = $this->value($item, [
                    'transactionId',
                    'transaction_id',
                    'systemReferenceNumber',
                    'system_reference_number',
                    'id',
                ]);

                if (! filled($externalTransactionId)) {
                    continue;
                }

                $transferReference = $this->value($item, [
                    'systemReferenceNumber',
                    'system_reference_number',
                    'paymentId',
                    'payment_id',
                    'clientReference',
                    'client_reference',
                ]);
                $transfer = $this->findTransfer($provider, $transferReference);
                $currency = strtoupper((string) ($this->value($item, ['currency', 'currencyCode']) ?: $transfer?->source_currency ?: 'USD'));
                $amount = $this->numericValue($item, ['amount', 'transactionAmount', 'sourceAmount'], 0);

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
                        'status' => $this->normalizeTransactionStatus($this->value($item, ['status'])),
                        'booked_at' => $this->value($item, ['dateTime', 'createdAt', 'transactionDate']) ?? now(),
                        'value_date' => $this->value($item, ['valueDate', 'date']) ?? now(),
                        'raw_data' => $item,
                    ],
                );

                $count++;
            }
        });

        return ['synced_transactions' => $count];
    }

    private function providerAccount(IntegrationProvider $provider, User $user): ?UserProviderAccount
    {
        return $user->providerAccounts()
            ->where('provider_id', $provider->id)
            ->latest('id')
            ->first();
    }

    private function successfulJson(Response $response, string $message): array
    {
        $data = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful()) {
            throw new RuntimeException((string) ($data['message'] ?? $data['error'] ?? $message));
        }

        return is_array($data) ? $data : [];
    }

    private function resource(array $data): array
    {
        return (array) (Arr::get($data, 'data.customer')
            ?? Arr::get($data, 'customer')
            ?? Arr::get($data, 'data')
            ?? $data);
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

        return $this->value($data, ['currency', 'currencyCode']) !== null ? [$data] : [];
    }

    private function items(array $data, array $paths): array
    {
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
        $direction = strtolower((string) $this->value($item, ['direction', 'debitCreditIndicator']));

        if (in_array($direction, ['credit', 'debit'], true)) {
            return $direction;
        }

        return (float) $amount < 0 ? 'debit' : 'credit';
    }

    private function normalizeAccountStatus(mixed $status): string
    {
        return match (strtolower((string) $status)) {
            'active', 'approved', 'completed', 'verified' => 'active',
            'blocked', 'suspended', 'rejected', 'failed' => 'blocked',
            default => 'pending',
        };
    }

    private function normalizeTransactionStatus(mixed $status): string
    {
        return match (strtolower((string) $status)) {
            'completed', 'success', 'paid', 'posted' => 'completed',
            'failed', 'rejected', 'returned', 'error' => 'failed',
            'cancelled', 'canceled', 'voided' => 'cancelled',
            default => 'pending',
        };
    }
}
