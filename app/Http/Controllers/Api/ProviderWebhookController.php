<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationProvider;
use App\Services\Integrations\ProviderWebhookManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ProviderWebhookController extends Controller
{
    public function __invoke(
        IntegrationProvider $provider,
        Request $request,
        ProviderWebhookManager $manager,
    ): JsonResponse {
        try {
            $result = $manager->handle($provider, $request);
        } catch (AccessDeniedHttpException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 403);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json($result);
    }
}
