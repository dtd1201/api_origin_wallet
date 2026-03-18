<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->bearerToken();

        if (! is_string($plainToken) || $plainToken === '') {
            return $this->unauthorizedResponse();
        }

        $token = ApiToken::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if ($token === null || $token->user === null) {
            return $this->unauthorizedResponse();
        }

        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            $token->delete();

            return $this->unauthorizedResponse('Token has expired.');
        }

        $token->forceFill([
            'last_used_at' => now(),
        ])->save();

        Auth::setUser($token->user);
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('apiToken', $token);

        return $next($request);
    }

    private function unauthorizedResponse(string $message = 'Unauthenticated.'): JsonResponse
    {
        return response()->json([
            'message' => $message,
        ], 401);
    }
}
