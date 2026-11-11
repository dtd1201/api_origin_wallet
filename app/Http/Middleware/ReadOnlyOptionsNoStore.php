<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Unregistered response boundary for the isolated dynamic options adapter only. */
final class ReadOnlyOptionsNoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            // Authentication lookup exceptions are caught before Laravel can log
            // SQL bindings containing a bearer hash. Never interpolate/report it.
            $status = match (true) {
                $exception instanceof AuthenticationException => 401,
                $exception instanceof AuthorizationException => 403,
                $exception instanceof ValidationException => 422,
                $exception instanceof HttpResponseException => $exception->getResponse()->getStatusCode(),
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            $response = $this->error($status);
        }

        // Laravel's routing pipeline may already have rendered an inner failure.
        // Do not expose debug exception/SQL content, even when APP_DEBUG is true.
        if ($response->getStatusCode() >= 500 || (isset($response->exception) && $response->exception instanceof Throwable)) {
            $response = $this->error($response->getStatusCode());
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        foreach (['ETag', 'Last-Modified', 'Expires'] as $header) {
            $response->headers->remove($header);
        }

        return $response;
    }

    private function error(int $status): JsonResponse
    {
        $status = $status >= 400 && $status <= 599 ? $status : 500;

        return new JsonResponse(['message' => match ($status) {
            401 => 'Unauthenticated.',
            403 => 'You are not allowed to access this user resource.',
            404 => 'Not found.',
            422 => 'Invalid transfer options request.',
            default => 'Transfer options unavailable.',
        }], $status);
    }
}
