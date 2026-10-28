<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NiumVirtualAccountSafetyLedgerMigrationTest extends TestCase
{
    public function test_additive_migration_preserves_existing_schema_and_legacy_rows_without_backfill(): void
    {
        $originalConnection = DB::getDefaultConnection();
        config(['database.connections.nium_va_existing_schema' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('nium_va_existing_schema');

        try {
            foreach (['user_provider_accounts', 'nium_virtual_accounts', 'api_request_logs', 'webhook_events'] as $table) {
                Schema::create($table, fn (Blueprint $blueprint) => $blueprint->id());
            }
            Schema::create('nium_api_exchange_evidence', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('api_request_log_id');
            });
            DB::table('user_provider_accounts')->insert(['id' => 6]);
            $existingMigration = require database_path('migrations/2026_10_20_000001_create_nium_virtual_account_operations_table.php');
            $existingMigration->up();
            foreach (['preflight_failed', 'submitting', 'unknown', 'pending', 'assigned', 'failed'] as $state) {
                DB::table('nium_virtual_account_operations')->insert([
                    'user_provider_account_id' => 6, 'operation_id' => 'legacy-'.$state,
                    'currency' => 'USD', 'account_category' => 'COLLECTION_ACCOUNT', 'account_type' => 'LOCAL',
                    'status' => $state, 'claimed_at' => '2026-10-20 00:00:00',
                ]);
            }
            $columns = Schema::getColumnListing('nium_virtual_account_operations');
            $rows = DB::table('nium_virtual_account_operations')->orderBy('id')->get()->all();

            $migration = require database_path('migrations/2026_10_27_000001_create_nium_va_safety_ledger.php');
            $migration->up();

            $this->assertSame($columns, Schema::getColumnListing('nium_virtual_account_operations'));
            $this->assertEquals($rows, DB::table('nium_virtual_account_operations')->orderBy('id')->get()->all());
            foreach (['nium_va_requests', 'nium_va_assignment_scopes', 'nium_va_dispatch_attempts'] as $table) {
                $this->assertTrue(Schema::hasTable($table));
                $this->assertSame(0, DB::table($table)->count());
            }
            try {
                $migration->down();
                $this->fail('Even empty safety tables must use forward-only recovery.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('rollback is forbidden', $exception->getMessage());
            }
            DB::table('nium_virtual_account_operations')->where('operation_id', 'legacy-pending')->update(['resolution_code' => 'still_legacy']);
            $this->assertSame('still_legacy', DB::table('nium_virtual_account_operations')->where('operation_id', 'legacy-pending')->value('resolution_code'));
        } finally {
            DB::purge('nium_va_existing_schema');
            DB::setDefaultConnection($originalConnection);
        }
    }
}
