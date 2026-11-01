<?php

use Illuminate\Support\Facades\Http;
use PHPUnit\Event\Facade;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use Tests\Support\PayoutPostgresSafety;

require dirname(__DIR__).'/vendor/autoload.php';

// Verify the actual target before any application setup or test migration.
// This bootstrap is dedicated to NiumRemittanceExecutionPostgresTest only.
PayoutPostgresSafety::assertBootstrapConnection();
Facade::instance()->registerSubscriber(new class implements PreparedSubscriber
{
    public function notify(Prepared $event): void
    {
        Http::preventStrayRequests();
    }
});
