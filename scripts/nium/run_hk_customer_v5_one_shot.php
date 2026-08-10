#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\Nium\NiumHkCustomerV5OneShotRunner;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    if (($argv[1] ?? '') !== '--execute=NIUM_HK_CUSTOMER_V5_POST_4_APPROVED') {
        throw new RuntimeException('Exact human approval marker is required.');
    }

    $prefix = '--execution-root=';
    $argument = (string) ($argv[2] ?? '');

    if (! str_starts_with($argument, $prefix)) {
        throw new RuntimeException('An external execution root is required.');
    }

    $result = $app->make(NiumHkCustomerV5OneShotRunner::class)->run(substr($argument, strlen($prefix)));
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, 'NIUM_HK_CUSTOMER_V5_ONE_SHOT_FAILED_CLOSED'.PHP_EOL);
    exit(1);
}
