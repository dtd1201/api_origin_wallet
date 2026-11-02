<?php

use Illuminate\Support\Facades\Http;
use PHPUnit\Event\Facade;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use Tests\Support\SgdReleasePostgresSafety;

require dirname(__DIR__).'/vendor/autoload.php';

SgdReleasePostgresSafety::assertBootstrapConnection();
Facade::instance()->registerSubscriber(new class implements PreparedSubscriber
{
    public function notify(Prepared $event): void
    {
        if (Http::getFacadeRoot() !== null) {
            Http::preventStrayRequests();
        }
    }
});
