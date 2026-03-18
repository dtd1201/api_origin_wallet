<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserProfileIsComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->profile === null) {
            return $this->profileRequiredResponse();
        }

        if (blank($user->profile->user_type)) {
            return $this->profileRequiredResponse();
        }

        return $next($request);
    }

    private function profileRequiredResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'User profile must be completed before accessing this resource.',
            'code' => 'PROFILE_REQUIRED',
        ], 409);
    }
}
