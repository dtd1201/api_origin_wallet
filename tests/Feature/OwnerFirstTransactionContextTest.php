<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Services\Wallet\Concurrency\OwnerFirstTransactions;
use App\Services\Wallet\Concurrency\OwnerLockContext;
use App\Services\Wallet\Concurrency\OwnerLockContractViolation;
use App\Services\Wallet\Concurrency\OwnerTransactionRollbackOnly;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\DynamicConfirmationDatabaseTestCase;

final class OwnerFirstTransactionContextTest extends DynamicConfirmationDatabaseTestCase
{
    private function coordinator(): OwnerFirstTransactions
    {
        return app(OwnerFirstTransactions::class);
    }

    public function test_sorted_owner_locks_and_context_expiry_after_commit(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $order = [];
        DB::listen(function ($query) use (&$order): void {
            if (str_contains($query->sql, '"users"') && str_contains($query->sql, 'for update')) $order[] = (int) $query->bindings[0];
        });
        $saved = null;
        $this->coordinator()->run(DB::connection(), [$second->id, $first->id, $second->id], function ($context) use (&$saved, $first): void {
            $saved = $context;
            $this->coordinator()->assertActive($context, DB::connection(), [$first->id]);
        });
        $this->assertSame([$first->id, $second->id], $order);
        $this->expectException(OwnerLockContractViolation::class);
        $this->coordinator()->assertActive($saved, DB::connection(), [$first->id]);
    }

    public function test_forged_context_and_additional_owner_cannot_commit_partial_work(): void
    {
        $owner = User::factory()->create(['full_name' => 'Before']);
        $extra = User::factory()->create();
        foreach ([new OwnerLockContext, null] as $forged) {
            try {
                OwnerFirst::users([$owner->id], function ($context) use ($owner, $extra, $forged): void {
                    $owner->update(['full_name' => 'Must roll back']);
                    try {
                        $this->coordinator()->join($forged ?? $context, DB::connection(), [$extra->id], fn () => null);
                    } catch (OwnerLockContractViolation) {
                        // Catching the nested failure cannot authorize a partial commit.
                    }
                });
                $this->fail('Invalid nested authority committed.');
            } catch (OwnerTransactionRollbackOnly) {
                $this->assertSame('Before', $owner->fresh()->full_name);
            }
        }
    }

    public function test_wrong_connection_poisoning_and_raw_pdo_root_rejection(): void
    {
        $owner = User::factory()->create();
        config(['database.connections.patch268c_other' => DB::connection()->getConfig()]);
        try {
            OwnerFirst::users([$owner->id], function ($context) use ($owner): void {
                try {
                    $this->coordinator()->join($context, DB::connection('patch268c_other'), [$owner->id], fn () => null);
                } catch (OwnerLockContractViolation) {}
            });
            $this->fail('A wrong-connection join did not poison the root.');
        } catch (OwnerTransactionRollbackOnly) {
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            DB::purge('patch268c_other');
        }
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        try {
            $this->expectException(OwnerLockContractViolation::class);
            OwnerFirst::users([$owner->id], fn () => null);
        } finally {
            $pdo->rollBack();
        }
    }

    public function test_successful_storage_savepoint_is_supported_but_financial_entry_inside_it_is_rejected(): void
    {
        $owner = User::factory()->create();
        OwnerFirst::users([$owner->id], function ($context) use ($owner): void {
            DB::transaction(fn () => DB::selectOne('SELECT 1'));
            $this->coordinator()->assertActive($context, DB::connection(), [$owner->id]);
        });
        $this->expectException(OwnerLockContractViolation::class);
        OwnerFirst::users([$owner->id], fn ($context) => DB::transaction(
            fn () => $this->coordinator()->join($context, DB::connection(), [$owner->id], fn () => null),
        ));
    }

    public function test_savepoint_rollback_forces_complete_root_rollback(): void
    {
        $owner = User::factory()->create(['full_name' => 'Before']);
        try {
            OwnerFirst::users([$owner->id], function () use ($owner): void {
                $owner->update(['full_name' => 'Must roll back']);
                try {
                    DB::transaction(function (): void { throw new RuntimeException('storage failure'); });
                } catch (RuntimeException) {}
            });
            $this->fail('A savepoint rollback allowed financial success.');
        } catch (OwnerTransactionRollbackOnly) {
            $this->assertSame('Before', $owner->fresh()->full_name);
        }
    }

    public static function retryStates(): array
    {
        return ['deadlock fault' => ['40P01'], 'serialization fault' => ['40001']];
    }

    #[DataProvider('retryStates')]
    public function test_local_retry_has_a_new_context_and_discards_failed_attempt_changes(string $state): void
    {
        $owner = User::factory()->create(['full_name' => 'Before']);
        $attempts = 0;
        $contexts = [];
        $this->coordinator()->run(DB::connection(), [$owner->id], function ($context) use ($owner, $state, &$attempts, &$contexts): void {
            $contexts[] = $context;
            $attempts++;
            if ($attempts === 1) {
                $owner->update(['full_name' => 'Rolled back']);
                DB::statement("DO $$ BEGIN RAISE EXCEPTION 'injected local fault' USING ERRCODE = '$state'; END $$");
            }
            $this->assertSame('Before', $owner->fresh()->full_name);
        }, 3);
        $this->assertSame(2, $attempts);
        $this->assertNotSame($contexts[0], $contexts[1]);
        foreach ($contexts as $context) {
            try {
                $this->coordinator()->assertActive($context, DB::connection(), [$owner->id]);
                $this->fail('Expired retry context remained valid.');
            } catch (OwnerLockContractViolation) { $this->addToAssertionCount(1); }
        }
    }

    public function test_rollback_work_runs_after_complete_root_rollback_in_a_fresh_context(): void
    {
        $owner = User::factory()->create(['full_name' => 'Before']);
        $callbackRan = false;
        try {
            OwnerFirst::users([$owner->id], function ($context) use ($owner, &$callbackRan): void {
                $owner->update(['full_name' => 'Must roll back']);
                $this->coordinator()->afterRollback($context, function () use ($owner, &$callbackRan): void {
                    $this->assertSame(0, DB::transactionLevel());
                    $this->assertFalse(DB::connection()->getPdo()->inTransaction());
                    $this->assertSame('Before', $owner->fresh()->full_name);
                    OwnerFirst::users([$owner->id], fn () => $owner->fresh()->update(['full_name' => 'Failure receipt']));
                    $callbackRan = true;
                });
                throw new RuntimeException('root must roll back');
            });
            $this->fail('Failed financial work committed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('root must roll back', $exception->getMessage());
        }
        $this->assertTrue($callbackRan);
        $this->assertSame('Failure receipt', $owner->fresh()->full_name);
    }

    public function test_caught_forged_rollback_registration_cannot_allow_partial_commit(): void
    {
        $owner = User::factory()->create(['full_name' => 'Before']);
        try {
            OwnerFirst::users([$owner->id], function () use ($owner): void {
                $owner->update(['full_name' => 'Must roll back']);
                try {
                    $this->coordinator()->afterRollback(new OwnerLockContext, fn () => null);
                } catch (OwnerLockContractViolation) {}
            });
            $this->fail('Forged rollback authority did not poison the root.');
        } catch (OwnerTransactionRollbackOnly) {
            $this->assertSame('Before', $owner->fresh()->full_name);
        }
    }

    public function test_provider_guard_checks_secondary_connections(): void
    {
        config(['database.connections.patch268c_other' => DB::connection()->getConfig()]);
        $other = DB::connection('patch268c_other');
        $other->beginTransaction();
        try {
            $this->expectException(OwnerLockContractViolation::class);
            OwnerFirst::outside();
        } finally {
            $other->rollBack();
            DB::purge('patch268c_other');
        }
    }
}
