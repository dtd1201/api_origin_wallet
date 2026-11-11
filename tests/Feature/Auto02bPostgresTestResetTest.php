<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\Auto02bPostgresTestReset;
use Tests\Support\DynamicConfirmationDatabaseTestCase;

final class Auto02bPostgresTestResetTest extends DynamicConfirmationDatabaseTestCase
{
    public function test_repeated_fresh_migrations_remove_orphans_and_reinstall_real_va_guards(): void
    {
        for ($cycle = 0; $cycle < 2; $cycle++) {
            DB::unprepared('CREATE FUNCTION public.auto02b_orphan_probe() RETURNS integer LANGUAGE sql AS \'SELECT 1\'');
            // Forked tests disconnect/purge PDOs; missing PDO must be reconnected
            // only after configuration checks and before server identity checks.
            DB::disconnect();

            // Exactly the command entry point used by Laravel RefreshDatabase.
            $this->artisan('migrate:fresh')->assertSuccessful();

            $this->assertNull(DB::selectOne("SELECT to_regprocedure('public.auto02b_orphan_probe()') AS name")->name);
            $this->assertNotNull(DB::selectOne("SELECT to_regprocedure('public.nium_va_request_insert_guard()') AS name")->name);
            $this->assertNotNull(DB::selectOne("SELECT to_regprocedure('public.nium_va_projection_mirror()') AS name")->name);
            $this->assertSame(1, DB::table('migrations')->where('migration', '2026_10_27_000001_create_nium_va_safety_ledger')->count());
            $this->assertSame(1, (int) DB::selectOne(<<<'SQL'
SELECT count(*) AS total FROM pg_trigger
WHERE tgrelid = 'public.nium_va_requests'::regclass
  AND tgname = 'nium_va_request_insert_guard' AND NOT tgisinternal AND tgenabled = 'O'
SQL)->total);
            $this->assertInsertGuardStillRejectsInvalidRequests();
        }
    }

    public function test_reset_clears_laravel_migration_cache_and_all_schema_functions(): void
    {
        RefreshDatabaseState::$migrated = true;
        Auto02bPostgresTestReset::reset($this->app, DB::connection());

        $this->assertFalse(RefreshDatabaseState::$migrated);
        $this->assertFalse(Schema::hasTable('migrations'));
        $this->assertNull(DB::selectOne("SELECT to_regprocedure('public.nium_va_request_insert_guard()') AS name")->name);

        $this->artisan('migrate:fresh')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('nium_va_requests'));
        $this->assertInsertGuardStillRejectsInvalidRequests();
    }

    public function test_va_safety_migration_remains_forward_only(): void
    {
        $migration = require database_path('migrations/2026_10_27_000001_create_nium_va_safety_ledger.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nium VA ledger rollback is forbidden');
        $migration->down();
    }

    private function assertInsertGuardStillRejectsInvalidRequests(): void
    {
        try {
            DB::transaction(static function (): void {
                DB::table('nium_va_requests')->insert([
                    'operation_id' => 1, 'scope_key' => str_repeat('a', 64), 'snapshot' => 'fixture-only',
                    'payload_sha256' => str_repeat('b', 64), 'provider_request_id' => '00000000-0000-4000-8000-000000000001',
                    'state_version' => 1, 'operation_state' => 'claimed', 'created_at' => now(), 'updated_at' => now(),
                ]);
            });
            $this->fail('A recreated VA insert guard accepted an invalid initial state.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) $exception->getCode());
            $this->assertStringContainsString('Nium VA safety ledger invariant: nium_va_request_insert_guard', $exception->getMessage());
        }
    }
}
