<?php

declare(strict_types=1);

namespace Tests\Unit;

use Closure;
use Composer\InstalledVersions;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use Throwable;

/**
 * Historical Laravel/Guzzle path, replaced in V2 by AUTO-02C.30.
 * Fault injection, not a native completion or wire experiment.
 * Uses real CurlFactory::create()/finish(), curl_getinfo()/curl_error() only.
 * NEVER calls CurlHandler, CurlMultiHandler, curl_exec, curl_multi_exec or fopen(URL).
 * No Laravel HTTP fakes. The only terminal is a test-local injected callable.
 */
final class NiumGuzzleRetryReachabilityTest extends TestCase
{
    // PHP's cURL extension may omit the symbolic name; Guzzle compares errno 65.
    // This value is injected test state, never evidence of a native completion.
    private const LIBCURL_SEND_FAIL_REWIND_ERRNO = 65;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(extension_loaded('curl'), 'ext-curl is required for this offline branch experiment; do not skip.');
        $this->assertSame('7.10.0', InstalledVersions::getPrettyVersion('guzzlehttp/guzzle'));
        $this->assertSame('v12.54.1', InstalledVersions::getPrettyVersion('laravel/framework'));
        $this->assertSame(0, CURLE_OK);
    }

    private function request(): Request
    {
        return new Request('GET', 'https://fee-inspection.invalid/api/v2/client/11111111-1111-4111-8111-111111111111/fees',
            ['Accept' => 'application/json', 'x-api-key' => 'synthetic-never-real-key',
                'x-request-id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
    }

    private function options(): array
    {
        return ['verify' => true, 'allow_redirects' => false, 'cookies' => false,
            'debug' => false, 'http_errors' => false, 'timeout' => 30, 'connect_timeout' => 10];
    }

    #[DataProvider('finishStates')]
    public function test_public_finish_entry_gate_for_a_zero_length_get(
        int $errno, bool $hasResponse, array $extra, bool $expectReplay,
    ): void {
        $factory = new CurlFactory(0);
        $request = $this->request();
        $this->assertSame(0, $request->getBody()->getSize());
        $this->assertSame(0, $request->getBody()->tell());
        // create configures a handle; it does not execute it or resolve the URL.
        $easy = $factory->create($request, array_replace($this->options(), $extra));
        $easy->errno = $errno; // Manufactured completion state, NOT a curl result.
        $easy->response = $hasResponse ? new Response(200, [], '[]') : null;
        $replays = [];
        $handler = static function (RequestInterface $replayed, array $options) use (&$replays): PromiseInterface {
            $replays[] = [$replayed, $options];

            return new FulfilledPromise(new Response(200));
        };
        $promise = CurlFactory::finish($handler, $easy, $factory);
        $this->assertCount($expectReplay ? 1 : 0, $replays);
        if ($expectReplay) {
            $this->assertSame($request, $replays[0][0]);
            $this->assertSame(0, $replays[0][0]->getBody()->tell());
            $this->assertSame(($extra['_curl_retries'] ?? 0) + 1, $replays[0][1]['_curl_retries']);
        }
        $this->assertSame($expectReplay || ($errno === 0 && $hasResponse)
            ? PromiseInterface::FULFILLED : PromiseInterface::REJECTED, $promise->getState());
    }

    public static function finishStates(): array
    {
        // Numeric literals keep the provider readable even if ext-curl is missing;
        // setUp then fails rather than reporting skipped coverage as success.
        return [
            'OK without response enters replay' => [0, false, [], true],
            'OK with response completes normally' => [0, true, [], false],
            'rewind errno without response enters replay' => [self::LIBCURL_SEND_FAIL_REWIND_ERRNO, false, [], true],
            'rewind errno even with response enters replay' => [self::LIBCURL_SEND_FAIL_REWIND_ERRNO, true, [], true],
            'connection refused is not this retry' => [7, false, [], false],
            'timeout is not this retry' => [28, false, [], false],
            'empty server reply is not errno zero' => [52, false, [], false],
            'write failure is not this retry' => [23, false, [], false],
            'certificate failure is not this retry' => [60, false, [], false],
            'nonempty private error marker excludes zero' => [0, false, ['_err_message' => 'synthetic-error'], false],
            'nonempty private error marker excludes rewind errno' => [self::LIBCURL_SEND_FAIL_REWIND_ERRNO, false, ['_err_message' => 'synthetic-error'], false],
            'empty error marker permits replay' => [self::LIBCURL_SEND_FAIL_REWIND_ERRNO, false, ['_err_message' => ''], true],
            'counter one permits last replay' => [self::LIBCURL_SEND_FAIL_REWIND_ERRNO, false, ['_curl_retries' => 1], true],
            'counter two rejects' => [self::LIBCURL_SEND_FAIL_REWIND_ERRNO, false, ['_curl_retries' => 2], false],
            'out-of-scope seeded counter is equality not greater-than guard' => [self::LIBCURL_SEND_FAIL_REWIND_ERRNO, false, ['_curl_retries' => 3], true],
        ];
    }

    #[DataProvider('replayErrnos')]
    public function test_repeated_injected_completion_states_allow_three_handler_invocations(int $errno): void
    {
        $factory = new CurlFactory(0);
        $calls = [];
        $handler = null;
        $handler = static function (RequestInterface $request, array $options) use (&$handler, &$calls, $factory, $errno): PromiseInterface {
            $calls[] = [$request, $options['_curl_retries'] ?? null];
            $easy = $factory->create($request, $options);
            $easy->errno = $errno; // Injected after handle creation; no execution.

            return CurlFactory::finish($handler, $easy, $factory);
        };
        try {
            $promise = $handler($this->request(), $this->options());
            $this->assertSame(PromiseInterface::REJECTED, $promise->getState());
            $this->assertSame([null, 1, 2], array_column($calls, 1));
            $this->assertCount(3, $calls, 'Initial invocation plus two replays, not three additional retries.');
            $this->assertSame($calls[0][0], $calls[1][0]);
            $this->assertSame($calls[1][0], $calls[2][0]);
            $reason = null;
            try {
                $promise->wait();
            } catch (RequestException $exception) {
                $reason = $exception;
            }
            $this->assertInstanceOf(RequestException::class, $reason);
            $this->assertSame($errno, $reason->getHandlerContext()['errno']);
        } finally {
            $handler = null; // Release the recursive closure reference.
        }
    }

    public static function replayErrnos(): array
    {
        return ['no-response OK' => [0], 'rewind failure' => [self::LIBCURL_SEND_FAIL_REWIND_ERRNO]];
    }

    public function test_exact_bodyless_get_does_not_configure_upload_or_read_callback(): void
    {
        // Reflect only to observe Guzzle's computed option array; no vendor patch,
        // function replacement, live execution or change to native V2 transport selection.
        $factory = new CurlFactory(0);
        $easy = $factory->create($this->request(), $this->options());
        try {
            $conf = (new ReflectionMethod($factory, 'getDefaultConf'))->invoke($factory, $easy);
            (new ReflectionMethod($factory, 'applyMethod'))->invokeArgs($factory, [$easy, &$conf]);
            $this->assertSame('GET', $conf[CURLOPT_CUSTOMREQUEST]);
            $this->assertSame(CURL_HTTP_VERSION_1_1, $conf[CURLOPT_HTTP_VERSION]);
            foreach ([CURLOPT_UPLOAD, CURLOPT_POSTFIELDS, CURLOPT_READFUNCTION, CURLOPT_INFILESIZE] as $option) {
                $this->assertArrayNotHasKey($option, $conf);
            }
        } finally {
            $factory->release($easy);
        }
    }

    /** Historical stack fixture only; the current native V2 transport is never loaded. */
    private function factory(Closure $terminal): Factory
    {
        return new class($terminal) extends Factory
        {
            public int $pendingRequests = 0;

            public function __construct(private readonly Closure $terminal)
            {
                parent::__construct();
            }

            protected function newPendingRequest()
            {
                ++$this->pendingRequests;

                return parent::newPendingRequest()->setHandler($this->terminal);
            }
        };
    }

    /**
     * Historical HTTP request-chain fixture, not a copy of approval/security logic.
     * Factory always has an injected non-network terminal. No current V2 class.
     */
    private function read(Factory $factory): array
    {
        $manifest = [];
        try {
            $response = $factory->createPendingRequest()->acceptJson()
                ->withHeaders(['x-api-key' => 'synthetic-never-real-key',
                    'x-request-id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'])
                ->timeout(30)->connectTimeout(10)->withoutRedirecting()->retry(1, 0)
                ->withOptions(['debug' => false, 'cookies' => false, 'verify' => true, 'http_errors' => false])
                ->get('https://fee-inspection.invalid/api/v2/client/11111111-1111-4111-8111-111111111111/fees');
            $manifest['http_status'] = $response->status();

            return ['manifest' => $manifest, 'failure' => $response->successful() ? null : 'http_error'];
        } catch (Throwable) {
            return ['manifest' => $manifest, 'failure' => 'transport_or_json_failure'];
        }
    }

    public function test_historical_stack_has_one_laravel_attempt_but_injected_finish_states_replay_below_it(): void
    {
        $curlFactory = new CurlFactory(0);
        $calls = [];
        $terminal = null;
        $terminal = static function (RequestInterface $request, array $options) use (&$terminal, &$calls, $curlFactory): PromiseInterface {
            $calls[] = [$request, $options];
            $easy = $curlFactory->create($request, $options);
            $easy->errno = self::LIBCURL_SEND_FAIL_REWIND_ERRNO; // Artificial, not emitted by executing this GET.

            return CurlFactory::finish($terminal, $easy, $curlFactory);
        };
        try {
            $factory = $this->factory($terminal);
            $result = $this->read($factory);
            // Assertions stay outside read(): it deliberately sanitizes Throwable.
            $this->assertSame(1, $factory->pendingRequests);
            $this->assertCount(3, $calls);
            $this->assertSame([null, 1, 2], array_map(static fn (array $call) => $call[1]['_curl_retries'] ?? null, $calls));
            $this->assertSame('transport_or_json_failure', $result['failure']);
            $request = $calls[0][0];
            $options = $calls[0][1];
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('/api/v2/client/11111111-1111-4111-8111-111111111111/fees', $request->getUri()->getPath());
            $this->assertSame('', $request->getUri()->getQuery());
            $this->assertSame(0, $request->getBody()->getSize());
            $this->assertSame(0, $request->getBody()->tell());
            $this->assertSame('1.1', $request->getProtocolVersion());
            $this->assertFalse($request->hasHeader('Content-Length'));
            $this->assertFalse($request->hasHeader('Transfer-Encoding'));
            $this->assertSame('application/json', $request->getHeaderLine('Accept'));
            $this->assertSame('synthetic-never-real-key', $request->getHeaderLine('x-api-key'));
            $this->assertTrue($options['synchronous']);
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertFalse($options['debug']);
            $this->assertFalse($options['cookies']);
            $this->assertArrayNotHasKey('auth', $options);
            $this->assertArrayNotHasKey('curl', $options);
            $this->assertArrayNotHasKey('stream', $options);
            $this->assertSame($request, $calls[1][0]);
            $this->assertSame($request, $calls[2][0]);
        } finally {
            $terminal = null;
        }
    }

    public function test_historical_normal_redirect_and_error_responses_do_not_repeat_the_injected_terminal(): void
    {
        foreach ([200, 307, 403, 503] as $status) {
            $calls = 0;
            $terminal = static function () use (&$calls, $status): PromiseInterface {
                ++$calls;

                return new FulfilledPromise(new Response($status, ['Location' => 'https://do-not-follow.invalid/'], '{}'));
            };
            $factory = $this->factory($terminal);
            $read = $this->read($factory);
            $this->assertSame(1, $factory->pendingRequests);
            $this->assertSame(1, $calls);
            $this->assertSame($status, $read['manifest']['http_status']);
            $this->assertSame($status === 200 ? null : 'http_error', $read['failure']);
        }
    }
}
