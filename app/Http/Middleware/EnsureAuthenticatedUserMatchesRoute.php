<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticatedUserMatchesRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        $routeUser = $request->route('user');
        $authenticatedUser = $request->user();

        if (! $authenticatedUser instanceof User) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $routeUserId = $routeUser instanceof User ? $routeUser->id : (int) $routeUser;

        if ($routeUserId !== $authenticatedUser->id) {
            return response()->json([
                'message' => 'You are not allowed to access this user resource.',
            ], 403);
        }

        return $next($request);
    }
}
