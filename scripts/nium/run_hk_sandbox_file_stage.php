#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\Nium\NiumHkFileStageExitCodes;
use App\Services\Nium\NiumHkSandboxFileStageRunner;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    if (! $app->environment('staging')) {
        throw new RuntimeException('The HK Sandbox file-stage runner is staging-only.');
    }

    if (($argv[1] ?? '') !== '--execute=NIUM_HK_FILE_STAGE_APPROVED') {
        throw new RuntimeException('Exact human approval marker is required.');
    }

    $results = $app->make(NiumHkSandboxFileStageRunner::class)->run();
    fwrite(STDOUT, json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    exit(NiumHkFileStageExitCodes::PASS);
} catch (Throwable $exception) {
    $exitCode = NiumHkFileStageExitCodes::forException($exception);
    fwrite(STDERR, 'NIUM_HK_FILE_STAGE_EXIT_'.$exitCode.PHP_EOL);
    exit($exitCode);
}
