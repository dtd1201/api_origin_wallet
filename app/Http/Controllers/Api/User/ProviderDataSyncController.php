<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\ProviderDataSyncManager;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ProviderDataSyncController extends Controller
{
    public function syncAccounts(User $user, IntegrationProvider $provider, ProviderDataSyncManager $manager): JsonResponse
    {
        return $this->respond(function () use ($provider, $user, $manager): array {
            $provider->assertSupportsCapability('data_sync');

            return $manager->syncAccounts($provider, $user);
        }, $provider, 'Accounts synced successfully.');
    }

    public function syncBalances(User $user, IntegrationProvider $provider, ProviderDataSyncManager $manager): JsonResponse
    {
        return $this->respond(function () use ($provider, $user, $manager): array {
            $provider->assertSupportsCapability('data_sync');

            return $manager->syncBalances($provider, $user);
        }, $provider, 'Balances synced successfully.');
    }

    public function syncTransactions(User $user, IntegrationProvider $provider, ProviderDataSyncManager $manager): JsonResponse
    {
        return $this->respond(function () use ($provider, $user, $manager): array {
            $provider->assertSupportsCapability('data_sync');

            return $manager->syncTransactions($provider, $user);
        }, $provider, 'Transactions synced successfully.');
    }

    private function respond(callable $callback, IntegrationProvider $provider, string $message): JsonResponse
    {
        try {
            $result = $callback();
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => $message,
            'provider' => $provider->only(['id', 'code', 'name', 'status']),
            'result' => $result,
        ]);
    }
}
