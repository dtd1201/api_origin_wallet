<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Nium\DynamicMultiCurrency\HttpReadiness\LocalTokenSnapshot;
use App\Domain\Nium\DynamicMultiCurrency\HttpReadiness\ReadOnlyCredentialPolicy;
use App\Models\ApiToken;
use App\Models\User;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Unregistered bearer authentication for this read-only adapter only. A new
 * request-local guard preserves Request::user() without SessionGuard's
 * Authenticated event, global guard mutation, login or event suppression.
 */
final class AuthenticateReadOnlyOptionsToken implements AuthenticatesRequests
{
    public const GUARD_ATTRIBUTE = 'nium.dynamic_options.request_guard';

    public function handle(Request $request, Closure $next): Response
    {
        // Own the no-store boundary here: Laravel sorts this authenticator ahead
        // of SubstituteBindings and can move it outside a separate route wrapper.
        return (new ReadOnlyOptionsNoStore)->handle($request, function (Request $request) use ($next): Response {
            $request->setUserResolver(static fn () => null);
            $request->attributes->remove('apiToken');
            $request->attributes->remove(self::GUARD_ATTRIBUTE);
            $headers = $request->headers->all('authorization');
            $header = $request->headers->get('Authorization');
            if (count($headers) !== 1 || ! is_string($header)
                || preg_match('/\ABearer ([A-Za-z0-9._~+\/-]+=*)\z/i', $header, $matches) !== 1) {
                return new JsonResponse(['message' => 'Unauthenticated.'], 401);
            }
            $plainToken = $request->bearerToken();
            if (! is_string($plainToken) || $plainToken !== $matches[1]) {
                return new JsonResponse(['message' => 'Unauthenticated.'], 401);
            }

            // No cached models/positive lookups: deletion revocation is checked
            // on every request. Existing ApiToken/User query scopes are preserved.
            $token = ApiToken::query()->with('user')
                ->where('token_hash', hash('sha256', $plainToken))->first();
            $snapshot = $token === null ? null : $this->snapshot($token);
            $actor = (new ReadOnlyCredentialPolicy)->authenticatedUserId(
                $plainToken, $snapshot, DateTimeImmutable::createFromInterface(now()),
            );
            if ($actor === null) {
                return new JsonResponse(['message' => 'Unauthenticated.'], 401);
            }

            $route = $request->route();
            $routeUser = $route instanceof Route ? $route->originalParameter('user') : null;
            if (! is_string($routeUser) || preg_match('/\A[1-9][0-9]*\z/', $routeUser) !== 1 || $routeUser !== $actor) {
                return new JsonResponse(['message' => 'You are not allowed to access this user resource.'], 403);
            }

            $guard = new RequestGuard(static fn () => null, $request);
            $guard->setUser($token->user);
            $request->setUserResolver(static fn () => $guard->user());
            $request->attributes->set(self::GUARD_ATTRIBUTE, $guard);
            $request->attributes->set('apiToken', $token);

            return $next($request);
        });
    }

    private function snapshot(ApiToken $token): ?LocalTokenSnapshot
    {
        $user = $token->user;
        $attributes = $token->getAttributes();
        if (! $user instanceof User || ! array_key_exists('expires_at', $attributes)) {
            return null;
        }
        $rawExpiry = $attributes['expires_at'];
        $expiry = null;
        if ($rawExpiry !== null) {
            if (! is_string($rawExpiry)
                || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/', $rawExpiry) !== 1) {
                return null;
            }
            try {
                $cast = $token->expires_at;
                if (! $cast instanceof DateTimeInterface || $cast->format('Y-m-d H:i:s') !== substr($rawExpiry, 0, 19)) {
                    return null;
                }
                $expiry = DateTimeImmutable::createFromInterface($cast);
            } catch (Throwable) {
                return null;
            }
        }

        return new LocalTokenSnapshot((string) $token->token_hash, (string) $token->user_id,
            (string) $user->getAuthIdentifier(), $expiry);
    }
}
