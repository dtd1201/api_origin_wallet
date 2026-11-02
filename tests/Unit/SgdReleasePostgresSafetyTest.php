<?php

namespace Tests\Unit;

use Tests\Support\SgdReleasePostgresSafety;

/** Run every existing connection/identity refusal against the new fixed target too. */
class SgdReleasePostgresSafetyTest extends PayoutPostgresSafetyTest
{
    protected function safety(): string
    {
        return SgdReleasePostgresSafety::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['DB_HOST' => '/tmp/origin-sgd-release-pg/socket', 'DB_PORT' => '55440',
            'DB_DATABASE' => 'origin_sgd_release_test'] as $key => $value) {
            putenv($key.'='.$value);
        }
    }

    protected function configuration(): array
    {
        return array_replace(parent::configuration(), ['host' => '/tmp/origin-sgd-release-pg/socket',
            'port' => '55440', 'database' => 'origin_sgd_release_test']);
    }

    protected function identity(): array
    {
        return array_replace(parent::identity(), ['database' => 'origin_sgd_release_test', 'port' => '55440',
            'socket_directories' => '/tmp/origin-sgd-release-pg/socket',
            'data_directory' => '/tmp/origin-sgd-release-pg/data']);
    }
}
