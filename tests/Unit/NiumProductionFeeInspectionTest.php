<?php

namespace Tests\Unit;

use App\Services\Nium\NiumEntitlementEvidenceTransport;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use NiumFeeEvidence\FeeConfigurationProjection;
use NiumFeeEvidence\ProductionFeeInspection;
use NiumFeeEvidence\StagingFeeInspection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

require_once __DIR__.'/../../tools/nium-fee-evidence/FeeConfigurationProjection.php';
require_once __DIR__.'/../../tools/nium-fee-evidence/StagingFeeInspection.php';
require_once __DIR__.'/../../tools/nium-fee-evidence/ProductionFeeInspection.php';

/** Offline only: synthetic identities, isolated HTTP fakes, no application bootstrap. */
final class NiumProductionFeeInspectionTest extends TestCase
{
    private array $directories = [];

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
            'operation' => ProductionFeeInspection::OPERATION,
            'method' => 'GET',
            'path_template' => ProductionFeeInspection::PATH,
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
        ProductionFeeInspection::validate(
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
        ProductionFeeInspection::validate($environment, $approval, $now, 1001, 'synthetic-host');
    }

    public static function invalidApprovalChanges(): array
    {
        return [
            'version scalar type' => ['version', '1'],
            'different operation' => ['operation', 'nium.payout.submit'],
            'POST forbidden' => ['method', 'POST'],
            'different endpoint' => ['path_template', '/api/v2/client/{clientHashId}/fees'],
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
        ProductionFeeInspection::validate($environment, $approval, $now, 1001, 'synthetic-host');
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
        ProductionFeeInspection::validate($environment, $approval, $now, 1001, 'synthetic-host');
    }

    private function privateApproval(): array
    {
        $this->assertTrue(function_exists('posix_geteuid'), 'POSIX identity checks are required; do not skip them.');
        $directory = sys_get_temp_dir().'/nium-production-fee-offline-'.bin2hex(random_bytes(16));
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
        $environment['NIUM_FEE_PRODUCTION_APPROVAL_FILE'] = $path;

        return [$environment, $approval, $directory, $path];
    }

    private function open(array $environment, string $directory): ProductionFeeInspection
    {
        // The CLI never exposes this directory override; it is only an offline test seam.
        return ProductionFeeInspection::open($environment, dirname(__DIR__, 2), $directory);
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
        $environment['NIUM_FEE_PRODUCTION_APPROVAL_FILE'] = $wrongPath;
        $this->expectException(RuntimeException::class);
        $this->open($environment, $directory);
    }

    #[DataProvider('failedResponses')]
    public function test_each_failed_attempt_is_one_get_and_permanently_consumes_approval(
        int $status, string $raw, bool $disconnect, string $failure,
    ): void {
        [$environment, $approval, $directory] = $this->privateApproval();
        $inspection = $this->open($environment, $directory);
        // Open a second capability before either claims the approval (stale reader race).
        $staleInspection = $this->open($environment, $directory);
        $marker = $directory.'/used-'.$approval['approval_id'].'.json';
        $factory = new Factory;
        $factory->preventStrayRequests();
        $calls = 0;
        $requestId = null;
        $observed = [];
        $factory->fake(function ($request, $options) use (
            &$calls, &$requestId, &$observed, $environment, $marker, $factory, $status, $raw, $disconnect,
        ) {
            ++$calls;
            $ids = $request->header('x-request-id');
            $requestId = $ids[0] ?? null;
            // Assert after collect(): the transport deliberately catches exceptions,
            // which must never swallow a failing assertion inside a fake callback.
            $observed = [
                'marker_exists' => is_file($marker),
                'method' => $request->method(),
                'url' => $request->url(),
                'authentication' => $request->header('x-api-key'),
                'ids' => $ids,
                'options' => $options,
            ];
            if ($disconnect) {
                throw new ConnectionException('Never emit '.$environment['NIUM_API_KEY'].' '.$environment['NIUM_CLIENT_ID']);
            }

            return $factory->response($raw, $status, ['Content-Type' => 'application/json']);
        });
        $transport = new NiumEntitlementEvidenceTransport;
        (new ReflectionProperty($transport, 'http'))->setValue($transport, $factory);

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
        $this->assertSame(1, $calls, 'No redirect, retry or fallback request is permitted.');
        $this->assertTrue($observed['marker_exists'], 'Approval must be claimed before the HTTP attempt.');
        $this->assertSame('GET', $observed['method']);
        $this->assertSame(
            $environment['NIUM_BASE_URL'].'/api/v3/client/'.$environment['NIUM_CLIENT_ID'].'/fees',
            $observed['url'],
        );
        $this->assertSame([$environment['NIUM_API_KEY']], $observed['authentication']);
        $this->assertCount(1, $observed['ids']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $requestId);
        $this->assertNotSame($approval['approval_id'], $requestId);
        $this->assertFalse($observed['options']['allow_redirects']);
        $this->assertTrue($observed['options']['verify']);
        $this->assertFalse($observed['options']['http_errors']);
        $this->assertFalse($observed['options']['debug']);
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
        $this->assertSame(0600, fileperms($marker) & 0777);

        // A current object, a pre-opened stale object and a new open must all fail closed.
        foreach ([$inspection, $staleInspection] as $usedInspection) {
            try {
                $usedInspection->collect($environment, $transport);
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
            'HTTP error with private metadata' => [503, '{"secret":"do not emit"}', false, 'http_error'],
            'redirect forbidden' => [307, '{}', false, 'unexpected_redirect'],
            'invalid JSON' => [200, '{invalid', false, 'fee_response_schema_mismatch'],
            'list cannot replace object' => [200, '[]', false, 'fee_response_schema_mismatch'],
            'ambiguous JSON object' => [200, '{"feePlans":[],"feePlans":{}}', false, 'fee_response_schema_mismatch'],
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
