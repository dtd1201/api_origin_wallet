<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Nium\NiumCorporateConstantsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NiumCorporateConstantsController extends Controller
{
    public function __invoke(Request $request, User $user, NiumCorporateConstantsService $service): JsonResponse
    {
        $validated = $request->validate([
            'region' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z]{2,10}$/'],
            'customerType' => ['required', 'in:CORPORATE'],
            'countryCode' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'type' => ['sometimes', 'in:STATE,SUBDIVISION'],
        ]);
        $result = $service->subdivisions($user, $validated['region'], $validated['countryCode']);

        return response()->json([
            'region' => strtoupper($validated['region']),
            'customerType' => 'CORPORATE',
            'countryCode' => strtoupper($validated['countryCode']),
            'type' => 'STATE',
            'values' => $result['values'],
            'source' => $result['source'],
        ]);
    }
}
