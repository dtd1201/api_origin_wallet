<?php

namespace App\Services\Currenxie;

use App\Models\Balance;
use App\Models\BankAccount;
use App\Models\IntegrationProvider;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Integrations\Contracts\DataSyncProvider;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Support\Facades\DB;

class CurrenxieDataSyncService implements DataSyncProvider
{
    private function client(IntegrationProvider $provider): ProviderHttpClient
    {
        return new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'currenxie',
            headers: $this->requestHeaders(),
        );
    }

    private function requestHeaders(): array
    {
        if (strtolower((string) config('services.currenxie.auth.mode', 'static_headers')) !== 'static_headers') {
            return [];
        }

        return [
            'X-API-KEY' => (string) config('services.currenxie.api_key'),
            'X-API-SECRET' => (string) config('services.currenxie.api_secret'),
        ];
    }

    public function syncAccounts(IntegrationProvider $provider, User $user): array
    {
        $response = $this->client($provider)->post(
            path: (string) config('services.currenxie.accounts_sync_endpoint'),
            payload: ['user_reference' => (string) $user->id, 'email' => $user->email],
            user: $user,
        );

        $items = $response->json('data') ?? $response->json() ?? [];

        $count = 0;

        DB::transaction(function () use ($items, $provider, $user, &$count): void {
            foreach ($items as $item) {
                $externalAccountId = $item['id'] ?? $item['account_id'] ?? null;

                if ($externalAccountId === null) {
                    continue;
                }

                BankAccount::updateOrCreate(
                    [
                        'provider_id' => $provider->id,
                        'external_account_id' => $externalAccountId,
                    ],
                    [
                        'user_id' => $user->id,
                        'currency' => $item['currency'] ?? 'USD',
                        'country_code' => $item['country_code'] ?? null,
                        'bank_name' => $item['bank_name'] ?? null,
                        'account_name' => $item['account_name'] ?? $user->full_name,
                        'account_number' => $item['account_number'] ?? null,
                        'iban' => $item['iban'] ?? null,
                        'swift_bic' => $item['swift_bic'] ?? null,
                        'status' => $item['status'] ?? 'active',
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
        $response = $this->client($provider)->post(
            path: (string) config('services.currenxie.balances_sync_endpoint'),
            payload: ['user_reference' => (string) $user->id, 'email' => $user->email],
            user: $user,
        );

        $items = $response->json('data') ?? $response->json() ?? [];
        $count = 0;

        DB::transaction(function () use ($items, $provider, $user, &$count): void {
            foreach ($items as $item) {
                $externalAccountId = $item['account_id'] ?? $item['external_account_id'] ?? null;
                $currency = $item['currency'] ?? null;

                if ($externalAccountId === null || $currency === null) {
                    continue;
                }

                $bankAccount = BankAccount::query()
                    ->where('provider_id', $provider->id)
                    ->where('external_account_id', $externalAccountId)
                    ->first();

                Balance::updateOrCreate(
                    [
                        'provider_id' => $provider->id,
                        'external_account_id' => $externalAccountId,
                        'currency' => $currency,
                    ],
                    [
                        'user_id' => $user->id,
                        'bank_account_id' => $bankAccount?->id,
                        'available_balance' => $item['available_balance'] ?? 0,
                        'ledger_balance' => $item['ledger_balance'] ?? 0,
                        'reserved_balance' => $item['reserved_balance'] ?? 0,
                        'as_of' => $item['as_of'] ?? now(),
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
        $response = $this->client($provider)->post(
            path: (string) config('services.currenxie.transactions_sync_endpoint'),
            payload: ['user_reference' => (string) $user->id, 'email' => $user->email],
            user: $user,
        );

        $items = $response->json('data') ?? $response->json() ?? [];
        $count = 0;

        DB::transaction(function () use ($items, $provider, $user, &$count): void {
            foreach ($items as $item) {
                $externalTransactionId = $item['id'] ?? $item['transaction_id'] ?? null;

                if ($externalTransactionId === null) {
                    continue;
                }

                $bankAccount = null;

                if (isset($item['account_id'])) {
                    $bankAccount = BankAccount::query()
                        ->where('provider_id', $provider->id)
                        ->where('external_account_id', $item['account_id'])
                        ->first();
                }

                Transaction::updateOrCreate(
                    [
                        'provider_id' => $provider->id,
                        'external_transaction_id' => $externalTransactionId,
                    ],
                    [
                        'user_id' => $user->id,
                        'bank_account_id' => $bankAccount?->id,
                        'transaction_type' => $item['transaction_type'] ?? null,
                        'direction' => $item['direction'] ?? null,
                        'currency' => $item['currency'] ?? 'USD',
                        'amount' => $item['amount'] ?? 0,
                        'fee_amount' => $item['fee_amount'] ?? 0,
                        'description' => $item['description'] ?? null,
                        'reference_text' => $item['reference_text'] ?? null,
                        'status' => $item['status'] ?? null,
                        'booked_at' => $item['booked_at'] ?? null,
                        'value_date' => $item['value_date'] ?? null,
                        'raw_data' => $item,
                    ]
                );

                $count++;
            }
        });

        return ['synced_transactions' => $count];
    }
}
