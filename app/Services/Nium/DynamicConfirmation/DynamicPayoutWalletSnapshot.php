<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumProviderAccountStateService;
use App\Services\Nium\NiumService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Request;
use RuntimeException;

/** Authenticated observation time is distinct from provider as-of time. Never imports a float. */
final class DynamicPayoutWalletSnapshot
{
    public function __construct(private readonly NiumService $nium,
        private readonly NiumProviderAccountStateService $accounts) {}

    public function refresh(User $user, Balance $wallet, ?string $exceptIntent = null): array
    {
        OwnerFirst::outside();
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('dynamic_wallet_refresh_requires_network_outside_transaction');
        }
        $account = $this->accounts->assertEligible($user);
        $scope = $this->scope($user, $wallet, $account);
        $credential = $this->credential();
        DynamicPayoutEnvironment::configured()->assertContract('wallet');
        $path = $scope['request_path'];
        $started = now();
        $authenticated = false;
        $response = $this->nium->get(path: $path, query: [], user: $user, operation: 'dynamic_wallet_availability',
            externalReference: (string) $wallet->id,
            beforeDispatch: function (Request $request) use ($user, $wallet, $account, $scope, $credential, &$authenticated): void {
                OwnerFirst::outside();
                DynamicPayoutEnvironment::configured()->assertBinding($scope['origin']);
                DynamicPayoutEnvironment::configured()->assertContract('wallet');
                if ($authenticated || $this->scope($user, $wallet, $account) !== $scope
                    || ! hash_equals($credential, $this->credential())
                    || $request->method() !== 'GET' || $request->url() !== $scope['origin'].$scope['request_path']
                    || $request->toPsrRequest()->getHeader('x-api-key') !== [$credential]) {
                    throw new RuntimeException('dynamic_wallet_snapshot_binding_changed');
                }
                $authenticated = true;
            });
        if (! $authenticated || ! $response->successful() || now()->greaterThanOrEqualTo($started->copy()->addSeconds(30))
            || strlen($response->body()) > 1048576) {
            throw new RuntimeException('dynamic_wallet_authenticated_snapshot_unavailable');
        }
        $snapshot = ['version' => 'nium-dynamic-authenticated-wallet-v1', ...$scope,
            'available' => DynamicWalletResponse::available($response->body(), $scope),
            'requested_at' => $started->toISOString(),
            'observed_at' => now()->toISOString(), 'expires_at' => $started->copy()->addSeconds(30)->toISOString(),
            'response_sha256' => hash('sha256', $response->body())];

        return OwnerFirst::users([$user->id], function () use ($user, $wallet, $account, $scope, $credential, $snapshot, $exceptIntent): array {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            $currentAccount = UserProviderAccount::query()->lockForUpdate()->findOrFail($account->id);
            $current = Balance::query()->lockForUpdate()->findOrFail($wallet->id);
            if ($this->scope($owner, $current, $currentAccount) !== $scope
                || ! hash_equals($credential, $this->credential())
                || $currentAccount->status !== 'active' || $currentAccount->security_conflict_at !== null
                || now()->greaterThanOrEqualTo($snapshot['expires_at'])) {
                throw new RuntimeException('dynamic_wallet_snapshot_binding_changed');
            }
            if ((string) $this->accounts->assertEligible($owner)->id !== $scope['account_id']) {
                throw new RuntimeException('dynamic_wallet_snapshot_binding_changed');
            }
            DynamicPayoutGate::assertWalletClear((int) $current->provider_id, $scope['wallet'], $exceptIntent);
            if (now()->greaterThanOrEqualTo($snapshot['expires_at'])) {
                throw new RuntimeException('dynamic_wallet_snapshot_binding_changed');
            }
            // Observations constrain admission, not journal balances. Persisting
            // a minimum here loses legitimate funds when a later observation rises.
            $raw = $current->raw_data ?? [];
            $raw['provider_available_balance'] = $snapshot['available'];
            $raw['dynamic_authenticated_availability'] = $snapshot;
            DynamicPayoutDispatcher::persist($current->forceFill(['raw_data' => $raw]));
            return $snapshot;
        }, 3);
    }

    private function credential(): string
    {
        $credential = config('services.nium.auth.header_value');
        if (strtolower((string) config('services.nium.auth.mode')) !== 'header'
            || strtolower(trim((string) config('services.nium.auth.header_name'))) !== 'x-api-key'
            || ! is_string($credential) || trim($credential) === '') {
            throw new RuntimeException('dynamic_wallet_authenticated_credentials_unavailable');
        }

        return $credential;
    }

    private function scope(User $user, Balance $wallet, UserProviderAccount $account): array
    {
        $base = DynamicPayoutEnvironment::configured()->origin;
        $provider = IntegrationProvider::query()->find($account->provider_id);
        if ((string) $wallet->user_id !== (string) $user->id || (string) $account->user_id !== (string) $user->id
            || $provider === null || $provider->code !== 'nium' || $provider->status !== 'active'
            || (string) $wallet->provider_id !== (string) $account->provider_id || $wallet->currency !== 'USD'
            || $wallet->external_account_id !== $account->external_account_id
            || ! is_string($account->external_customer_id) || $account->external_customer_id === ''
            || ! is_string($wallet->external_account_id) || $wallet->external_account_id === '') {
            throw new RuntimeException('dynamic_wallet_snapshot_binding_changed');
        }
        $client = $this->nium->clientId();
        $path = $this->nium->path('/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}',
            ['client' => $client, 'customer' => $account->external_customer_id, 'wallet' => $wallet->external_account_id]);

        return ['origin' => $base, 'client' => $client, 'customer' => $account->external_customer_id,
            'wallet' => $wallet->external_account_id, 'balance_id' => (string) $wallet->id,
            'account_id' => (string) $account->id, 'owner' => (string) $user->id,
            'provider_id' => (string) $account->provider_id, 'currency' => $wallet->currency, 'request_path' => $path];
    }
}
