<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Auto02bPostgresTestReset;
use Tests\TestCase;

final class Auto02bPostgresRefreshDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        Auto02bPostgresTestReset::assertSafe($this->app, DB::connection());
        // Reproduce a fresh process or the cache invalidation between migration
        // test classes. The preceding cycle's committed guard functions still exist.
        RefreshDatabaseState::$migrated = false;
    }

    public static function cycles(): array
    {
        return ['first migration cycle' => [1], 'second migration cycle' => [2]];
    }

    #[DataProvider('cycles')]
    public function test_real_refresh_database_lifecycle_reinstalls_guard_functions(int $cycle): void
    {
        $this->assertTrue(RefreshDatabaseState::$migrated);
        $this->assertSame(1, DB::transactionLevel());
        $this->assertNotNull(DB::selectOne("SELECT to_regprocedure('public.nium_va_request_insert_guard()') AS name")->name,
            'RefreshDatabase cycle '.$cycle.' must reinstall the real VA guard.');
        $this->assertSame(1, DB::table('migrations')->where('migration', '2026_10_27_000001_create_nium_va_safety_ledger')->count());
    }
}
