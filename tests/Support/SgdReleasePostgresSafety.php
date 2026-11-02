<?php

namespace Tests\Support;

/** A separate fixed identity; never reuses the historical Phase 4 cluster. */
final class SgdReleasePostgresSafety extends PayoutPostgresSafety
{
    protected const DATABASE = 'origin_sgd_release_test';

    protected const HOST = '/tmp/origin-sgd-release-pg/socket';

    protected const PORT = '55440';

    protected const DATA_DIRECTORY = '/tmp/origin-sgd-release-pg/data';
}
