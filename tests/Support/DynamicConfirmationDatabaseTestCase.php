<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

abstract class DynamicConfirmationDatabaseTestCase extends TestCase
{
    use DatabaseMigrations;

    private bool $offlineFakesInstalled = false;

    public function runDatabaseMigrations()
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();

        // Keep tests outside a wrapping transaction for forked PostgreSQL workers.
        // Never invoke down(): the VA ledger migration is intentionally forward-only.
        $this->beforeApplicationDestroyed(function (): void {
            Auto02bPostgresTestReset::reset($this->app, DB::connection());
        });
    }

    protected function beforeRefreshingDatabase(): void
    {
        Auto02bPostgresTestReset::assertSafe($this->app, DB::connection());
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([]);
        Queue::fake();
        Bus::fake();
        $this->offlineFakesInstalled = true;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->offlineFakesInstalled) {
                Http::assertNothingSent();
                Queue::assertNothingPushed();
                Bus::assertNothingDispatched();
            }
        } finally {
            parent::tearDown();
        }
    }
}
