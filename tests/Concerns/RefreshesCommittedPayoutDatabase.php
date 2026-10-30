<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use LogicException;

trait RefreshesCommittedPayoutDatabase
{
    use RefreshDatabase;

    protected function refreshTestDatabase(): void
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new LogicException('Committed payout fixtures require an in-memory testing database.');
        }
        // A test wrapper transaction would hide the production commit boundary.
        $this->artisan('migrate:fresh', $this->migrateFreshUsing())->assertExitCode(0);
        $this->app[Kernel::class]->setArtisan(null);
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }
}
