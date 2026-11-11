<?php

declare(strict_types=1);

// Temporary operator entry point. No Laravel bootstrap or .env/config loading.
$feeEnvironment = [];
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException('fee_runner_runtime_error', 0, $severity, $file, $line);
});
try {
    if (PHP_SAPI !== 'cli' || $argc !== 1) {
        throw new RuntimeException('fee_runner_invocation_rejected');
    }
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    require __DIR__.'/FeeConfigurationProjection.php';
    require __DIR__.'/StagingFeeInspection.php';
    foreach (\NiumFeeEvidence\StagingFeeInspection::ENVIRONMENT_NAMES as $feeName) {
        $feeValue = getenv($feeName);
        if ($feeValue !== false) {
            $feeEnvironment[$feeName] = $feeValue;
        }
    }
    if (($feeEnvironment['APP_ENV'] ?? null) !== 'staging') {
        throw new RuntimeException('staging_configuration_rejected');
    }
    $feeApprovalPath = $feeEnvironment['NIUM_FEE_STAGING_APPROVAL_FILE'] ?? '';
    $feeApproval = \NiumFeeEvidence\StagingFeeInspection::readApproval($feeApprovalPath, dirname(__DIR__, 2));
    $feeOutput = \NiumFeeEvidence\StagingFeeInspection::collect($feeEnvironment, $feeApproval);
} catch (Throwable) {
    // A warning/exception is a failed run, never success or raw diagnostic output.
    $feeOutput = ['schema_version' => 1, 'status' => 'FAILED', 'http_status' => null,
        'request_id' => null, 'failure' => 'fee_runner_rejected_or_unavailable',
        'admission' => 'DENIED', 'dispatch' => 'DENIED'];
}
if (class_exists(\NiumFeeEvidence\StagingFeeInspection::class, false)) {
    $feeOutput = \NiumFeeEvidence\StagingFeeInspection::redact($feeOutput, $feeEnvironment);
}
fwrite(STDOUT, json_encode($feeOutput, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
exit(match ($feeOutput['status']) { 'OBSERVED' => 0, 'INCOMPLETE' => 2, default => 1 });
