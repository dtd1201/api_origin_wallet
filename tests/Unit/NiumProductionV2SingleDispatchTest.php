<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nium\NiumEntitlementEvidenceScope;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use NiumFeeEvidence\ProductionV2FeeTransport;
use NiumFeeEvidence\V2FeeConfigurationProjection;
use NiumFeeEvidence\V2StrictJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

require_once __DIR__.'/../../tools/nium-fee-evidence/ProductionV2FeeInspection.php';
require_once __DIR__.'/../../tools/nium-fee-evidence/ProductionV2FeeTransport.php';
require_once __DIR__.'/../../tools/nium-fee-evidence/V2StrictJson.php';
require_once __DIR__.'/../../tools/nium-fee-evidence/V2FeeConfigurationProjection.php';

/**
 * Never invokes performOnce/curl_exec or a server. Protocol configuration tests
 * may create an unexecuted handle and call curl_setopt locally, without a URL.
 * Callback delivery and completion fields are manufactured, not native/wire evidence.
 * No Laravel HTTP fakes, credentials, real approvals or application bootstrap.
 */
final class NiumProductionV2SingleDispatchTest extends TestCase
{
    private ?Container $previous = null;

    private const CLIENT = '11111111-1111-4111-8111-111111111111';

    private const KEY = 'synthetic-never-real-native-fee-key';

    private const ORIGIN = 'https://synthetic-fee-evidence.nium.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $this->assertTrue(extension_loaded('curl'), 'Required cURL capabilities must fail, not skip.');
        (new ReflectionMethod(ProductionV2FeeTransport::class, 'assertCapabilities'))->invoke(new ProductionV2FeeTransport);
        $private = new Container;
        $private->instance('config', new Repository([
            'app' => ['env' => 'production'],
            'services' => ['nium' => ['base_url' => self::ORIGIN, 'client_id' => self::CLIENT,
                'regulatory_region' => 'HK', 'auth' => ['mode' => 'header', 'header_name' => 'x-api-key', 'header_value' => self::KEY]]],
            'nium_entitlement_evidence' => ['approved_origins' => ['production' => [self::ORIGIN]],
                'scopes' => ['scope-00000001' => ['verified' => true, 'verification_sha256' => str_repeat('a', 64),
                    'app_environment' => 'production', 'provider_environment' => 'production', 'origin' => self::ORIGIN,
                    'client_hash_id' => self::CLIENT, 'client_alias' => 'client-00000001',
                    'customer_hash_id' => null, 'customer_alias' => null, 'regulatory_region' => 'HK', 'corridors' => []]]],
        ]));
        Container::setInstance($private);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
        parent::tearDown();
    }

    private function scope(): NiumEntitlementEvidenceScope
    {
        return NiumEntitlementEvidenceScope::resolve('scope-00000001', 'production', []);
    }

    private function transport(Closure $perform): ProductionV2FeeTransport
    {
        $transport = new ProductionV2FeeTransport;
        (new ReflectionProperty($transport, 'perform'))->setValue($transport, $perform);

        return $transport;
    }

    /** Actual extension values, never numeric substitutes for the legacy HTTPS mask. */
    private function protocolConstants(): array
    {
        $constants = (new \ReflectionExtension('curl'))->getConstants();
        $this->assertArrayHasKey('CURLOPT_PROTOCOLS', $constants, 'Legacy capability tests require the runtime symbol; do not skip.');
        $this->assertArrayHasKey('CURLPROTO_HTTPS', $constants, 'Legacy capability tests require the runtime symbol; do not skip.');

        return array_intersect_key($constants, array_flip(['CURLOPT_PROTOCOLS_STR', 'CURLOPT_PROTOCOLS', 'CURLPROTO_HTTPS']));
    }

    private function protocolProfile(ProductionV2FeeTransport $transport, array $constants): void
    {
        (new ReflectionProperty($transport, 'protocolConstant'))->setValue($transport,
            static fn (string $symbol): mixed => $constants[$symbol] ?? null);
    }

    public function test_modern_protocol_option_takes_precedence_without_legacy_lookup(): void
    {
        $constants = $this->protocolConstants();
        // On a legacy host this is only a synthetic selection token, never sent to cURL.
        $modern = $constants['CURLOPT_PROTOCOLS_STR'] ?? PHP_INT_MAX;
        $constants['CURLOPT_PROTOCOLS_STR'] = $modern;
        $lookups = [];
        $transport = new ProductionV2FeeTransport;
        (new ReflectionProperty($transport, 'protocolConstant'))->setValue($transport,
            static function (string $symbol) use ($constants, &$lookups): mixed {
                $lookups[] = $symbol;

                return $constants[$symbol] ?? null;
            });
        $selected = (new ReflectionMethod($transport, 'httpsOnlyProtocolOption'))->invoke($transport);
        $this->assertSame([$modern, 'https'], $selected);
        $this->assertSame(['CURLOPT_PROTOCOLS_STR'], $lookups);
    }

    public function test_legacy_protocol_option_uses_actual_https_runtime_mask_at_native_seam(): void
    {
        $constants = $this->protocolConstants();
        unset($constants['CURLOPT_PROTOCOLS_STR']);
        $calls = 0;
        $observed = [];
        $transport = $this->transport(static function (array $options) use (&$calls, &$observed): array {
            ++$calls;
            $observed = $options;
            $options[CURLOPT_WRITEFUNCTION](null, '[]');

            return ['completed' => true, 'errno' => 0, 'http_status' => 200];
        });
        $this->protocolProfile($transport, $constants);
        $read = $transport->read($this->scope(), static fn (string $raw): array => (new V2FeeConfigurationProjection)->project($raw));
        $this->assertNull($read['failure']);
        $this->assertSame(1, $calls, 'Synthetic seam only; not a native execution or network GET count.');
        $this->assertSame($constants['CURLPROTO_HTTPS'], $observed[$constants['CURLOPT_PROTOCOLS']]);
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $this->assertArrayNotHasKey(constant('CURLOPT_PROTOCOLS_STR'), $observed);
        }
    }

    #[DataProvider('missingProtocolCapabilities')]
    public function test_missing_protocol_capabilities_fail_preflight_before_native_seam(array $present): void
    {
        $constants = array_intersect_key($this->protocolConstants(), array_flip($present));
        $calls = 0;
        $transport = $this->transport(static function () use (&$calls): array {
            ++$calls;

            return [];
        });
        $this->protocolProfile($transport, $constants);
        $scope = $this->scope();
        $preflightRejected = false;
        try {
            $transport->assertIsolated($scope);
        } catch (RuntimeException $exception) {
            $preflightRejected = $exception->getMessage() === 'evidence_transport_isolation_unavailable';
        }
        $this->assertTrue($preflightRejected);
        $this->assertFalse((new ReflectionProperty($transport, 'entered'))->getValue($transport));
        $readRejected = false;
        try {
            $transport->read($scope, static fn (): array => []);
        } catch (RuntimeException $exception) {
            $readRejected = $exception->getMessage() === 'evidence_transport_isolation_unavailable';
        }
        $this->assertTrue($readRejected);
        $this->assertSame(0, $calls, 'Failed preflight cannot reach even the synthetic native attempt.');
    }

    public static function missingProtocolCapabilities(): array
    {
        return ['neither mechanism' => [[]], 'legacy option without HTTPS mask' => [['CURLOPT_PROTOCOLS']],
            'HTTPS mask without legacy option' => [['CURLPROTO_HTTPS']]];
    }

    #[DataProvider('protocolSetterFailureProfiles')]
    public function test_failed_protocol_setopt_is_terminal_without_another_mechanism(string $profile): void
    {
        $constants = $this->protocolConstants();
        if ($profile === 'modern') {
            // Synthetic only if the modern symbol is absent; the injected setter never calls cURL.
            $constants['CURLOPT_PROTOCOLS_STR'] ??= PHP_INT_MAX;
            $expected = [$constants['CURLOPT_PROTOCOLS_STR'], 'https'];
        } else {
            unset($constants['CURLOPT_PROTOCOLS_STR']);
            $expected = [$constants['CURLOPT_PROTOCOLS'], $constants['CURLPROTO_HTTPS']];
        }
        $transport = new ProductionV2FeeTransport;
        $this->protocolProfile($transport, $constants);
        $options = [$expected[0] => $expected[1]];
        $sets = [];
        $setter = static function ($handle, int $option, int|string $value) use (&$sets): bool {
            $sets[] = [$option, $value];

            return false; // Injected option rejection, not a native failure observation.
        };
        $handle = \curl_init(); // Unexecuted, no URL.
        $this->assertInstanceOf(\CurlHandle::class, $handle);
        $error = null;
        $continued = false;
        try {
            (new ReflectionMethod($transport, 'configureProtocol'))->invokeArgs($transport, [$handle, &$options, $setter]);
            $continued = true;
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        } finally {
            unset($handle);
        }
        $this->assertSame('evidence_transport_setup_failed', $error);
        $this->assertFalse($continued);
        $this->assertSame([$expected], $sets, 'One failed setting must not try legacy/another protocol.');
        $this->assertSame([$expected[0] => $expected[1]], $options);
        // performOnce/curl_exec are never invoked here; native send prevention also needs source review.
    }

    public static function protocolSetterFailureProfiles(): array
    {
        return ['modern rejection' => ['modern'], 'legacy rejection' => ['legacy']];
    }

    public function test_runtime_protocol_option_is_checked_on_an_unexecuted_handle(): void
    {
        $transport = new ProductionV2FeeTransport;
        [$option, $value] = (new ReflectionMethod($transport, 'httpsOnlyProtocolOption'))->invoke($transport);
        $options = [$option => $value];
        $handle = \curl_init(); // No URL, no transfer. Use the actual runtime-selected option.
        $this->assertInstanceOf(\CurlHandle::class, $handle);
        try {
            (new ReflectionMethod($transport, 'configureProtocol'))->invokeArgs($transport, [$handle, &$options]);
        } finally {
            unset($handle);
        }
        $this->assertSame([], $options, 'Successful protocol setting is removed before the other options are applied.');
    }

    public function test_default_native_binding_is_private_and_constructor_does_not_execute_it(): void
    {
        $transport = new ProductionV2FeeTransport;
        $property = new ReflectionProperty($transport, 'perform');
        $binding = new ReflectionFunction($property->getValue($transport));
        $this->assertTrue($property->isPrivate());
        $this->assertTrue((new ReflectionMethod($transport, 'performOnce'))->isPrivate());
        $this->assertSame($transport, $binding->getClosureThis());
        $this->assertSame('performOnce', $binding->getName());
        $this->assertSame(0, (new ReflectionMethod($transport, '__construct'))->getNumberOfParameters());
        $this->assertFalse((new ReflectionProperty($transport, 'entered'))->getValue($transport));
    }

    public function test_fixed_request_policy_and_exact_decimal_projection_at_one_synthetic_seam(): void
    {
        $raw = '[{"default":true,"segment":"synthetic-private","status":"Active","fees":['
            .'{"authCurrency":"USD","feeCurrency":"USD","feeName":"FX_MARKUP",'
            .'"feeValue":0.123456789012345678901234567890,"fixed":false,"slabFrom":0,"slabTo":9999999999999999999999,'
            .'"thresholdFeeValue":0.0000,"status":"Active","transactionCurrency":"HKD"}]}]';
        $calls = 0;
        $observed = [];
        $transport = $this->transport(static function (array $options) use (&$calls, &$observed, $raw): array {
            ++$calls;
            $observed = $options;
            $options[CURLOPT_WRITEFUNCTION](null, $raw);

            return ['completed' => true, 'errno' => 0, 'http_status' => 200];
        });
        $decodeCalls = 0;
        $read = $transport->read($this->scope(), static function (string $body) use (&$decodeCalls): array {
            ++$decodeCalls;

            return (new V2FeeConfigurationProjection)->project($body);
        });
        $this->assertSame(1, $calls, 'Synthetic seam count, not native execution or network transmission count.');
        $this->assertSame(1, $decodeCalls);
        $this->assertNull($read['failure']);
        $this->assertSame('0.123456789012345678901234567890', $read['body']['plans'][0]['fees'][0]['feeValue']);
        $this->assertSame('9999999999999999999999', $read['body']['plans'][0]['fees'][0]['slabTo']);
        $this->assertSame('0.0000', $read['body']['plans'][0]['fees'][0]['thresholdFeeValue']);
        $this->assertSame(self::ORIGIN.'/api/v2/client/'.self::CLIENT.'/fees', $observed[CURLOPT_URL]);
        $this->assertNull(parse_url($observed[CURLOPT_URL], PHP_URL_QUERY));
        $this->assertSame(['Accept: application/json', 'x-api-key: '.self::KEY,
            'x-request-id: '.$read['manifest']['request_id']], $observed[CURLOPT_HTTPHEADER]);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/D', $read['manifest']['request_id']);
        $this->assertNotEmpty($read['manifest']['retrieved_at_utc']);

        // Exact allowlist prevents accidental upload, cookie, proxy/auth, debug or stateful options.
        $constants = $this->protocolConstants();
        $protocolOption = $constants['CURLOPT_PROTOCOLS_STR'] ?? $constants['CURLOPT_PROTOCOLS'];
        $protocolValue = isset($constants['CURLOPT_PROTOCOLS_STR']) ? 'https' : $constants['CURLPROTO_HTTPS'];
        $policy = [CURLOPT_HTTPGET => true, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            $protocolOption => $protocolValue, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROXY => '', CURLOPT_PRE_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPAUTH => CURLAUTH_NONE, CURLOPT_PROXYAUTH => CURLAUTH_NONE, CURLOPT_NETRC => CURL_NETRC_IGNORED,
            CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true, CURLOPT_FAILONERROR => false,
            CURLOPT_HEADER => false, CURLOPT_RETURNTRANSFER => false, CURLOPT_VERBOSE => false];
        $actual = $observed;
        unset($actual[CURLOPT_URL], $actual[CURLOPT_HTTPHEADER], $actual[CURLOPT_WRITEFUNCTION]);
        $this->assertSame($policy, $actual);
        $this->assertInstanceOf(Closure::class, $observed[CURLOPT_WRITEFUNCTION]);
        $encoded = json_encode($read, JSON_THROW_ON_ERROR);
        foreach ([self::CLIENT, self::KEY, self::ORIGIN, 'synthetic-private'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    #[DataProvider('terminalStates')]
    public function test_terminal_states_do_not_retry_or_decode(int $status, int $errno, bool $completed, string $failure): void
    {
        $calls = 0;
        $decoded = 0;
        $transport = $this->transport(static function (array $options) use (&$calls, $status, $errno, $completed): array {
            ++$calls;
            $options[CURLOPT_WRITEFUNCTION](null, 'private '.self::KEY.' '.self::CLIENT.' https://do-not-follow.invalid/');

            return ['completed' => $completed, 'errno' => $errno, 'http_status' => $status];
        });
        $scope = $this->scope();
        $read = $transport->read($scope, static function () use (&$decoded): array {
            ++$decoded;

            return [];
        });
        $this->assertSame(1, $calls);
        $this->assertSame(0, $decoded);
        $this->assertSame($failure, $read['failure']);
        $this->assertNull($read['body']);
        $this->assertSame($status >= 100 && $status <= 599 ? $status : null, $read['manifest']['http_status']);
        $this->assertStringNotContainsString('private', json_encode($read, JSON_THROW_ON_ERROR));
        $rejected = false;
        try {
            $transport->read($scope, static fn (): array => []);
        } catch (RuntimeException $exception) {
            $rejected = $exception->getMessage() === 'evidence_transport_already_used';
        }
        $this->assertTrue($rejected);
        $this->assertSame(1, $calls);
    }

    public static function terminalStates(): array
    {
        return [
            'timeout synthetic completion' => [0, 28, false, 'transport_or_json_failure'],
            'connection failure synthetic completion' => [0, 7, false, 'transport_or_json_failure'],
            'TLS failure synthetic completion' => [0, 60, false, 'transport_or_json_failure'],
            'rewind failure is terminal, no PHP symbol needed' => [0, 65, false, 'transport_or_json_failure'],
            'zero errno without response is terminal' => [0, 0, true, 'transport_or_json_failure'],
            'incomplete body despite 200' => [200, 0, false, 'transport_or_json_failure'],
            'out of range status' => [600, 0, true, 'transport_or_json_failure'],
            'redirect 301' => [301, 0, true, 'http_error'],
            'redirect 302' => [302, 0, true, 'http_error'],
            'redirect 303' => [303, 0, true, 'http_error'],
            'redirect 307' => [307, 0, true, 'http_error'],
            'redirect 308' => [308, 0, true, 'http_error'],
            'unauthorized' => [401, 0, true, 'http_error'],
            'forbidden' => [403, 0, true, 'http_error'],
            'proxy authentication' => [407, 0, true, 'http_error'],
            'rate limited' => [429, 0, true, 'http_error'],
            'service unavailable' => [503, 0, true, 'http_error'],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function test_strict_decode_failure_never_replays(string $raw): void
    {
        $calls = 0;
        $transport = $this->transport(static function (array $options) use (&$calls, $raw): array {
            ++$calls;
            $options[CURLOPT_WRITEFUNCTION](null, $raw);

            return ['completed' => true, 'errno' => 0, 'http_status' => 200];
        });
        $read = $transport->read($this->scope(), static fn (string $body): array => (new V2FeeConfigurationProjection)->project($body));
        $this->assertSame(1, $calls);
        $this->assertSame('transport_or_json_failure', $read['failure']);
        $this->assertNull($read['body']);
        $this->assertSame(200, $read['manifest']['http_status']);
    }

    public static function invalidBodies(): array
    {
        return ['malformed' => ['{invalid'], 'ambiguous' => ['[{"fees":[],"fees":{}}]'],
            'V3 schema' => ['{"feePlans":[]}'], 'empty' => ['']];
    }

    public function test_callback_caps_buffer_and_stays_aborted_after_overflow(): void
    {
        $calls = 0;
        $accepted = [];
        $buffered = null;
        $transport = $this->transport(static function (array $options) use (&$calls, &$accepted, &$buffered): array {
            ++$calls;
            $write = $options[CURLOPT_WRITEFUNCTION];
            $accepted[] = $write(null, '[]'.str_repeat(' ', V2StrictJson::MAX_BYTES - 2));
            $accepted[] = $write(null, 'x');
            // Synthetic repeated delivery tests the sticky abort; native scheduling is untested.
            $accepted[] = $write(null, 'more private bytes');
            $buffered = strlen((new ReflectionFunction($write))->getStaticVariables()['raw']);

            return ['completed' => false, 'errno' => 23, 'http_status' => 200];
        });
        $decoded = 0;
        $read = $transport->read($this->scope(), static function () use (&$decoded): array {
            ++$decoded;

            return [];
        });
        $this->assertSame(1, $calls);
        $this->assertSame([V2StrictJson::MAX_BYTES, 0, 0], $accepted);
        $this->assertSame(V2StrictJson::MAX_BYTES, $buffered);
        $this->assertSame(0, $decoded);
        $this->assertSame('response_size_limit', $read['failure']);
        $this->assertNull($read['body']);
    }

    public function test_single_oversized_chunk_is_never_buffered(): void
    {
        $accepted = null;
        $buffered = null;
        $transport = $this->transport(static function (array $options) use (&$accepted, &$buffered): array {
            $write = $options[CURLOPT_WRITEFUNCTION];
            $accepted = $write(null, str_repeat('x', V2StrictJson::MAX_BYTES + 1));
            $buffered = strlen((new ReflectionFunction($write))->getStaticVariables()['raw']);

            return ['completed' => false, 'errno' => 23, 'http_status' => 200];
        });
        $read = $transport->read($this->scope(), static fn (): array => []);
        $this->assertSame(0, $accepted);
        $this->assertSame(0, $buffered);
        $this->assertSame('response_size_limit', $read['failure']);
    }

    public function test_exact_byte_cap_is_accepted_and_success_does_not_reset_latch(): void
    {
        $calls = 0;
        $transport = $this->transport(static function (array $options) use (&$calls): array {
            ++$calls;
            $options[CURLOPT_WRITEFUNCTION](null, '[]'.str_repeat(' ', V2StrictJson::MAX_BYTES - 2));

            return ['completed' => true, 'errno' => 0, 'http_status' => 200];
        });
        $scope = $this->scope();
        $read = $transport->read($scope, static fn (string $raw): array => (new V2FeeConfigurationProjection)->project($raw));
        $this->assertNull($read['failure']);
        $this->assertSame([], $read['body']['plans']);
        $this->assertSame(['response[]'], $read['body']['missing_fields']);
        $rejected = false;
        try {
            $transport->read($scope, static fn (): array => []);
        } catch (RuntimeException) {
            $rejected = true;
        }
        $this->assertTrue($rejected);
        $this->assertSame(1, $calls);
    }

    public function test_setup_exception_is_sanitized_and_reentrant_read_is_rejected(): void
    {
        $scope = $this->scope();
        $calls = 0;
        $rejected = false;
        $transport = null;
        $transport = $this->transport(static function () use (&$transport, $scope, &$calls, &$rejected): array {
            ++$calls;
            try {
                $transport->read($scope, static fn (): array => []);
            } catch (RuntimeException $exception) {
                $rejected = $exception->getMessage() === 'evidence_transport_already_used';
            }
            throw new RuntimeException('synthetic setup error '.self::KEY.' '.self::CLIENT.' '.self::ORIGIN);
        });
        try {
            $read = $transport->read($scope, static fn (): array => []);
            $this->assertSame(1, $calls);
            $this->assertTrue($rejected);
            $this->assertSame('transport_or_json_failure', $read['failure']);
            $encoded = json_encode($read, JSON_THROW_ON_ERROR);
            foreach ([self::KEY, self::CLIENT, self::ORIGIN, 'synthetic setup error'] as $secret) {
                $this->assertStringNotContainsString($secret, $encoded);
            }
        } finally {
            $transport = null;
        }
    }

    public function test_auth_change_after_scope_resolution_fails_before_native_seam(): void
    {
        $scope = $this->scope();
        config(['services.nium.auth.header_value' => "invalid\nheader"]);
        $calls = 0;
        $transport = $this->transport(static function () use (&$calls): array {
            ++$calls;

            return [];
        });
        $rejected = false;
        try {
            $transport->read($scope, static fn (): array => []);
        } catch (RuntimeException $exception) {
            $rejected = $exception->getMessage() === 'evidence_transport_isolation_unavailable';
        }
        $this->assertTrue($rejected);
        $this->assertSame(0, $calls);
        $this->assertTrue((new ReflectionProperty($transport, 'entered'))->getValue($transport));
    }
}
