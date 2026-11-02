<?php

use App\Models\Transfer;

// Operator read-only artifact/config verification; no Laravel boot, DB or HTTP.
// Never print paths, config values, secret material or exception messages.
$options = getopt('', ['environment-target:', 'storage-target:', 'policy-sha256:', 'fence-directory:']);
set_error_handler(static function (): never {
    throw new RuntimeException;
});
$checks = [];
try {
    $root = realpath(dirname(__DIR__));
    $checks['php83-runtime'] = PHP_VERSION === '8.3.31'
        && extension_loaded('pdo_pgsql') && extension_loaded('bcmath') && extension_loaded('mbstring');
    require $root.'/vendor/autoload.php';
    $checks['release-local-autoload'] = realpath((new ReflectionClass(Transfer::class))->getFileName()) === $root.'/app/Models/Transfer.php';
    $app = require $root.'/bootstrap/app.php';
    $checks['application-base'] = realpath($app->basePath()) === $root;
    $checks['environment-link'] = is_link($root.'/.env')
        && realpath($root.'/.env') === ($options['environment-target'] ?? null)
        && (fileperms($root.'/.env') & 0007) === 0;
    $checks['shared-production-storage'] = is_link($root.'/storage')
        && realpath($root.'/storage') === ($options['storage-target'] ?? null) && is_writable($root.'/storage');
    $cache = $root.'/bootstrap/cache';
    $checks['release-local-cache'] = ! is_link($cache) && realpath($cache) === $cache
        && is_writable($cache) && $app->getCachedConfigPath() === $cache.'/config.php'
        && (fileperms($cache) & 0007) === 0;
    if (! $checks['release-local-cache'] || ! is_file($cache.'/config.php')) {
        throw new RuntimeException;
    }
    $config = require $cache.'/config.php';
    $checks['private-production-config'] = ($config['app']['env'] ?? null) === 'production'
        && ($config['app']['debug'] ?? null) === false && ! empty($config['app']['key']) && (fileperms($cache.'/config.php') & 0007) === 0;
    $policy = $config['services']['nium']['sgd_local_zero_fee'] ?? null;
    $canonical = function ($value) use (&$canonical) {
        if (is_array($value)) {
            ksort($value);
            foreach ($value as &$child) {
                $child = $canonical($child);
            }
        }

        return $value;
    };
    $hash = hash('sha256', json_encode($canonical($policy), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $checks['policy-identical-and-unexpired'] = is_array($policy) && ($policy['enabled'] ?? null) === true
        && ($policy['version'] ?? null) === 'sgd-local-zero-fee-v1'
        && strtotime($policy['expires_at'] ?? '') === strtotime('2026-11-09T01:25:57Z')
        && strtotime($policy['expires_at']) > time()
        && preg_match('/^[a-f0-9]{64}$/D', $options['policy-sha256'] ?? '')
        && hash_equals($options['policy-sha256'], $hash);
    $fence = $options['fence-directory'] ?? null;
    $checks['fence-closed-and-configured'] = is_string($fence)
        && ($config['sgd_admission']['directory'] ?? null) === $fence
        && ! is_link($fence.'/state') && file_get_contents($fence.'/state') === "CLOSED v1\n";
} catch (Throwable) {
    $checks['complete-verification'] = false;
}
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ').$name.PHP_EOL;
}
exit(count($checks) === 9 && ! in_array(false, $checks, true) ? 0 : 1);
