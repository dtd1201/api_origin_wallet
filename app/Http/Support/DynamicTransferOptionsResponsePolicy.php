<?php

declare(strict_types=1);

namespace App\Http\Support;

use JsonException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Uninstalled, stateless response policy. No evidence, authentication or I/O.
 * Nonmatches retain their exact response object. Matches produce a fresh safe
 * response, without mutating the request, input response, globals or cookies.
 */
final class DynamicTransferOptionsResponsePolicy
{
    private const PATH = '~\A/api/user/users/[^/]*/dynamic-transfer-options\z~';

    private const UNAVAILABLE = ['options' => [], 'reason_codes' => ['trusted_snapshot_unavailable']];

    private const PROFILE_REQUIRED = [
        'message' => 'User profile must be completed before accessing this resource.',
        'code' => 'PROFILE_REQUIRED',
    ];

    private const PROTOCOL_HEADERS = [
        'Date', 'Allow', 'Retry-After', 'Refresh', 'WWW-Authenticate', 'Vary',
        'Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials',
        'Access-Control-Allow-Methods', 'Access-Control-Allow-Headers',
        'Access-Control-Expose-Headers', 'Access-Control-Max-Age',
        'Access-Control-Allow-Private-Network',
    ];

    public function matches(Request $request): bool
    {
        $path = $request->getPathInfo();

        return preg_match(self::PATH, rtrim($path, '/')) === 1
            || preg_match(self::PATH, rtrim(rawurldecode($path), '/')) === 1;
    }

    /**
     * The final exception-response callback MUST supply its Throwable. An
     * optional Laravel response attachment is only an additional signal, never
     * the basis of diagnostic protection. No reporting/rendering is invoked.
     */
    public function normalize(Request $request, Response $response, ?Throwable $exception = null): Response
    {
        if (! $this->matches($request)) {
            return $response;
        }

        $status = $response->getStatusCode();
        $headers = $this->protocolHeaders($response);
        $isException = $exception !== null
            || (isset($response->exception) && $response->exception instanceof Throwable);
        $opaque = $response instanceof StreamedResponse || $response instanceof BinaryFileResponse;

        if ($response->isInformational() || $status === 204 || $status === 304) {
            // Preserve synthetic 304; never invent its lost representation.
            $safe = new Response(null, $status, $headers);
            $safe->headers->remove('Content-Type');
        } elseif (! $isException && ! $opaque && $request->isMethod('OPTIONS')
            && $status === 200 && $response->getContent() === '' && $response->headers->has('Allow')) {
            // Laravel's synthesized plain OPTIONS response is protocol metadata.
            $safe = new Response('', $status, $headers);
        } else {
            $body = ['message' => match ($status) {
                400 => 'Bad request.',
                401 => 'Unauthenticated.',
                403 => 'You are not allowed to access this user resource.',
                404 => 'Not found.',
                405 => 'Method not allowed.',
                413 => 'Request content too large.',
                422 => 'Invalid transfer options request.',
                default => 'Transfer options unavailable.',
            }];

            if (! $isException && ! $opaque) {
                if ($status === 200 && $this->isDto($response, self::UNAVAILABLE)) {
                    $body = self::UNAVAILABLE;
                } elseif ($status === 409 && $this->isDto($response, self::PROFILE_REQUIRED)) {
                    $body = self::PROFILE_REQUIRED;
                }
            }

            // Re-encode fixed data, never forward raw JSON: duplicate keys,
            // JSONP, extra fields, HTML, custom renderers and streams cannot
            // smuggle diagnostic content through a successful DTO comparison.
            $safe = new JsonResponse($body, $status, $headers);
        }

        $safe->setProtocolVersion($response->getProtocolVersion());
        if ($request->isMethod('HEAD')) {
            $safe->setContent(null);
        }
        // Deliberately avoid Response::prepare (it can change process INI and
        // cookie state) and isNotModified. Fresh responses carry no stale entity
        // lengths, encodings, digests, cookies or arbitrary diagnostic headers.
        $safe->headers->remove('Content-Length');
        $safe->headers->set('Cache-Control', 'private, no-store');
        foreach (['ETag', 'Last-Modified', 'Expires'] as $header) {
            $safe->headers->remove($header);
        }

        return $safe;
    }

    private function protocolHeaders(Response $response): array
    {
        $headers = [];
        foreach (self::PROTOCOL_HEADERS as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = $response->headers->all($name);
            }
        }
        if ($response->isRedirection() && $response->getStatusCode() !== 304 && $response->headers->has('Location')) {
            $headers['Location'] = $response->headers->all('Location');
        }

        return $headers;
    }

    private function isDto(Response $response, array $expected): bool
    {
        if (strtolower(trim(explode(';', (string) $response->headers->get('Content-Type'))[0])) !== 'application/json') {
            return false;
        }

        try {
            // Object decoding distinguishes [] from {} in options/reason_codes.
            $decoded = json_decode((string) $response->getContent(), false, 32, JSON_THROW_ON_ERROR);

            if (! $decoded instanceof \stdClass) {
                return false;
            }
            $properties = get_object_vars($decoded);
            if (count($properties) !== count($expected)) {
                return false;
            }
            foreach ($expected as $key => $value) {
                if (! array_key_exists($key, $properties) || $properties[$key] !== $value) {
                    return false;
                }
            }

            return true;
        } catch (JsonException) {
            return false;
        }
    }
}
