<?php

namespace App\Http\Middleware;

use App\Exceptions\AuthenticationServiceUnavailable;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuthNoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        // Laravel's routing pipeline renders downstream exceptions before
        // returning here. Exception sanitization belongs in the handler hook.
        return self::headers($next($request));
    }

    public static function appliesTo(Request $request): bool
    {
        return $request->is('api/auth/*', 'api/admin/auth/*');
    }

    /** Invoked by Laravel's exception handler, after service transactions unwind. */
    public static function renderException(Request $request, Throwable $exception): ?JsonResponse
    {
        if (! self::appliesTo($request)) {
            return null;
        }
        if ($exception instanceof ValidationException) {
            return self::headers(response()->json(['message' => 'Invalid authentication request.',
                'errors' => $exception->errors()], 422));
        }
        $status = self::exceptionStatus($exception);
        $message = match (true) {
            $status >= 500 => $status === 503 ? 'Authentication service is unavailable.' : 'Server Error',
            $exception instanceof AuthenticationException => 'Unauthenticated.',
            $status === 404 => 'Not Found',
            default => $exception->getMessage() ?: 'Authentication could not be completed.',
        };

        return self::headers(response()->json(['message' => $message], $status,
            $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []));
    }

    public static function exceptionStatus(Throwable $exception): int
    {
        return match (true) {
            $exception instanceof AuthenticationServiceUnavailable => 503,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof AuthenticationException => 401,
            $exception instanceof ValidationException => 422,
            default => 500,
        };
    }

    private static function headers(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
