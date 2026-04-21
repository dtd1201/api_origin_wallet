<?php

namespace App\Services\Tazapay;

use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\Contracts\DataSyncProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TazapayDataSyncService implements DataSyncProvider
{
    public function __construct(
        private readonly TazapayService $tazapayService,
    ) {
    }

    public function syncAccounts(IntegrationProvider $provider, User $user): array
    {
        return ['synced_accounts' => 0];
    }

    public function syncBalances(IntegrationProvider $provider, User $user): array
    {
        $response = $this->tazapayService->get(
            path: (string) config('services.tazapay.balance_endpoint'),
            user: $user,
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];
        $items = $responseData['data']['available'] ?? [];

        if (! $response->successful() || ! is_array($items)) {
            throw new RuntimeException($responseData['message'] ?? 'Tazapay balance sync failed.');
        }

        $count = 0;
        $externalAccountId = (string) (
            config('services.tazapay.account_id')
            ?: config('services.tazapay.tz_account_id')
            ?: optional(
                $user->providerAccounts()->where('provider_id', $provider->id)->latest('id')->first()
            )->external_account_id
        );

        DB::transaction(function () use ($items, $provider, $user, $externalAccountId, &$count): void {
            foreach ($items as $item) {
                $currency = strtoupper((string) ($item['currency'] ?? ''));

                if ($currency === '') {
                    continue;
                }

                $amount = is_numeric($item['amount'] ?? null) ? (float) $item['amount'] : 0.0;

                Balance::updateOrCreate(
                    [
                        'provider_id' => $provider->id,
                        'external_account_id' => $externalAccountId !== '' ? $externalAccountId : null,
                        'currency' => $currency,
                    ],
                    [
                        'user_id' => $user->id,
                        'available_balance' => $amount,
                        'ledger_balance' => $amount,
                        'reserved_balance' => 0,
                        'as_of' => $responseData['data']['updated_at'] ?? now(),
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
        return ['synced_transactions' => 0];
    }
}
