<?php

namespace Tests\Unit;

use Tests\Support\SgdActivationPostgresSafety;

/** Run every existing connection/identity refusal against the new fixed target too. */
class SgdActivationPostgresSafetyTest extends PayoutPostgresSafetyTest
{
    protected function safety(): string
    {
        return SgdActivationPostgresSafety::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['DB_HOST' => '/tmp/origin-sgd-activation-pg14/socket', 'DB_PORT' => '55441',
            'DB_DATABASE' => 'origin_sgd_activation_test'] as $key => $value) {
            putenv($key.'='.$value);
        }
    }

    protected function configuration(): array
    {
        return array_replace(parent::configuration(), ['host' => '/tmp/origin-sgd-activation-pg14/socket',
            'port' => '55441', 'database' => 'origin_sgd_activation_test']);
    }

    protected function identity(): array
    {
        return array_replace(parent::identity(), ['database' => 'origin_sgd_activation_test', 'port' => '55441',
            'socket_directories' => '/tmp/origin-sgd-activation-pg14/socket',
            'data_directory' => '/tmp/origin-sgd-activation-pg14/data']);
    }
}
