<?php

declare(strict_types=1);

namespace App\Services\Wallet\Concurrency;

use App\Models\Transfer;
use App\Models\UserProviderAccount;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Application adapters: an existing transaction is joined only after registry authentication. */
final class OwnerFirst
{
    public static function users(array $owners, Closure $work, int $attempts = 1, ?OwnerLockContext $context = null): mixed
    {
        $coordinator = app(OwnerFirstTransactions::class);
        $connection = DB::connection();
        if ($context !== null || $connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            $context ??= $coordinator->requireActive($connection, $owners);

            return $coordinator->join($context, $connection, $owners, $work);
        }

        return $coordinator->run($connection, $owners, $work, $attempts);
    }

    public static function transfer(Transfer $transfer, Closure $work, array $additionalOwners = [], ?OwnerLockContext $context = null): mixed
    {
        return self::bound($transfer, $work, $additionalOwners, $context);
    }

    public static function account(UserProviderAccount $account, Closure $work, ?OwnerLockContext $context = null, int $attempts = 1): mixed
    {
        return self::bound($account, $work, [], $context, $attempts);
    }

    public static function outside(): void
    {
        app(OwnerFirstTransactions::class)->assertOutsideTransactions();
    }

    public static function current(array $owners): OwnerLockContext
    {
        return app(OwnerFirstTransactions::class)->requireActive(DB::connection(), $owners);
    }

    private static function bound(Model $model, Closure $work, array $extra, ?OwnerLockContext $context, int $attempts = 1): mixed
    {
        try {
            if ($model->getConnection() !== DB::connection()) {
                throw new OwnerLockContractViolation('Financial model uses an unexpected connection.');
            }
            $discovered = $model->newQuery()->useWritePdo()->findOrFail($model->getKey());
            $owner = $discovered->user_id;
            $provider = $discovered->provider_id;

            return self::users([$owner, ...$extra], function (OwnerLockContext $active) use ($model, $work, $owner, $provider): mixed {
                $locked = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
                if ((string) $locked->user_id !== (string) $owner || (string) $locked->provider_id !== (string) $provider) {
                    throw new OwnerBindingChanged('Financial owner/provider binding changed after discovery.');
                }

                return $work($active);
            }, attempts: $attempts, context: $context);
        } catch (\Throwable $exception) {
            // Discovery/model-connection failures must poison an enclosing authenticated root too.
            try { self::outside(); } catch (OwnerLockContractViolation) {}
            throw $exception;
        }
    }
}
