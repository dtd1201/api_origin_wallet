<?php

namespace App\Services\Integrations;

use App\Models\IntegrationProvider;
use App\Models\User;

class ProviderDataSyncManager
{
    public function __construct(
        private readonly ProviderRegistry $registry,
    ) {
    }

    public function syncAccounts(IntegrationProvider $provider, User $user): array
    {
        return $this->registry->resolveDataSyncProvider($provider)->syncAccounts($provider, $user);
    }

    public function syncBalances(IntegrationProvider $provider, User $user): array
    {
        return $this->registry->resolveDataSyncProvider($provider)->syncBalances($provider, $user);
    }

    public function syncTransactions(IntegrationProvider $provider, User $user): array
    {
        return $this->registry->resolveDataSyncProvider($provider)->syncTransactions($provider, $user);
    }
}
