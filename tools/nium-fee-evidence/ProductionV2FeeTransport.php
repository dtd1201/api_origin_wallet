<?php

declare(strict_types=1);

namespace NiumFeeEvidence;

use App\Services\Nium\NiumEntitlementEvidenceScope;
use Closure;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** V2-only native transfer. One execution is not proof of one network GET. */
final class ProductionV2FeeTransport
{
    private bool $entered = false;

    /** Private offline-test seam; never selected through config, CLI or constructor input. */
    private Closure $perform;

    /** Runtime symbols only; private seam lets offline tests model missing capabilities. */
    private Closure $protocolConstant;

    public function __construct()
    {
        $this->perform = $this->performOnce(...);
        $this->protocolConstant = static fn (string $symbol): mixed => defined($symbol) ? constant($symbol) : null;
    }

    public function assertIsolated(NiumEntitlementEvidenceScope $scope): void
    {
        $auth = config('services.nium.auth');
        if (! is_array($auth) || ($auth['mode'] ?? null) !== 'header'
            || strtolower((string) ($auth['header_name'] ?? '')) !== 'x-api-key'
            || ! is_string($auth['header_value'] ?? null) || strlen($auth['header_value']) < 16
            || strlen($auth['header_value']) > 4096 || preg_match('/[\x00-\x20\x7f]/', $auth['header_value'])
            || $scope->environment !== 'production' || $scope->appEnvironment !== 'production'
            || $scope->customerId() !== null || $scope->corridors !== []
            || $scope->origin !== rtrim((string) config('services.nium.base_url'), '/')
            || $scope->clientId() !== config('services.nium.client_id')) {
            throw new RuntimeException('evidence_transport_isolation_unavailable');
        }
        $this->assertCapabilities();
    }

    /** Local introspection only; unsupported profiles fail before approval consumption. */
    private function assertCapabilities(): void
    {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('evidence_transport_isolation_unavailable');
        }
        foreach (['curl_init', 'curl_setopt', 'curl_setopt_array', 'curl_exec', 'curl_errno', 'curl_getinfo', 'curl_version'] as $function) {
            if (! is_callable($function)) {
                throw new RuntimeException('evidence_transport_isolation_unavailable');
            }
        }
        foreach (['CURLOPT_URL', 'CURLOPT_HTTPGET', 'CURLOPT_HTTP_VERSION', 'CURL_HTTP_VERSION_1_1',
            'CURLOPT_FOLLOWLOCATION', 'CURLOPT_MAXREDIRS',
            'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_TIMEOUT', 'CURLOPT_CONNECTTIMEOUT',
            'CURLOPT_PROXY', 'CURLOPT_PRE_PROXY', 'CURLOPT_NOPROXY', 'CURLOPT_HTTPAUTH', 'CURLOPT_PROXYAUTH',
            'CURLAUTH_NONE', 'CURLOPT_NETRC', 'CURL_NETRC_IGNORED', 'CURLOPT_FRESH_CONNECT', 'CURLOPT_FORBID_REUSE',
            'CURLOPT_FAILONERROR', 'CURLOPT_HEADER', 'CURLOPT_RETURNTRANSFER', 'CURLOPT_VERBOSE',
            'CURLOPT_HTTPHEADER', 'CURLOPT_WRITEFUNCTION', 'CURLINFO_RESPONSE_CODE', 'CURL_VERSION_SSL'] as $symbol) {
            if (! defined($symbol) || ! is_int(constant($symbol))) {
                throw new RuntimeException('evidence_transport_isolation_unavailable');
            }
        }
        $this->httpsOnlyProtocolOption();
        $version = \curl_version();
        if (! is_array($version) || ! is_int($version['version_number'] ?? null)
            || ! is_int($version['features'] ?? null) || ($version['features'] & CURL_VERSION_SSL) === 0
            || ! is_array($version['protocols'] ?? null) || ! in_array('https', $version['protocols'], true)) {
            throw new RuntimeException('evidence_transport_isolation_unavailable');
        }
    }

    /** Capability selection, never a retry after a rejected option. No numeric polyfills. */
    private function httpsOnlyProtocolOption(): array
    {
        $modern = ($this->protocolConstant)('CURLOPT_PROTOCOLS_STR');
        if ($modern !== null) {
            if (! is_int($modern) || $modern <= 0) {
                throw new RuntimeException('evidence_transport_isolation_unavailable');
            }

            return [$modern, 'https'];
        }
        $legacy = ($this->protocolConstant)('CURLOPT_PROTOCOLS');
        $https = ($this->protocolConstant)('CURLPROTO_HTTPS');
        if (! is_int($legacy) || $legacy <= 0 || ! is_int($https) || $https <= 0) {
            throw new RuntimeException('evidence_transport_isolation_unavailable');
        }

        return [$legacy, $https];
    }

    /** Checked before all other options and execution; setter injection is private/test-only. */
    private function configureProtocol(\CurlHandle $handle, array &$options, ?Closure $setOption = null): void
    {
        [$option, $value] = $this->httpsOnlyProtocolOption();
        if (! array_key_exists($option, $options) || $options[$option] !== $value) {
            throw new RuntimeException('evidence_transport_setup_failed');
        }
        $setOption ??= static fn (\CurlHandle $handle, int $option, int|string $value): bool => \curl_setopt($handle, $option, $value);
        if ($setOption($handle, $option, $value) !== true) {
            throw new RuntimeException('evidence_transport_setup_failed');
        }
        // Do not configure it again in curl_setopt_array, and never try another mechanism.
        unset($options[$option]);
    }

    public function read(NiumEntitlementEvidenceScope $scope, Closure $decode): array
    {
        if ($this->entered) {
            throw new RuntimeException('evidence_transport_already_used');
        }
        // Never reset, even if preflight, setup, execution or parsing fails.
        $this->entered = true;
        $this->assertIsolated($scope);
        $path = strtr(ProductionV2FeeInspection::PATH, ['{clientHashId}' => $scope->clientId()]);
        $manifest = ['request_id' => (string) Str::uuid()];
        try {
            $raw = '';
            $overflow = false;
            $write = static function ($handle, string $chunk) use (&$raw, &$overflow): int {
                $length = strlen($chunk);
                if ($overflow || $length > V2StrictJson::MAX_BYTES - strlen($raw)) {
                    $overflow = true;

                    return 0; // Abort without buffering or emitting the oversized chunk.
                }
                $raw .= $chunk;

                return $length;
            };
            [$protocolOption, $protocolValue] = $this->httpsOnlyProtocolOption();
            $options = [
                CURLOPT_URL => $scope->origin.$path,
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                $protocolOption => $protocolValue,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_PROXY => '',
                CURLOPT_PRE_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_HTTPAUTH => CURLAUTH_NONE,
                CURLOPT_PROXYAUTH => CURLAUTH_NONE,
                CURLOPT_NETRC => CURL_NETRC_IGNORED,
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
                CURLOPT_FAILONERROR => false,
                CURLOPT_HEADER => false,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_VERBOSE => false,
                CURLOPT_HTTPHEADER => ['Accept: application/json',
                    'x-api-key: '.config('services.nium.auth.header_value'), 'x-request-id: '.$manifest['request_id']],
                CURLOPT_WRITEFUNCTION => $write,
            ];
            $completion = ($this->perform)($options);
            $manifest['retrieved_at_utc'] = now('UTC')->toISOString();
            $status = $completion['http_status'];
            $manifest['http_status'] = is_int($status) && $status >= 100 && $status <= 599 ? $status : null;
            if ($overflow) {
                // Preserve non-2xx classification even if its body exceeds the cap.
                return ['manifest' => $manifest,
                    'failure' => $manifest['http_status'] !== null && ($status < 200 || $status >= 300)
                        ? 'http_error' : 'response_size_limit', 'body' => null];
            }
            if ($completion['completed'] !== true || $completion['errno'] !== 0 || $manifest['http_status'] === null) {
                return ['manifest' => $manifest, 'failure' => 'transport_or_json_failure', 'body' => null];
            }
            if ($status < 200 || $status >= 300) {
                return ['manifest' => $manifest, 'failure' => 'http_error', 'body' => null];
            }

            return ['manifest' => $manifest, 'failure' => null, 'body' => $decode($raw)];
        } catch (Throwable) {
            return ['manifest' => [...$manifest, 'retrieved_at_utc' => now('UTC')->toISOString()],
                'failure' => 'transport_or_json_failure', 'body' => null];
        }
    }

    /** No loops/replays/fallbacks. Raw body stays in the bounded private write callback. */
    private function performOnce(array $options): array
    {
        // Never let a native warning escape to the CLI/logs with sensitive context.
        set_error_handler(static function (): never {
            throw new RuntimeException('evidence_transport_native_failed');
        });
        try {
            $handle = \curl_init();
            if (! $handle instanceof \CurlHandle) {
                throw new RuntimeException('evidence_transport_setup_failed');
            }
            $this->configureProtocol($handle, $options);
            if (! \curl_setopt_array($handle, $options)) {
                throw new RuntimeException('evidence_transport_setup_failed');
            }
            // Sole native execution site. Native internal resends remain unproven.
            $completed = \curl_exec($handle);

            return ['completed' => $completed === true, 'errno' => \curl_errno($handle),
                'http_status' => \curl_getinfo($handle, CURLINFO_RESPONSE_CODE)];
        } finally {
            // PHP 8 releases CurlHandle on last reference; curl_close is deprecated in PHP 8.5.
            unset($handle);
            restore_error_handler();
        }
    }
}
