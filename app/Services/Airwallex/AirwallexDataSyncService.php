<?php

namespace App\Services\Airwallex;

use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\Contracts\DataSyncProvider;
use RuntimeException;

class AirwallexDataSyncService implements DataSyncProvider
{
    public function syncAccounts(IntegrationProvider $provider, User $user): array
    {
        throw new RuntimeException('Airwallex API sync is not configured yet.');
    }

    public function syncBalances(IntegrationProvider $provider, User $user): array
    {
        throw new RuntimeException('Airwallex API sync is not configured yet.');
    }

    public function syncTransactions(IntegrationProvider $provider, User $user): array
    {
        throw new RuntimeException('Airwallex API sync is not configured yet.');
    }
}
