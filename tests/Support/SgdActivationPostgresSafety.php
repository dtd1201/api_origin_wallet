<?php

namespace Tests\Support;

/** Fixed identity for a NEW network-disabled PostgreSQL 14 container only. */
final class SgdActivationPostgresSafety extends PayoutPostgresSafety
{
    protected const DATABASE = 'origin_sgd_activation_test';

    protected const HOST = '/tmp/origin-sgd-activation-pg14/socket';

    protected const PORT = '55441';

    protected const DATA_DIRECTORY = '/tmp/origin-sgd-activation-pg14/data';
}
