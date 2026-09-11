<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Nium\NiumPurposeCodeService;
use Illuminate\Http\JsonResponse;
use Throwable;

final class NiumPurposeCodeController extends Controller
{
    public function __invoke(User $user, NiumPurposeCodeService $purposeCodes): JsonResponse
    {
        try {
            return response()->json(['data' => $purposeCodes->supported($user)]);
        } catch (Throwable) {
            return response()->json(['message' => 'Payment purposes are temporarily unavailable.'], 502);
        }
    }
}
