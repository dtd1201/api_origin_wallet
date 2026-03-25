<?php

namespace App\Services\Wise;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\Contracts\DataSyncProvider;
use RuntimeException;

class WiseDataSyncService implements DataSyncProvider
{
    public function syncAccounts(IntegrationProvider $provider, User $user): array
    {
        throw new RuntimeException('Wise API sync is not configured yet.');
    }

    public function syncBalances(IntegrationProvider $provider, User $user): array
    {
        throw new RuntimeException('Wise API sync is not configured yet.');
    }

    public function syncTransactions(IntegrationProvider $provider, User $user): array
    {
        throw new RuntimeException('Wise API sync is not configured yet.');
    }
}
