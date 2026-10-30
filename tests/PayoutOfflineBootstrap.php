<?php

use Illuminate\Support\Facades\Http;
use PHPUnit\Event\Facade;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

// Run payout regressions without allowing an unfaked Laravel HTTP request.
require dirname(__DIR__).'/vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
    throw new LogicException('Payout regressions require testing mode and an in-memory SQLite database.');
}

Facade::instance()->registerSubscriber(new class implements PreparedSubscriber
{
    public function notify(Prepared $event): void
    {
        Http::preventStrayRequests();
    }
});
