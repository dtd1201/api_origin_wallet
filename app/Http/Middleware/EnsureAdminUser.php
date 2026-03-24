<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->forbiddenResponse('Unauthenticated.', 401);
        }

        $user->loadMissing('roles');

        if (! $user->isAdmin()) {
            return $this->forbiddenResponse('You are not allowed to access admin resources.', 403);
        }

        return $next($request);
    }

    private function forbiddenResponse(string $message, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
        ], $status);
    }
}
