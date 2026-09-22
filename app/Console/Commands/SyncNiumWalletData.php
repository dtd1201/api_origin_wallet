<?php

namespace App\Console\Commands;

use App\Models\IntegrationProvider;
use App\Models\UserProviderAccount;
use App\Services\Integrations\ProviderDataSyncManager;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Console\Command;
use Throwable;

class SyncNiumWalletData extends Command
{
    protected $signature = 'nium:sync-wallet-data
        {--limit= : Maximum number of operational Nium accounts to sync}';

    protected $description = 'Synchronize Nium wallet transactions and balances for operational accounts';

    public function handle(
        ProviderDataSyncManager $manager,
        SensitiveDataSanitizer $sanitizer,
    ): int {
        if (! (bool) config('services.nium.wallet_data_sync_enabled', false)) {
            $this->info('Nium wallet data sync is disabled.');

            return self::SUCCESS;
        }

        $provider = IntegrationProvider::query()
            ->whereRaw('LOWER(code) = ?', ['nium'])
            ->where('status', 'active')
            ->first();

        if ($provider === null) {
            $this->info('No active Nium provider is configured.');

            return self::SUCCESS;
        }

        $configuredLimit = (int) config(
            'services.nium.wallet_data_sync_limit',
            50
        );

        $optionLimit = $this->option('limit');

        $limit = is_numeric($optionLimit)
            ? (int) $optionLimit
            : $configuredLimit;

        $limit = max(1, min($limit, 200));

        $accounts = UserProviderAccount::query()
            ->where('provider_id', $provider->id)
            ->where('status', 'active')
            ->whereRaw('LOWER(provider_status) = ?', ['clear'])
            ->where('reconciliation_status', 'reconciled')
            ->whereNull('security_conflict_at')
            ->whereNotNull('external_customer_id')
            ->where('external_customer_id', '<>', '')
            ->whereNotNull('external_account_id')
            ->where('external_account_id', '<>', '')
            ->whereNotNull('customer_id_verified_at')
            ->whereNotNull('wallet_id_verified_at')
            ->whereNotNull('provider_ids_verified_at')
            ->with('user')
            ->orderByRaw('transactions_last_synced_at IS NOT NULL')
            ->orderBy('transactions_last_synced_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $transactionRuns = 0;
        $balanceRuns = 0;
        $transactionRows = 0;
        $balanceRows = 0;
        $failures = 0;

        foreach ($accounts as $account) {
            if ($account->user === null) {
                $failures++;

                $this->error(
                    "Account {$account->id}: local user is missing."
                );

                continue;
            }

            try {
                $result = $manager->syncTransactions(
                    $provider,
                    $account->user
                );

                $transactionRuns++;
                $transactionRows += (int) (
                    $result['synced_transactions'] ?? 0
                );

                $this->line(
                    "Account {$account->id}: transactions synced."
                );
            } catch (Throwable $exception) {
                $failures++;

                $message = (string) $sanitizer->sanitize(
                    $exception->getMessage()
                );

                $this->error(
                    "Account {$account->id}: transaction sync failed: {$message}"
                );
            }

            try {
                $result = $manager->syncBalances(
                    $provider,
                    $account->user
                );

                $balanceRuns++;
                $balanceRows += (int) (
                    $result['synced_balances'] ?? 0
                );

                $this->line(
                    "Account {$account->id}: balances synced."
                );
            } catch (Throwable $exception) {
                $failures++;

                $message = (string) $sanitizer->sanitize(
                    $exception->getMessage()
                );

                $this->error(
                    "Account {$account->id}: balance sync failed: {$message}"
                );
            }
        }

        $this->info(
            'Nium wallet data sync complete: '.
            "{$accounts->count()} accounts, ".
            "{$transactionRuns} transaction runs / {$transactionRows} rows, ".
            "{$balanceRuns} balance runs / {$balanceRows} rows, ".
            "{$failures} failures."
        );

        return $failures > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
