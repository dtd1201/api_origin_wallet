<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\ProtectDynamicTransferOptionsResponses;
use App\Http\Support\DynamicTransferOptionsResponsePolicy;
use Illuminate\Http\Request as LaravelRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Offline response objects only; no application boot or external resources. */
final class NiumDynamicTransferOptionsResponsePolicyTest extends TestCase
{
    private const URI = '/api/user/users/101/dynamic-transfer-options';

    private const UNAVAILABLE = ['options' => [], 'reason_codes' => ['trusted_snapshot_unavailable']];

    #[DataProvider('paths')]
    public function test_exact_path_scope_for_every_method(string $path, bool $expected): void
    {
        $policy = new DynamicTransferOptionsResponsePolicy;
        foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $this->assertSame($expected, $policy->matches(Request::create($path, $method)), $method.' '.$path);
        }
    }

    public static function paths(): iterable
    {
        foreach (['1', '0', '01', '-1', '+1', '999999999999999999999999', 'abc', '', '%31', '%2F',
            '%252F', '%FF', '%00', '%ZZ', "\xFF", 'a%3Fb'] as $user) {
            yield 'user '.bin2hex($user) => ['/api/user/users/'.$user.'/dynamic-transfer-options', true];
        }
        yield 'query ignored' => [self::URI.'?environment=production&active=true', true];
        yield 'trailing slash' => [self::URI.'///', true];
        yield 'encoded fixed literal' => ['/api/%75ser/users/1/dynamic-transfer%2Doptions', true];
        yield 'encoded separators' => ['/api%2Fuser%2Fusers%2F1%2Fdynamic-transfer-options', true];
        foreach ([
            '/api/user/users/101/transfers', '/api/user/users/101/transfers/review',
            '/api/user/users/101/transfers/5/submit', '/api/user/users/101/fx-quotes',
            '/api/user/users/101/beneficiaries', '/api/user/users/101/dynamic-transfer-options-export',
            self::URI.'/child', '/prefix'.self::URI, '/API/user/users/1/dynamic-transfer-options',
            '/api//user/users/1/dynamic-transfer-options', '/api/user/users/a/b/dynamic-transfer-options',
            '/api/user/users/1/../dynamic-transfer-options',
            '/api/%2575ser/users/1/dynamic-transfer-options', '/other?path='.self::URI,
        ] as $i => $path) {
            yield 'neighbor '.$i => [$path, false];
        }
    }

    public function test_nonmatches_preserve_object_headers_cookies_and_body_even_with_an_exception(): void
    {
        $request = LaravelRequest::create('/api/user/users/101/transfers', 'POST');
        $response = new Response('synthetic legacy response', 409, ['Cache-Control' => 'public, max-age=60', 'ETag' => 'old']);
        $response->headers->setCookie(Cookie::create('synthetic-cookie', 'unchanged'));
        $headers = $response->headers->all();
        $policy = new DynamicTransferOptionsResponsePolicy;
        $this->assertSame($response, $policy->normalize($request, $response, new RuntimeException('private')));
        $calls = 0;
        $this->assertSame($response, (new ProtectDynamicTransferOptionsResponses($policy))->handle(
            $request, function ($actual) use ($request, $response, &$calls) {
                $this->assertSame($request, $actual);
                $calls++;

                return $response;
            },
        ));
        $this->assertSame(1, $calls);
        $this->assertSame($headers, $response->headers->all());
        $this->assertSame('synthetic legacy response', $response->getContent());
        $this->assertFalse(method_exists(ProtectDynamicTransferOptionsResponses::class, 'terminate'));
    }

    #[DataProvider('errors')]
    public function test_fixed_errors_preserve_status_and_never_forward_diagnostics(int $status, string $message): void
    {
        $policy = new DynamicTransferOptionsResponsePolicy;
        foreach ([null, new RuntimeException('synthetic-private')] as $exception) {
            $safe = $policy->normalize(Request::create(self::URI), new Response('<pre>synthetic-private</pre>', $status), $exception);
            $this->assertSame($status, $safe->getStatusCode());
            $this->assertSame(['message' => $message], json_decode($safe->getContent(), true, 32, JSON_THROW_ON_ERROR));
            $this->assertCacheProtection($safe);
        }
    }

    public static function errors(): iterable
    {
        foreach ([400 => 'Bad request.', 401 => 'Unauthenticated.',
            403 => 'You are not allowed to access this user resource.', 404 => 'Not found.',
            405 => 'Method not allowed.', 409 => 'Transfer options unavailable.',
            413 => 'Request content too large.', 422 => 'Invalid transfer options request.',
            429 => 'Transfer options unavailable.', 500 => 'Transfer options unavailable.',
            503 => 'Transfer options unavailable.'] as $status => $message) {
            yield (string) $status => [$status, $message];
        }
    }

    public function test_only_exact_approved_unavailable_and_profile_dtos_are_preserved(): void
    {
        $policy = new DynamicTransferOptionsResponsePolicy;
        $profile = ['message' => 'User profile must be completed before accessing this resource.', 'code' => 'PROFILE_REQUIRED'];
        foreach ([[200, self::UNAVAILABLE], [409, $profile]] as [$status, $body]) {
            $safe = $policy->normalize(Request::create(self::URI), new JsonResponse($body, $status));
            $this->assertSame($body, json_decode($safe->getContent(), true, 32, JSON_THROW_ON_ERROR));
            $redacted = $policy->normalize(Request::create(self::URI), new JsonResponse($body, $status), new RuntimeException('private'));
            $this->assertSame(['message' => 'Transfer options unavailable.'], json_decode($redacted->getContent(), true, 32, JSON_THROW_ON_ERROR));
        }
        foreach ([
            ['options' => [['dispatch' => 'ALLOWED']], 'reason_codes' => []],
            self::UNAVAILABLE + ['trace' => 'private'],
            ['options' => false, 'reason_codes' => ['trusted_snapshot_unavailable']],
            ['options' => (object) [], 'reason_codes' => ['trusted_snapshot_unavailable']],
            ['options' => [], 'reason_codes' => 'trusted_snapshot_unavailable'],
            ['message' => $profile['message'], 'code' => 'PROFILE_REQUIRED', 'user_id' => 'private'],
        ] as $body) {
            $safe = $policy->normalize(Request::create(self::URI), new JsonResponse($body));
            $this->assertSame(['message' => 'Transfer options unavailable.'], json_decode($safe->getContent(), true, 32, JSON_THROW_ON_ERROR));
        }
    }

    public function test_raw_json_duplicates_jsonp_and_malformed_json_cannot_forward_hidden_data(): void
    {
        $policy = new DynamicTransferOptionsResponsePolicy;
        $raw = new Response('{"options":["private"],"options":[],"reason_codes":["trusted_snapshot_unavailable"]}', 200, ['Content-Type' => 'application/json']);
        $this->assertSame(self::UNAVAILABLE, json_decode($policy->normalize(Request::create(self::URI), $raw)->getContent(), true, 32, JSON_THROW_ON_ERROR));
        foreach (['{"options":', 'callback({"options":[],"reason_codes":["trusted_snapshot_unavailable"]})', '{"options":[],"reason_codes":{}}'] as $content) {
            $safe = $policy->normalize(Request::create(self::URI), new Response($content, 200, ['Content-Type' => 'application/json']));
            $this->assertSame(['message' => 'Transfer options unavailable.'], json_decode($safe->getContent(), true, 32, JSON_THROW_ON_ERROR));
        }
    }

    public function test_protocol_metadata_survives_but_entity_and_diagnostic_headers_do_not(): void
    {
        $headers = ['Allow' => 'GET, HEAD, OPTIONS', 'Retry-After' => '60', 'Refresh' => '30',
            'WWW-Authenticate' => ['Bearer realm="synthetic"', 'Basic realm="synthetic"'],
            'Vary' => 'Origin, Access-Control-Request-Method', 'Access-Control-Allow-Origin' => 'https://allowed.example',
            'Access-Control-Allow-Credentials' => 'true', 'Access-Control-Allow-Methods' => 'GET',
            'Access-Control-Allow-Headers' => 'Authorization', 'Access-Control-Expose-Headers' => 'Retry-After',
            'Access-Control-Max-Age' => '0', 'Access-Control-Allow-Private-Network' => 'true'];
        $response = new Response('private', 503, $headers + ['Cache-Control' => 'public, max-age=600, s-maxage=900',
            'ETag' => 'old', 'Last-Modified' => 'Mon, 09 Nov 2026 00:00:00 GMT', 'Expires' => 'Tue, 10 Nov 2026 00:00:00 GMT',
            'Content-Length' => '9999', 'Content-Encoding' => 'gzip', 'Content-MD5' => 'private',
            'Digest' => 'private', 'X-Debug-Exception' => 'private']);
        $original = $response->headers->all();
        $safe = (new DynamicTransferOptionsResponsePolicy)->normalize(Request::create(self::URI), $response);
        foreach ($headers as $name => $value) {
            $this->assertSame((array) $value, $safe->headers->all($name));
        }
        foreach (['Content-Length', 'Content-Encoding', 'Content-MD5', 'Digest', 'X-Debug-Exception'] as $name) {
            $this->assertFalse($safe->headers->has($name));
        }
        $this->assertSame($original, $response->headers->all());
        $this->assertSame('private', $response->getContent());
        $this->assertCacheProtection($safe);
    }

    public function test_redirect_metadata_is_preserved_with_a_fixed_body(): void
    {
        $safe = (new DynamicTransferOptionsResponsePolicy)->normalize(Request::create(self::URI),
            new Response('private debug redirect', 307, ['Location' => '/maintenance', 'Retry-After' => '60']));
        $this->assertSame(307, $safe->getStatusCode());
        $this->assertSame('/maintenance', $safe->headers->get('Location'));
        $this->assertSame('60', $safe->headers->get('Retry-After'));
        $this->assertStringNotContainsString('private debug', $safe->getContent());
        $this->assertCacheProtection($safe);
    }

    public function test_head_204_304_and_options_remain_bodyless_without_process_ini_mutation(): void
    {
        $policy = new DynamicTransferOptionsResponsePolicy;
        $mime = ini_get('default_mimetype');
        foreach ([['HEAD', 200], ['HEAD', 503], ['GET', 204], ['GET', 304], ['OPTIONS', 204]] as [$method, $status]) {
            $safe = $policy->normalize(Request::create(self::URI, $method), new Response('private', $status, ['ETag' => 'old']));
            $this->assertSame($status, $safe->getStatusCode());
            $this->assertSame('', $safe->getContent());
            if ($status === 204 || $status === 304) {
                $this->assertFalse($safe->headers->has('Content-Type'));
            }
            $this->assertCacheProtection($safe);
        }
        $options = $policy->normalize(Request::create(self::URI, 'OPTIONS'), new Response('', 200, ['Allow' => 'GET, HEAD']));
        $this->assertSame('', $options->getContent());
        $this->assertSame('GET, HEAD', $options->headers->get('Allow'));
        $this->assertSame($mime, ini_get('default_mimetype'));
    }

    public function test_conditional_headers_never_trigger_conversion_and_normalization_is_idempotent(): void
    {
        $policy = new DynamicTransferOptionsResponsePolicy;
        $request = Request::create(self::URI, 'GET', [], [], [], ['HTTP_IF_NONE_MATCH' => '*', 'HTTP_IF_MODIFIED_SINCE' => 'Tue, 10 Nov 2026 00:00:00 GMT']);
        foreach ([new JsonResponse(self::UNAVAILABLE), new Response('private', 503), new Response('', 304)] as $response) {
            foreach ([null, new RuntimeException('private')] as $exception) {
                $once = $policy->normalize($request, $response, $exception);
                $twice = $policy->normalize($request, $once, $exception);
                $this->assertSame($response->getStatusCode(), $once->getStatusCode());
                $this->assertSame($once->getContent(), $twice->getContent());
                $this->assertSame($once->headers->all(), $twice->headers->all());
            }
        }
    }

    public function test_streams_are_replaced_without_invoking_their_callback(): void
    {
        $called = false;
        $stream = new StreamedResponse(function () use (&$called): void { $called = true; });
        $safe = (new DynamicTransferOptionsResponsePolicy)->normalize(Request::create(self::URI), $stream);
        $this->assertFalse($called);
        $this->assertNotInstanceOf(StreamedResponse::class, $safe);
        $this->assertSame(['message' => 'Transfer options unavailable.'], json_decode($safe->getContent(), true, 32, JSON_THROW_ON_ERROR));
    }

    public function test_direct_middleware_does_not_swallow_or_report_exceptions(): void
    {
        $exception = new RuntimeException('synthetic exception');
        $this->expectExceptionObject($exception);
        (new ProtectDynamicTransferOptionsResponses)->handle(LaravelRequest::create(self::URI), static function () use ($exception): never {
            throw $exception;
        });
    }

    private function assertCacheProtection(Response $response): void
    {
        foreach (['private', 'no-store'] as $directive) {
            $this->assertTrue($response->headers->hasCacheControlDirective($directive));
        }
        foreach (['public', 'max-age', 's-maxage'] as $directive) {
            $this->assertFalse($response->headers->hasCacheControlDirective($directive));
        }
        foreach (['ETag', 'Last-Modified', 'Expires'] as $header) {
            $this->assertFalse($response->headers->has($header));
        }
    }
}
