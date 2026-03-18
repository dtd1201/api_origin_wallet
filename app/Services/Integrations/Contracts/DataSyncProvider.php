<?php

namespace App\Services\Integrations\Contracts;

use App\Models\IntegrationProvider;
use App\Models\User;

interface DataSyncProvider
{
    public function syncAccounts(IntegrationProvider $provider, User $user): array;

    public function syncBalances(IntegrationProvider $provider, User $user): array;

    public function syncTransactions(IntegrationProvider $provider, User $user): array;
}
