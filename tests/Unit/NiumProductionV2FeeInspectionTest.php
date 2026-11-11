<?php

namespace Tests\Unit;

use NiumFeeEvidence\ProductionV2FeeTransport;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use NiumFeeEvidence\FeeConfigurationProjection;
use NiumFeeEvidence\ProductionFeeInspection;
use NiumFeeEvidence\ProductionV2FeeInspection;
use NiumFeeEvidence\V2FeeConfigurationProjection;
use NiumFeeEvidence\StagingFeeInspection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

require_once __DIR__.'/../../tools/nium-fee-evidence/FeeConfigurationProjection.php';
require_once __DIR__.'/../../tools/nium-fee-evidence/StagingFeeInspection.php';
require_once __DIR__.'/../../tools/nium-fee-evidence/ProductionFeeInspection.php';
require_once __DIR__.'/../../tools/nium-fee-evidence/V2StrictJson.php';
require_once __DIR__.'/../../tools/nium-fee-evidence/V2FeeConfigurationProjection.php';
require_once __DIR__.'/../../tools/nium-fee-evidence/ProductionV2FeeTransport.php';
require_once __DIR__.'/../../tools/nium-fee-evidence/ProductionV2FeeInspection.php';

/** Offline only: synthetic identities/completions, no native execution or application bootstrap. */
final class NiumProductionV2FeeInspectionTest extends TestCase
{
    private array $directories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(extension_loaded('curl'), 'Required cURL capabilities must fail, not skip.');
        (new ReflectionMethod(ProductionV2FeeTransport::class, 'assertCapabilities'))->invoke(new ProductionV2FeeTransport);
    }

    private function transport(Closure $perform): ProductionV2FeeTransport
    {
        $transport = new ProductionV2FeeTransport;
        (new ReflectionProperty($transport, 'perform'))->setValue($transport, $perform);

        return $transport;
    }

    /** Synthetic callback delivery, not a native transfer or native completion. */
    private static function complete(array $options, int $status = 200, string $raw = '[]'): array
    {
        $accepted = $options[CURLOPT_WRITEFUNCTION](null, $raw);

        return ['completed' => $accepted === strlen($raw), 'errno' => 0, 'http_status' => $status];
    }

    protected function tearDown(): void
    {
        // Only this test's randomly named temporary control directories are removed.
        // Production approval receipts must never be removed to permit another attempt.
        foreach ($this->directories as $directory) {
            foreach (scandir($directory) as $name) {
                if ($name !== '.' && $name !== '..') {
                    unlink($directory.'/'.$name);
                }
            }
            rmdir($directory);
        }
        parent::tearDown();
    }

    private function environment(): array
    {
        return [
            'APP_ENV' => 'production',
            'NIUM_BASE_URL' => 'https://gateway.nium.com',
            'NIUM_CLIENT_ID' => '11111111-1111-4111-8111-111111111111',
            'NIUM_API_KEY' => 'synthetic-production-fee-key-never-real',
            'NIUM_REGULATORY_REGION' => 'HK',
            'NIUM_AUTH_MODE' => 'header',
            'NIUM_AUTH_HEADER_NAME' => 'x-api-key',
        ];
    }

    private function approval(array $environment, DateTimeImmutable $issued, int $uid, string $host): array
    {
        return [
            'version' => 1,
            'operation' => ProductionV2FeeInspection::OPERATION,
            'method' => 'GET',
            'path_template' => ProductionV2FeeInspection::PATH,
            'request_limit' => 1,
            'app_environment' => 'production',
            'provider_environment' => 'production',
            'origin' => 'https://gateway.nium.com',
            'client_sha256' => hash('sha256', $environment['NIUM_CLIENT_ID']),
            'api_key_sha256' => hash('sha256', $environment['NIUM_API_KEY']),
            'regulatory_region' => 'HK',
            'approval_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'issued_at_utc' => $issued->format('Y-m-d\TH:i:s\Z'),
            'expires_at_utc' => $issued->modify('+10 minutes')->format('Y-m-d\TH:i:s\Z'),
            'operator_uid' => $uid,
            'web_runtime_uid' => $uid === 33 ? 34 : 33,
            'host_sha256' => hash('sha256', $host),
            'approval_reference_sha256' => hash('sha256', 'synthetic-approved-review-reference'),
            'outside_web_roots' => true,
            'manual_execution_only' => true,
        ];
    }

    public function test_complete_exact_production_approval_is_accepted(): void
    {
        $environment = $this->environment();
        $now = new DateTimeImmutable('2030-01-01T00:00:00Z');
        ProductionV2FeeInspection::validate(
            $environment, $this->approval($environment, $now, 1001, 'synthetic-host'),
            $now, 1001, 'synthetic-host',
        );
        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidApprovalChanges')]
    public function test_inexact_or_out_of_scope_approval_is_rejected(string $field, mixed $value): void
    {
        $environment = $this->environment();
        $now = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $approval = $this->approval($environment, $now, 1001, 'synthetic-host');
        $approval[$field] = $value;
        $this->expectException(RuntimeException::class);
        ProductionV2FeeInspection::validate($environment, $approval, $now, 1001, 'synthetic-host');
    }

    public static function invalidApprovalChanges(): array
    {
        return [
            'version scalar type' => ['version', '1'],
            'different operation' => ['operation', 'nium.payout.submit'],
            'POST forbidden' => ['method', 'POST'],
            'different endpoint' => ['path_template', '/api/v3/client/{clientHashId}/fees'],
            'more than one attempt' => ['request_limit', 2],
            'request limit scalar type' => ['request_limit', '1'],
            'wrong app scope' => ['app_environment', 'staging'],
            'wrong provider scope' => ['provider_environment', 'sandbox'],
            'insecure origin' => ['origin', 'http://gateway.nium.com'],
            'lookalike origin' => ['origin', 'https://gateway.nium.com.invalid'],
            'origin with path' => ['origin', 'https://gateway.nium.com/fees'],
            'origin with port' => ['origin', 'https://gateway.nium.com:443'],
            'origin with userinfo' => ['origin', 'https://user@gateway.nium.com'],
            'wrong client' => ['client_sha256', str_repeat('0', 64)],
            'wrong credential' => ['api_key_sha256', str_repeat('0', 64)],
            'wrong region' => ['regulatory_region', 'SG'],
            'wrong host' => ['host_sha256', str_repeat('0', 64)],
            'wrong operator' => ['operator_uid', 1002],
            'operator scalar type' => ['operator_uid', '1001'],
            'web runtime cannot operate' => ['web_runtime_uid', 1001],
            'no web root attestation' => ['outside_web_roots', false],
            'attestation scalar type' => ['outside_web_roots', 'true'],
            'automation forbidden' => ['manual_execution_only', false],
            'invalid approval UUID' => ['approval_id', 'repeatable-approval'],
            'client cannot be printed as approval' => ['approval_id', '11111111-1111-4111-8111-111111111111'],
            'missing review fingerprint' => ['approval_reference_sha256', ''],
            'future approval' => ['issued_at_utc', '2030-01-01T00:01:00Z'],
            'expired boundary' => ['expires_at_utc', '2030-01-01T00:00:00Z'],
            'lifetime over fifteen minutes' => ['expires_at_utc', '2030-01-01T00:15:01Z'],
            'noncanonical timestamp' => ['expires_at_utc', '2030-01-01T00:10:00+00:00'],
            'unknown approval permission' => ['allow_payout', true],
        ];
    }

    #[DataProvider('invalidEnvironmentChanges')]
    public function test_runtime_identity_has_no_fallback(string $field, mixed $value): void
    {
        $environment = $this->environment();
        $now = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $approval = $this->approval($environment, $now, 1001, 'synthetic-host');
        $environment[$field] = $value;
        $this->expectException(RuntimeException::class);
        ProductionV2FeeInspection::validate($environment, $approval, $now, 1001, 'synthetic-host');
    }

    public static function invalidEnvironmentChanges(): array
    {
        return [
            'local environment' => ['APP_ENV', 'local'],
            'staging environment' => ['APP_ENV', 'staging'],
            'missing environment' => ['APP_ENV', ''],
            'missing key' => ['NIUM_API_KEY', ''],
            'changed key' => ['NIUM_API_KEY', 'another-synthetic-key-never-real'],
            'key with newline' => ['NIUM_API_KEY', "synthetic-key-with-newline\n"],
            'missing client' => ['NIUM_CLIENT_ID', ''],
            'changed client' => ['NIUM_CLIENT_ID', '22222222-2222-4222-8222-222222222222'],
            'different origin' => ['NIUM_BASE_URL', 'https://sandbox.nium.com'],
            'origin query' => ['NIUM_BASE_URL', 'https://gateway.nium.com?redirect=1'],
            'missing region' => ['NIUM_REGULATORY_REGION', ''],
            'different region' => ['NIUM_REGULATORY_REGION', 'SG'],
            'query authentication' => ['NIUM_AUTH_MODE', 'query'],
            'different auth header' => ['NIUM_AUTH_HEADER_NAME', 'Authorization'],
        ];
    }

    public function test_missing_approval_member_is_rejected(): void
    {
        $environment = $this->environment();
        $now = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $approval = $this->approval($environment, $now, 1001, 'synthetic-host');
        unset($approval['request_limit']);
        $this->expectException(RuntimeException::class);
        ProductionV2FeeInspection::validate($environment, $approval, $now, 1001, 'synthetic-host');
    }

    public function test_v2_and_v3_approvals_cannot_authorize_each_other(): void
    {
        $environment = $this->environment();
        $now = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $v2 = $this->approval($environment, $now, 1001, 'synthetic-host');
        $v3 = array_replace($v2, ['operation' => ProductionFeeInspection::OPERATION, 'path_template' => ProductionFeeInspection::PATH]);
        foreach ([[$v2, ProductionFeeInspection::class], [$v3, ProductionV2FeeInspection::class]] as [$approval, $class]) {
            $rejected = false;
            try {
                $class::validate($environment, $approval, $now, 1001, 'synthetic-host');
            } catch (RuntimeException) {
                $rejected = true;
            }
            $this->assertTrue($rejected);
        }
        foreach (['operation', 'path_template'] as $field) {
            $mixed = array_replace($v2, [$field => $v3[$field]]);
            $rejected = false;
            try {
                ProductionV2FeeInspection::validate($environment, $mixed, $now, 1001, 'synthetic-host');
            } catch (RuntimeException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'Both operation and path must bind independently.');
        }
    }

    public function test_revalidation_or_missing_v2_approval_never_sends_http(): void
    {
        [$environment, , $directory] = $this->privateApproval();
        $inspection = $this->open($environment, $directory);
        $calls = 0;
        $transport = $this->transport(static function (array $options) use (&$calls): array {
            ++$calls;

            return self::complete($options);
        });
        foreach (self::invalidEnvironmentChanges() as [$field, $value]) {
            $rejected = false;
            try {
                $inspection->collect(array_replace($environment, [$field => $value]), $transport);
            } catch (RuntimeException) {
                $rejected = true;
            }
            $this->assertTrue($rejected);
            $this->assertFalse($inspection->wasConsumed());
        }
        $v3Only = $environment;
        $v3Only['NIUM_FEE_PRODUCTION_APPROVAL_FILE'] = $v3Only['NIUM_FEE_PRODUCTION_V2_APPROVAL_FILE'];
        unset($v3Only['NIUM_FEE_PRODUCTION_V2_APPROVAL_FILE']);
        $rejected = false;
        try {
            $this->open($v3Only, $directory)->collect($v3Only, $transport);
        } catch (RuntimeException) {
            $rejected = true;
        }
        $this->assertTrue($rejected);
        $this->assertSame(0, $calls);
    }

    public function test_v3_approval_file_is_rejected_without_http(): void
    {
        [$environment, $approval, $directory, $path] = $this->privateApproval();
        $approval['operation'] = ProductionFeeInspection::OPERATION;
        $approval['path_template'] = ProductionFeeInspection::PATH;
        file_put_contents($path, json_encode($approval, JSON_THROW_ON_ERROR));
        $calls = 0;
        $transport = $this->transport(static function (array $options) use (&$calls): array {
            ++$calls;

            return self::complete($options);
        });
        $rejected = false;
        try {
            $this->open($environment, $directory)->collect($environment, $transport);
        } catch (RuntimeException) {
            $rejected = true;
        }
        $this->assertTrue($rejected);
        $this->assertSame(0, $calls);
    }

    #[DataProvider('successfulResponses')]
    public function test_success_is_observation_only_and_approval_is_consumed(string $raw, string $status): void
    {
        [$environment, , $directory] = $this->privateApproval();
        $inspection = $this->open($environment, $directory);
        $calls = 0;
        $options = [];
        $transport = $this->transport(static function (array $configured) use (&$calls, &$options, $raw): array {
            ++$calls;
            $options = $configured;

            return self::complete($configured, 200, $raw);
        });
        $output = $inspection->collect($environment, $transport);
        $this->assertSame(1, $calls);
        $this->assertTrue($options[CURLOPT_HTTPGET]);
        $this->assertArrayNotHasKey(CURLOPT_POSTFIELDS, $options);
        $this->assertSame($environment['NIUM_BASE_URL'].'/api/v2/client/'.$environment['NIUM_CLIENT_ID'].'/fees', $options[CURLOPT_URL]);
        $this->assertSame($status, $output['status']);
        $this->assertNull($output['failure']);
        $this->assertSame(200, $output['http_status']);
        $this->assertSame(ProductionV2FeeInspection::PATH, $output['endpoint_template']);
        $this->assertTrue($output['approval_consumed']);
        $this->assertSame(['customer_applicability' => 'UNVERIFIED', 'transaction_fee' => 'UNVERIFIED',
            'fee_payer' => 'UNVERIFIED', 'fee_allocation' => 'UNVERIFIED',
            'nium_wallet_debit' => 'UNVERIFIED', 'origin_wallet_debit' => 'UNVERIFIED',
            'admission' => 'DENIED', 'dispatch' => 'DENIED'], $output['financial_authority']);
        $this->assertSame(['status' => 'UNVERIFIED'], $output['fee_collection']);
        $projected = (new V2FeeConfigurationProjection)->project($raw);
        $this->assertSame($projected['plans'], $output['fee_configuration']);
        $this->assertSame($projected['missing_fields'], $output['missing_fields']);
        $this->assertSame($projected['unsupported_fields'], $output['unsupported_fields']);
        $rejected = false;
        try {
            $inspection->collect($environment, $this->transport(static function (array $options) use (&$calls): array {
                ++$calls;

                return self::complete($options);
            }));
        } catch (RuntimeException) {
            $rejected = true;
        }
        $this->assertTrue($rejected);
        $this->assertSame(1, $calls);
    }

    public static function successfulResponses(): array
    {
        $complete = <<<'JSON'
[{"default":true,"segment":"synthetic-private","status":"Active","fees":[
{"authCurrency":"USD","feeCurrency":"USD","feeName":"FX_MARKUP","feeValue":0.123456789012345678901234567890,"fixed":false,"slabFrom":0,"slabTo":9999999999999999999999,"thresholdFeeValue":0.0000,"status":"Active","transactionCurrency":"HKD"},
{"authCurrency":"USD","feeCurrency":"USD","feeName":"REMIT_BANK_FEE","feeValue":20.0000,"fixed":true,"slabFrom":0,"slabTo":9999999999999999999999,"thresholdFeeValue":0.0000,"status":"Active","transactionCurrency":"HKD"}
]}]
JSON;

        return [
            'complete V2 array' => [$complete, 'OBSERVED'],
            'unknown currencies' => [str_replace('"USD"', '"ZZZ"', $complete), 'INCOMPLETE'],
            'ANY currency' => [str_replace('"USD"', '"ANY"', $complete), 'INCOMPLETE'],
            'null currency' => [str_replace('"USD"', 'null', $complete), 'INCOMPLETE'],
            'unknown status' => [str_replace('"Active"', '"Paused"', $complete), 'INCOMPLETE'],
            'unknown fee' => [str_replace('"FX_MARKUP"', '"FUTURE_FEE"', $complete), 'INCOMPLETE'],
            'missing fee amount' => [str_replace('"feeValue":20.0000,', '', $complete), 'INCOMPLETE'],
            'no templates' => ['[]', 'INCOMPLETE'],
            'no fees' => ['[{"default":true,"segment":"synthetic","status":"Active","fees":[]}]', 'INCOMPLETE'],
        ];
    }

    public function test_claim_collision_is_rejected_before_native_attempt(): void
    {
        [$environment, $approval, $directory] = $this->privateApproval();
        $inspection = $this->open($environment, $directory);
        $marker = $directory.'/used-'.$approval['approval_id'].'.json';
        file_put_contents($marker, 'synthetic-existing-claim');
        chmod($marker, 0600);
        $calls = 0;
        $transport = $this->transport(static function (array $options) use (&$calls): array {
            ++$calls;

            return self::complete($options);
        });
        $before = Container::getInstance();
        $rejected = false;
        try {
            $inspection->collect($environment, $transport);
        } catch (RuntimeException) {
            $rejected = true;
        }
        $this->assertTrue($rejected);
        $this->assertSame($before, Container::getInstance());
        $this->assertFalse($inspection->wasConsumed());
        $this->assertSame(0, $calls);
        $this->assertSame('synthetic-existing-claim', file_get_contents($marker));
    }

    public function test_changed_control_directory_permissions_fail_before_native_attempt(): void
    {
        [$environment, , $directory] = $this->privateApproval();
        $inspection = $this->open($environment, $directory);
        chmod($directory, 0750);
        $calls = 0;
        $transport = $this->transport(static function (array $options) use (&$calls): array {
            ++$calls;

            return self::complete($options);
        });
        $rejected = false;
        try {
            $inspection->collect($environment, $transport);
        } catch (RuntimeException) {
            $rejected = true;
        }
        $this->assertTrue($rejected);
        $this->assertFalse($inspection->wasConsumed());
        $this->assertSame(0, $calls);
    }

    /**
     * Normally checks the durable happy path. Fault modes require operator strace
     * syscall injection; the flag alone does not manufacture an fsync failure.
     * No approval method/function is replaced. No native HTTP is executed.
     */
    public function test_durable_claim_with_optional_operator_fsync_fault(): void
    {
        $fault = getenv('NIUM_OFFLINE_FSYNC_FAULT');
        $fault = $fault === false ? '' : $fault;
        $this->assertContains($fault, ['', 'file', 'directory']);
        [$environment, $approval, $directory] = $this->privateApproval();
        $inspection = $this->open($environment, $directory);
        $stale = $this->open($environment, $directory);
        $calls = 0;
        $transport = $this->transport(static function (array $options) use (&$calls): array {
            ++$calls;

            return self::complete($options);
        });
        $error = null;
        $output = null;
        // Native syscall errors may warn or return false depending on the PHP build.
        set_error_handler(static function (int $severity): never {
            throw new \ErrorException('offline_filesystem_warning', 0, $severity);
        });
        try {
            $output = $inspection->collect($environment, $transport);
        } catch (\Throwable $exception) {
            $error = $exception;
        } finally {
            restore_error_handler();
        }
        $this->assertTrue($inspection->wasConsumed());
        $marker = $directory.'/used-'.$approval['approval_id'].'.json';
        $this->assertFileExists($marker);
        $this->assertSame(0600, fileperms($marker) & 0777);
        if ($fault === '') {
            $this->assertNull($error);
            $this->assertSame(1, $calls, 'Synthetic seam only, after successful local fsync calls.');
            $this->assertNull($output['failure']);
        } else {
            $this->assertInstanceOf(\Throwable::class, $error, 'Fault mode must fail unless syscall injection took effect.');
            $this->assertContains($error->getMessage(), ['offline_filesystem_warning', $fault === 'file'
                ? 'production_approval_claim_failed' : 'production_approval_directory_sync_failed']);
            $this->assertSame(0, $calls, 'Failed file/directory fsync must prevent entry to the native seam.');
            $this->assertNull($output);
        }
        foreach ([$inspection, $stale] as $used) {
            $rejected = false;
            try {
                $used->collect($environment, $this->transport(static function (array $options) use (&$calls): array {
                    ++$calls;

                    return self::complete($options);
                }));
            } catch (RuntimeException) {
                $rejected = true;
            }
            $this->assertTrue($rejected);
        }
        $this->assertSame($fault === '' ? 1 : 0, $calls);
    }

    private function privateApproval(): array
    {
        $this->assertTrue(function_exists('posix_geteuid'), 'POSIX identity checks are required; do not skip them.');
        $directory = sys_get_temp_dir().'/nium-production-v2-fee-offline-'.bin2hex(random_bytes(16));
        $this->assertTrue(mkdir($directory, 0700));
        chmod($directory, 0700);
        $directory = realpath($directory);
        $this->directories[] = $directory;
        $environment = $this->environment();
        $approval = $this->approval(
            $environment, new DateTimeImmutable('now', new DateTimeZone('UTC')),
            posix_geteuid(), gethostname(),
        );
        $path = $directory.'/approval-'.$approval['approval_id'].'.json';
        file_put_contents($path, json_encode($approval, JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        $environment['NIUM_FEE_PRODUCTION_V2_APPROVAL_FILE'] = $path;

        return [$environment, $approval, $directory, $path];
    }

    private function open(array $environment, string $directory): ProductionV2FeeInspection
    {
        // The CLI never exposes this directory override; it is only an offline test seam.
        return ProductionV2FeeInspection::open($environment, dirname(__DIR__, 2), $directory);
    }

    public function test_group_readable_approval_is_rejected_before_http(): void
    {
        [$environment, , $directory, $path] = $this->privateApproval();
        chmod($path, 0640);
        $this->expectException(RuntimeException::class);
        $this->open($environment, $directory);
    }

    public function test_group_accessible_control_directory_is_rejected(): void
    {
        [$environment, , $directory] = $this->privateApproval();
        chmod($directory, 0750);
        $this->expectException(RuntimeException::class);
        $this->open($environment, $directory);
    }

    public function test_symlink_approval_is_rejected(): void
    {
        [$environment, , $directory, $path] = $this->privateApproval();
        $target = $directory.'/private-target.json';
        rename($path, $target);
        symlink($target, $path);
        $this->expectException(RuntimeException::class);
        $this->open($environment, $directory);
    }

    public function test_duplicate_approval_members_are_rejected(): void
    {
        [$environment, , $directory, $path] = $this->privateApproval();
        $raw = file_get_contents($path);
        file_put_contents($path, '{"method":"POST",'.substr($raw, 1));
        $this->expectException(RuntimeException::class);
        $this->open($environment, $directory);
    }

    public function test_approval_file_name_must_match_approved_operation_id(): void
    {
        [$environment, , $directory, $path] = $this->privateApproval();
        $wrongPath = $directory.'/approval-unbound.json';
        rename($path, $wrongPath);
        $environment['NIUM_FEE_PRODUCTION_V2_APPROVAL_FILE'] = $wrongPath;
        $this->expectException(RuntimeException::class);
        $this->open($environment, $directory);
    }

    #[DataProvider('failedResponses')]
    public function test_each_failed_native_seam_attempt_permanently_consumes_approval(
        int $status, string $raw, bool $disconnect, string $failure,
    ): void {
        [$environment, $approval, $directory] = $this->privateApproval();
        $inspection = $this->open($environment, $directory);
        // Open a second capability before either claims the approval (stale reader race).
        $staleInspection = $this->open($environment, $directory);
        $marker = $directory.'/used-'.$approval['approval_id'].'.json';
        $calls = 0;
        $requestId = null;
        $observed = [];
        $transport = $this->transport(static function (array $options) use (
            &$calls, &$requestId, &$observed, $environment, $marker, $status, $raw, $disconnect,
        ): array {
            ++$calls;
            $requestId = substr($options[CURLOPT_HTTPHEADER][2], strlen('x-request-id: '));
            // Observe here; assert after collect(), which intentionally sanitizes Throwable.
            $observed = ['marker_exists' => is_file($marker), 'options' => $options];
            if ($disconnect) {
                throw new RuntimeException('Never emit '.$environment['NIUM_API_KEY'].' '.$environment['NIUM_CLIENT_ID']);
            }

            return self::complete($options, $status, $raw);
        });

        $priorContainer = Container::getInstance();
        $sentinel = new Container;
        $sentinelConfig = new Repository(['untouched' => ['value' => 'original']]);
        $sentinel->instance('config', $sentinelConfig);
        Container::setInstance($sentinel);
        $forbiddenLoads = [];
        $autoloadGuard = static function (string $class) use (&$forbiddenLoads): void {
            foreach (['App\\Models\\', 'App\\Providers\\', 'Illuminate\\Database\\', 'App\\Services\\Nium\\NiumService'] as $prefix) {
                if (str_starts_with($class, $prefix)) {
                    $forbiddenLoads[] = $class;
                    throw new RuntimeException('Application bootstrap is forbidden in the fee collector.');
                }
            }
        };
        spl_autoload_register($autoloadGuard, true, true);
        try {
            $output = $inspection->collect($environment, $transport);
            $this->assertSame($sentinel, Container::getInstance());
            $this->assertSame(['untouched' => ['value' => 'original']], $sentinelConfig->all());
        } finally {
            spl_autoload_unregister($autoloadGuard);
            Container::setInstance($priorContainer);
        }

        $this->assertSame([], $forbiddenLoads);
        $this->assertSame(1, $calls, 'One synthetic seam invocation; not a network GET count.');
        $this->assertTrue($observed['marker_exists'], 'Approval must be claimed before the native seam.');
        $options = $observed['options'];
        $this->assertTrue($options[CURLOPT_HTTPGET]);
        $this->assertSame(
            $environment['NIUM_BASE_URL'].'/api/v2/client/'.$environment['NIUM_CLIENT_ID'].'/fees',
            $options[CURLOPT_URL],
        );
        $this->assertSame('x-api-key: '.$environment['NIUM_API_KEY'], $options[CURLOPT_HTTPHEADER][1]);
        $this->assertArrayNotHasKey(CURLOPT_POSTFIELDS, $options);
        $this->assertArrayNotHasKey(CURLOPT_COOKIEFILE, $options);
        $this->assertSame(30, $options[CURLOPT_TIMEOUT]);
        $this->assertSame(10, $options[CURLOPT_CONNECTTIMEOUT]);
        $this->assertCount(3, $options[CURLOPT_HTTPHEADER]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $requestId);
        $this->assertNotSame($approval['approval_id'], $requestId);
        $this->assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        $this->assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        $this->assertFalse($options[CURLOPT_FAILONERROR]);
        $this->assertFalse($options[CURLOPT_VERBOSE]);
        $this->assertSame('', $options[CURLOPT_PROXY]);
        $this->assertSame('', $options[CURLOPT_PRE_PROXY]);
        $this->assertSame(CURLAUTH_NONE, $options[CURLOPT_HTTPAUTH]);
        $this->assertTrue($inspection->wasConsumed());
        $this->assertSame('FAILED', $output['status']);
        $this->assertSame($failure, $output['failure']);
        $this->assertSame($disconnect ? null : $status, $output['http_status']);
        $this->assertSame($requestId, $output['request_id']);
        $this->assertSame([], $output['fee_configuration']);
        $this->assertSame('DENIED', $output['financial_authority']['admission']);
        $this->assertSame('DENIED', $output['financial_authority']['dispatch']);
        $encoded = json_encode($output, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($environment['NIUM_API_KEY'], $encoded);
        $this->assertStringNotContainsString($environment['NIUM_CLIENT_ID'], $encoded);
        $receipt = json_decode(file_get_contents($marker), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($approval['approval_id'], $receipt['approval_id']);
        $this->assertSame(ProductionV2FeeInspection::OPERATION, $receipt['operation']);
        $this->assertSame(0600, fileperms($marker) & 0777);

        // A current object, a pre-opened stale object and a new open must all fail closed.
        foreach ([$inspection, $staleInspection] as $usedInspection) {
            try {
                // Fresh transport: the durable approval boundary must reject independently of its latch.
                $usedInspection->collect($environment, $this->transport(static function (array $options) use (&$calls): array {
                    ++$calls;

                    return self::complete($options);
                }));
                $this->fail('A consumed approval must not permit another attempt.');
            } catch (RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
        try {
            $this->open($environment, $directory);
            $this->fail('A consumed approval must not be reopened.');
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame(1, $calls);
    }

    public static function failedResponses(): array
    {
        return [
            'unauthorized with no negotiation' => [401, '{}', false, 'http_error'],
            'proxy auth forbidden' => [407, '{}', false, 'http_error'],
            'rate limit terminal' => [429, '{}', false, 'http_error'],
            'forbidden with no fallback' => [403, '{"private":"do not emit"}', false, 'http_error'],
            'HTTP error with private metadata' => [503, '{"secret":"do not emit"}', false, 'http_error'],
            'redirect forbidden' => [307, '{}', false, 'unexpected_redirect'],
            'invalid JSON' => [200, '{invalid', false, 'fee_response_schema_mismatch'],
            'V3 object cannot replace V2 array' => [200, '{"feePlans":[]}', false, 'fee_response_schema_mismatch'],
            'ambiguous JSON object' => [200, '[{"fees":[],"fees":{}}]', false, 'fee_response_schema_mismatch'],
            'escaped duplicate members' => [200, '[{"fees":[],"f\\u0065es":[]}]', false, 'fee_response_schema_mismatch'],
            'oversized response' => [200, str_repeat(' ', \NiumFeeEvidence\V2StrictJson::MAX_BYTES + 1), false, 'response_size_limit'],
            'oversized redirect stays terminal' => [307, str_repeat(' ', \NiumFeeEvidence\V2StrictJson::MAX_BYTES + 1), false, 'unexpected_redirect'],
            'oversized forbidden stays terminal' => [403, str_repeat(' ', \NiumFeeEvidence\V2StrictJson::MAX_BYTES + 1), false, 'http_error'],
            'connection failure' => [0, '', true, 'transport_or_json_failure'],
        ];
    }

    public function test_final_redaction_removes_secret_values_even_inside_projected_fields(): void
    {
        $environment = $this->environment();
        $result = StagingFeeInspection::redact([
            'status' => 'OBSERVED',
            'fee_configuration' => [['value' => $environment['NIUM_API_KEY'].'/'.$environment['NIUM_CLIENT_ID']]],
            'unsupported_fields' => [],
        ], $environment);
        $this->assertSame('INCOMPLETE', $result['status']);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($environment['NIUM_API_KEY'], $encoded);
        $this->assertStringNotContainsString($environment['NIUM_CLIENT_ID'], $encoded);
        $this->assertStringContainsString('[REDACTED]', $encoded);
    }

    public function test_shared_strict_decoder_preserves_decimal_strings_and_object_shape(): void
    {
        $decoded = FeeConfigurationProjection::decodeObject('{"price":{"value":"20.0000"},"rules":[]}');
        $this->assertInstanceOf(\stdClass::class, $decoded->price);
        $this->assertSame('20.0000', $decoded->price->value);
        $this->assertSame([], $decoded->rules);
    }
}
