<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Models\Balance;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Services\Nium\NiumProviderAccountStateService;
use App\Services\Nium\NiumService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Authenticated observation time is distinct from provider as-of time. Never imports a float. */
final class DynamicPayoutWalletSnapshot
{
    public function __construct(private readonly NiumService $nium,
        private readonly NiumProviderAccountStateService $accounts) {}

    public function refresh(User $user, Balance $wallet, ?string $exceptIntent = null): array
    {
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('dynamic_wallet_refresh_requires_network_outside_transaction');
        }
        $account = $this->accounts->assertEligible($user);
        $scope = $this->scope($user, $wallet, $account);
        $path = $this->nium->path('/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}',
            ['client' => $scope['client'], 'customer' => $scope['customer'], 'wallet' => $scope['wallet']]);
        $started = now();
        $response = $this->nium->get(path: $path, user: $user, operation: 'dynamic_wallet_availability', externalReference: (string) $wallet->id);
        if (! $response->successful() || $started->copy()->addSeconds(30)->isPast() || strlen($response->body()) > 1048576) {
            throw new RuntimeException('dynamic_wallet_authenticated_snapshot_unavailable');
        }
        $snapshot = self::parse($response->body(), $scope);
        $snapshot += ['observed_at' => now()->toISOString(), 'expires_at' => $started->copy()->addSeconds(30)->toISOString(),
            'response_sha256' => hash('sha256', $response->body())];

        return DB::transaction(function () use ($user, $wallet, $account, $scope, $snapshot, $exceptIntent): array {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            $currentAccount = UserProviderAccount::query()->lockForUpdate()->findOrFail($account->id);
            $current = Balance::query()->lockForUpdate()->findOrFail($wallet->id);
            if ($this->scope($owner, $current, $currentAccount) !== $scope
                || $currentAccount->status !== 'active' || $currentAccount->security_conflict_at !== null
                || now()->greaterThanOrEqualTo($snapshot['expires_at'])) {
                throw new RuntimeException('dynamic_wallet_snapshot_binding_changed');
            }
            DynamicPayoutGate::assertWalletClear((int) $wallet->provider_id, $scope['wallet'], $exceptIntent);
            $reserved = BigDecimal::of(DynamicReservationLedger::storage($current->reserved_balance));
            $ceiling = BigDecimal::of($snapshot['available'])->minus($reserved);
            $local = BigDecimal::of(DynamicReservationLedger::storage($current->available_balance));
            $ledgerCeiling = BigDecimal::of(DynamicReservationLedger::storage($current->ledger_balance))->minus($reserved);
            // A provider refresh cannot increase local funds, overwrite holds, or
            // apply the provider's debit to the local ledger for a second time.
            $available = BigDecimal::min($local, $ceiling, $ledgerCeiling);
            if ($available->isNegative()) {
                $available = BigDecimal::zero();
            }
            $raw = $current->raw_data ?? [];
            $raw['provider_available_balance'] = $snapshot['available'];
            $raw['dynamic_authenticated_availability'] = $snapshot;
            DynamicPayoutDispatcher::persist($current->forceFill(['raw_data' => $raw,
                'available_balance' => DynamicReservationLedger::storage((string) $available)]));
            return $snapshot;
        }, 3);
    }

    public static function parse(string $body, array $scope): array
    {
        $data = ExactProviderJson::decode($body);
        $data = $data['data'] ?? $data;
        if (! is_array($data) || ($data['walletHashId'] ?? null) !== $scope['wallet']) {
            throw new RuntimeException('dynamic_wallet_response_scope_unverified');
        }
        foreach (['clientHashId' => 'client', 'customerHashId' => 'customer'] as $field => $key) {
            if (isset($data[$field]) && $data[$field] !== $scope[$key]) {
                throw new RuntimeException('dynamic_wallet_response_scope_unverified');
            }
        }
        $rows = $data['balances'] ?? $data['currencies'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('dynamic_wallet_response_contract_unavailable');
        }
        $usd = array_values(array_filter($rows, static fn ($row): bool => is_array($row)
            && ($row['currency'] ?? $row['currencyCode'] ?? $row['curSymbol'] ?? null) === 'USD'));
        if (count($usd) !== 1 || ! is_string($usd[0]['availableBalance'] ?? null)) {
            throw new RuntimeException('dynamic_wallet_usd_availability_unavailable');
        }
        return ['version' => 'nium-dynamic-authenticated-wallet-v1', ...$scope,
            'available' => DynamicReservationLedger::storage($usd[0]['availableBalance'])];
    }

    private function scope(User $user, Balance $wallet, UserProviderAccount $account): array
    {
        $base = rtrim((string) config('services.nium.base_url'), '/');
        if (! in_array($base, ['https://gatewaysandbox.nium.com', 'https://gateway.nium.com'], true)
            || (string) $wallet->user_id !== (string) $user->id || (string) $account->user_id !== (string) $user->id
            || (string) $wallet->provider_id !== (string) $account->provider_id || $wallet->currency !== 'USD'
            || $wallet->external_account_id !== $account->external_account_id
            || ! is_string($account->external_customer_id) || $account->external_customer_id === ''
            || ! is_string($wallet->external_account_id) || $wallet->external_account_id === '') {
            throw new RuntimeException('dynamic_wallet_snapshot_binding_changed');
        }
        return ['origin' => $base, 'client' => $this->nium->clientId(), 'customer' => $account->external_customer_id,
            'wallet' => $wallet->external_account_id, 'balance_id' => (string) $wallet->id,
            'account_id' => (string) $account->id, 'owner' => (string) $user->id];
    }
}
