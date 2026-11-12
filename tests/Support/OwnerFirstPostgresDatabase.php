<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

/** Real committed roots; the same disposable PostgreSQL allowlist as the financial race harness. */
trait OwnerFirstPostgresDatabase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        Auto02bPostgresTestReset::assertSafe($this->app, DB::connection());
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            Auto02bPostgresTestReset::reset($this->app, DB::connection());
        });
    }
}
