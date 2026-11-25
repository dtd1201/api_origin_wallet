<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\Auth\ApiAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuthController extends Controller
{
    public function __construct(
        private readonly ApiAuthService $apiAuthService,
    ) {}

    public function login(Request $request): JsonResponse
    {
        return app(MandatoryAuthController::class)->login($request);
    }

    public function verifyLogin(Request $request): JsonResponse
    {
        return app(MandatoryAuthController::class)->verify($request);
    }

    public function resendLogin(Request $request): JsonResponse
    {
        return app(MandatoryAuthController::class)->retired($request);
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
