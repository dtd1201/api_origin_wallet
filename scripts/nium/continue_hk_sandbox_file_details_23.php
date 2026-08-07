#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\Nium\NiumHkFileStageExitCodes;
use App\Services\Nium\NiumHkSandboxFileStageContinuation;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    if (! $app->environment('staging') || ($argv[1] ?? '') !== '--execute=NIUM_HK_FILE_DETAILS_23_APPROVED') {
        throw new RuntimeException('Exact staging approval marker is required.');
    }
    $status = $app->make(NiumHkSandboxFileStageContinuation::class)->continueDocument23();
    fwrite(STDOUT, $status.PHP_EOL);
    exit(NiumHkFileStageExitCodes::forStatus($status));
} catch (Throwable $exception) {
    $exitCode = NiumHkFileStageExitCodes::forException($exception);
    fwrite(STDERR, 'NIUM_HK_FILE_DETAILS_23_EXIT_'.$exitCode.PHP_EOL);
    exit($exitCode);
}
