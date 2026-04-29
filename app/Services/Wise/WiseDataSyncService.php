<?php

namespace App\Services\Wise;

use App\Models\Balance;
use App\Models\BankAccount;
use App\Models\IntegrationProvider;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Integrations\Contracts\DataSyncProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WiseDataSyncService implements DataSyncProvider
{
    public function __construct(
        private readonly WiseService $wiseService,
    ) {
    }

    public function syncAccounts(IntegrationProvider $provider, User $user): array
    {
        $response = $this->wiseService->get(
            path: $this->wiseService->path(
                (string) config('services.wise.account_details_endpoint'),
                ['profile' => $this->wiseService->profileId($user)],
            ),
            user: $user,
        );

        $items = $this->items($response);
        $count = 0;

        DB::transaction(function () use ($items, $provider, $user, &$count): void {
            foreach ($items as $item) {
                $externalAccountId = $this->value($item, ['id']) ?? 'wise-account-'.$this->value($item, ['currency.code', 'currency']);

                if (! filled($externalAccountId)) {
                    continue;
                }

                BankAccount::updateOrCreate(
                    [
                        'provider_id' => $provider->id,
                        'external_account_id' => (string) $externalAccountId,
                    ],
                    [
                        'user_id' => $user->id,
                        'account_type' => $this->value($item, ['localDetails.type', 'internationalDetails.type', 'title']) ?? 'receiving',
                        'currency' => $this->value($item, ['currency.code', 'currency']) ?? 'USD',
                        'country_code' => $this->value($item, ['bankDetails.country', 'localDetails.country', 'internationalDetails.country']),
                        'bank_name' => $this->value($item, ['localDetails.bankName', 'internationalDetails.bankName', 'title']),
                        'bank_code' => $this->value($item, ['localDetails.sortCode', 'localDetails.bankCode']),
                        'account_name' => $this->value($item, ['title']) ?? $user->full_name,
                        'account_number' => $this->value($item, ['localDetails.accountNumber']),
                        'iban' => $this->value($item, ['internationalDetails.iban']),
                        'swift_bic' => $this->value($item, ['internationalDetails.swiftCode']),
                        'status' => strtolower((string) ($item['status'] ?? 'active')),
                        'is_default' => false,
                        'raw_data' => $item,
                    ]
                );

                $count++;
            }
        });

        return ['synced_accounts' => $count];
    }

    public function syncBalances(IntegrationProvider $provider, User $user): array
    {
        $response = $this->wiseService->get(
            path: $this->wiseService->path(
                (string) config('services.wise.balances_endpoint'),
                ['profile' => $this->wiseService->profileId($user)],
            ),
            query: ['types' => 'STANDARD'],
            user: $user,
        );

        $items = $this->items($response);
        $count = 0;

        DB::transaction(function () use ($items, $provider, $user, &$count): void {
            foreach ($items as $item) {
                $balanceId = $this->value($item, ['id']);
                $currency = $this->value($item, ['currency']);

                if (! filled($balanceId) || ! filled($currency)) {
                    continue;
                }

                $bankAccount = BankAccount::query()
                    ->where('provider_id', $provider->id)
                    ->where('user_id', $user->id)
                    ->where('currency', $currency)
                    ->latest('id')
                    ->first();

                Balance::updateOrCreate(
                    [
                        'provider_id' => $provider->id,
                        'external_account_id' => (string) $balanceId,
                        'currency' => (string) $currency,
                    ],
                    [
                        'user_id' => $user->id,
                        'bank_account_id' => $bankAccount?->id,
                        'available_balance' => Arr::get($item, 'amount.value', 0),
                        'ledger_balance' => Arr::get($item, 'cashAmount.value', Arr::get($item, 'amount.value', 0)),
                        'reserved_balance' => Arr::get($item, 'reservedAmount.value', 0),
                        'as_of' => now(),
                        'raw_data' => $item,
                    ]
                );

                $count++;
            }
        });

        return ['synced_balances' => $count];
    }

    public function syncTransactions(IntegrationProvider $provider, User $user): array
    {
        $balances = Balance::query()
            ->where('provider_id', $provider->id)
            ->where('user_id', $user->id)
            ->get();

        if ($balances->isEmpty()) {
            $this->syncBalances($provider, $user);
            $balances = Balance::query()
                ->where('provider_id', $provider->id)
                ->where('user_id', $user->id)
                ->get();
        }

        $count = 0;

        foreach ($balances as $balance) {
            $response = $this->wiseService->get(
                path: $this->wiseService->path(
                    (string) config('services.wise.transactions_endpoint'),
                    [
                        'profile' => $this->wiseService->profileId($user),
                        'balance' => $balance->external_account_id,
                    ],
                ),
                query: [
                    'currency' => $balance->currency,
                    'intervalStart' => now()->subDays(90)->startOfDay()->toIso8601String(),
                    'intervalEnd' => now()->endOfDay()->toIso8601String(),
                    'type' => 'COMPACT',
                ],
                user: $user,
            );

            $responseData = $response->json() ?? ['raw' => $response->body()];

            if (! $response->successful()) {
                throw new RuntimeException($responseData['message'] ?? 'Wise transaction sync failed.');
            }

            DB::transaction(function () use ($responseData, $provider, $user, $balance, &$count): void {
                foreach ((array) ($responseData['transactions'] ?? []) as $item) {
                    $externalTransactionId = $item['referenceNumber'] ?? null;

                    if (! filled($externalTransactionId)) {
                        continue;
                    }

                    $transfer = Transfer::query()
                        ->where('provider_id', $provider->id)
                        ->where(function ($query) use ($externalTransactionId): void {
                            $query->where('external_transfer_id', $externalTransactionId)
                                ->orWhere('external_payment_id', $externalTransactionId);
                        })
                        ->first();

                    Transaction::updateOrCreate(
                        [
                            'provider_id' => $provider->id,
                            'external_transaction_id' => (string) $externalTransactionId,
                        ],
                        [
                            'user_id' => $user->id,
                            'bank_account_id' => $balance->bank_account_id,
                            'transfer_id' => $transfer?->id,
                            'transaction_type' => Arr::get($item, 'details.type'),
                            'direction' => strtolower((string) ($item['type'] ?? '')),
                            'currency' => Arr::get($item, 'amount.currency', $balance->currency),
                            'amount' => Arr::get($item, 'amount.value', 0),
                            'fee_amount' => Arr::get($item, 'totalFees.value', 0),
                            'description' => Arr::get($item, 'details.description'),
                            'reference_text' => Arr::get($item, 'details.paymentReference'),
                            'status' => 'completed',
                            'booked_at' => $item['date'] ?? now(),
                            'value_date' => $item['date'] ?? now(),
                            'raw_data' => $item,
                        ]
                    );

                    $count++;
                }
            });
        }

        return ['synced_transactions' => $count];
    }

    private function items(Response $response): array
    {
        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful()) {
            throw new RuntimeException($responseData['message'] ?? 'Wise data sync failed.');
        }

        if (is_array($responseData)) {
            return array_is_list($responseData)
                ? $responseData
                : (array) ($responseData['data'] ?? []);
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
}
