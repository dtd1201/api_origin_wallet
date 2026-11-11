<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\User;

use App\Domain\Nium\DynamicMultiCurrency\HttpReadiness\ReadOnlyOptionsResponse;
use App\Http\Middleware\AuthenticateReadOnlyOptionsToken;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Auth\RequestGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use JsonException;

/**
 * Unregistered unavailable-only HTTP boundary. No trusted corridor snapshot
 * source exists yet; this controller does not pretend to load or build one.
 */
final class NiumDynamicTransferOptionsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $guard = $request->attributes->get(AuthenticateReadOnlyOptionsToken::GUARD_ATTRIBUTE);
        $token = $request->attributes->get('apiToken');
        $user = $request->user();
        if (! $guard instanceof RequestGuard || ! $token instanceof ApiToken || ! $user instanceof User
            || $guard->user() !== $user || (string) $token->user_id !== (string) $user->getAuthIdentifier()) {
            return $this->response(['message' => 'Unauthenticated.'], 401);
        }
        $route = $request->route();
        $routeUser = $route instanceof Route ? $route->originalParameter('user') : null;
        if (! is_string($routeUser) || preg_match('/\A[1-9][0-9]*\z/', $routeUser) !== 1
            || $routeUser !== (string) $user->getAuthIdentifier()) {
            return $this->response(['message' => 'You are not allowed to access this user resource.'], 403);
        }

        // Inspect original bytes: global TrimStrings/ConvertEmptyStringsToNull
        // must not turn a malformed selector into an accepted canonical ID.
        $rawQuery = (string) $request->server->get('QUERY_STRING', '');
        $selector = null;
        if ($rawQuery !== '') {
            $parts = explode('&', $rawQuery);
            $pair = explode('=', $parts[0], 2);
            if (count($parts) !== 1 || count($pair) !== 2 || urldecode($pair[0]) !== 'beneficiary_id') {
                return $this->invalid();
            }
            $selector = urldecode($pair[1]);
            if (preg_match('/\A[1-9][0-9]*\z/', $selector) !== 1
                || filter_var($selector, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                return $this->invalid();
            }
        }
        $content = trim($request->getContent());
        if ($content !== '') {
            try {
                if (json_decode($content, true, 512, JSON_THROW_ON_ERROR) !== []) {
                    return $this->invalid();
                }
            } catch (JsonException) {
                return $this->invalid();
            }
        }
        if ($request->request->all() !== []) {
            return $this->invalid();
        }
        if ($selector !== null && ! $user->beneficiaries()->whereKey($selector)->exists()) {
            return $this->response(['message' => 'Not found.'], 404);
        }

        // Ownership is the only verified selection boundary. Provider/account,
        // environment, legal entity, wallet and evidence bindings remain unknown.
        // No beneficiary projection/compatibility or corridor is invented here.
        return $this->response(['options' => [], 'reason_codes' => ['trusted_snapshot_unavailable']], 200);
    }

    private function invalid(): JsonResponse
    {
        return $this->response(['message' => 'Invalid transfer options request.'], 422);
    }

    private function response(array $body, int $status): JsonResponse
    {
        $response = new ReadOnlyOptionsResponse($status, $body);

        return new JsonResponse($response->body(), $response->status(), $response->headers());
    }
}
