<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Integrations\Support\StaticWebhookHeaderVerifier;
use App\Services\Nium\NiumComplianceCallbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NiumComplianceCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        StaticWebhookHeaderVerifier $staticHeaderVerifier,
        NiumComplianceCallbackService $service,
    ): JsonResponse {
        $headerName = (string) config('services.nium.compliance_callback.static_header_name', 'x-partner-key');
        $headerValue = (string) config('services.nium.compliance_callback.static_header_value', '');

        if ($headerName === '' || $headerValue === '') {
            Log::error('Nium compliance callback authentication is not configured.');

            return response()->json([
                'message' => 'Nium compliance callback is not configured.',
            ], 503);
        }

        if (! $staticHeaderVerifier->isValid($request, $headerName, $headerValue)) {
            Log::warning('Rejected Nium compliance callback with invalid static authentication header.', [
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Invalid Nium compliance callback authentication.',
            ], 403);
        }

        return response()->json($service->handle($request), 202);
    }
}
