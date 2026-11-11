<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\NiumTransferOfflineHttpGuard;
use Tests\Support\Auto02bPostgresTestReset;

abstract class TestCase extends BaseTestCase
{
    public function artisan($command, $parameters = [])
    {
        // Laravel's RefreshDatabase, DatabaseMigrations and DatabaseTruncation
        // all call this exact path. Ordinary PostgreSQL table wipes leave functions.
        if ($command === 'migrate:fresh') {
            $connection = $this->app['db']->connection($parameters['--database'] ?? null);
            if ($connection->getDriverName() === 'pgsql') {
                Auto02bPostgresTestReset::reset($this->app, $connection);
            }
        }

        return parent::artisan($command, $parameters);
    }

    protected function refreshApplication()
    {
        parent::refreshApplication();

        // Cover initial setup and later refreshes before traits/child setup run.
        if (NiumTransferOfflineHttpGuard::isEnabled()) {
            NiumTransferOfflineHttpGuard::install($this->app);
        }
    }
}
