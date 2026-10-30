<?php

use Illuminate\Support\Facades\Http;
use PHPUnit\Event\Facade;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

require dirname(__DIR__).'/vendor/autoload.php';

// This guard runs before any application/database setup or test migration.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'pgsql'
    || getenv('DB_HOST') !== '/tmp/origin-wallet-p0-pg' || getenv('DB_PORT') !== '55439'
    || getenv('DB_DATABASE') !== 'nium_p0_execution_test' || getenv('DB_URL') !== '') {
    throw new LogicException('Payment concurrency tests require their disposable local PostgreSQL socket/database.');
}
Facade::instance()->registerSubscriber(new class implements PreparedSubscriber
{
    public function notify(Prepared $event): void
    {
        Http::preventStrayRequests();
    }
});
