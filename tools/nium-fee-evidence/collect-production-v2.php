<?php

declare(strict_types=1);

// PREPARATION ONLY. Never register this entry point with application/deployment automation.
$productionFeeEnvironment = [];
$productionFeeInspection = null;
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException('production_fee_runner_runtime_error', 0, $severity, $file, $line);
});
try {
    if (PHP_SAPI !== 'cli' || $argc !== 2 || $argv[1] !== '--inspect-production-v2-fees-once'
        || ! function_exists('posix_isatty') || ! posix_isatty(STDIN)) {
        throw new RuntimeException('manual_production_fee_invocation_required');
    }
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    require __DIR__.'/FeeConfigurationProjection.php';
    require __DIR__.'/StagingFeeInspection.php';
    require __DIR__.'/V2StrictJson.php';
    require __DIR__.'/V2FeeConfigurationProjection.php';
    require __DIR__.'/ProductionV2FeeTransport.php';
    require __DIR__.'/ProductionV2FeeInspection.php';
    foreach (\NiumFeeEvidence\ProductionV2FeeInspection::ENVIRONMENT_NAMES as $productionFeeName) {
        $productionFeeValue = getenv($productionFeeName);
        if ($productionFeeValue !== false) {
            $productionFeeEnvironment[$productionFeeName] = $productionFeeValue;
        }
    }
    $productionFeeInspection = \NiumFeeEvidence\ProductionV2FeeInspection::open(
        $productionFeeEnvironment, dirname(__DIR__, 2));
    $productionFeeChallenge = 'INSPECT PRODUCTION V2 FEES '.$productionFeeInspection->approvalId();
    fwrite(STDERR, 'One Production V2 fee GET maximum. Type: '.$productionFeeChallenge.PHP_EOL);
    $productionFeeAnswer = fgets(STDIN, 128);
    if (! is_string($productionFeeAnswer) || rtrim($productionFeeAnswer, "\r\n") !== $productionFeeChallenge) {
        throw new RuntimeException('manual_production_fee_confirmation_rejected');
    }
    // Approval identity/time are checked again after manual confirmation; the
    // permanent atomic claim must succeed before the sole network attempt.
    $productionFeeOutput = $productionFeeInspection->collect($productionFeeEnvironment);
} catch (Throwable) {
    $productionFeeOutput = $productionFeeInspection === null
        ? ['schema_version' => 1, 'status' => 'FAILED', 'http_status' => null, 'request_id' => null,
            'approval_consumed' => false, 'failure' => 'production_fee_runner_rejected_or_unavailable',
            'admission' => 'DENIED', 'dispatch' => 'DENIED']
        : $productionFeeInspection->failure('production_fee_runner_rejected_or_unavailable');
}
if (class_exists(\NiumFeeEvidence\StagingFeeInspection::class, false)) {
    $productionFeeOutput = \NiumFeeEvidence\StagingFeeInspection::redact($productionFeeOutput, $productionFeeEnvironment);
}
fwrite(STDOUT, json_encode($productionFeeOutput, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
exit(match ($productionFeeOutput['status']) { 'OBSERVED' => 0, 'INCOMPLETE' => 2, default => 1 });
