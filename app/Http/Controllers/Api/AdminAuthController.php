<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\Auth\ApiAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    public function __construct(
        private readonly ApiAuthService $apiAuthService,
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->with(['profile', 'providerAccounts.provider', 'roles'])
            ->where('email', $validated['email'])
            ->first();

        if ($user === null || ! Hash::check($validated['password'], $user->password_hash)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 422);
        }

        if (! $user->isAdmin()) {
            return response()->json([
                'message' => 'This account is not allowed to access admin.',
            ], 403);
        }

        return response()->json($this->apiAuthService->startLogin($user), 202);
    }

    public function verifyLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'verification_code' => ['required', 'digits:6'],
        ]);

        [, $payload] = $this->apiAuthService->completeLogin(
            $validated['email'],
            $validated['verification_code'],
            (string) $request->userAgent(),
            function (User $user): void {
                abort_if(! $user->isAdmin(), 403, 'This account is not allowed to access admin.');
            }
        );

        return response()->json([
            'message' => 'Login successful.',
            ...$payload,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user()->load(['profile', 'providerAccounts.provider', 'roles']);

        abort_unless($user->isAdmin(), 403, 'You are not allowed to access admin resources.');

        return response()->json($this->apiAuthService->buildAuthPayload($user));
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var ApiToken|null $token */
        $token = $request->attributes->get('apiToken');

        $this->apiAuthService->logout($token);

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
