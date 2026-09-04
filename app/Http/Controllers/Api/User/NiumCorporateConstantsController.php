<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Nium\NiumCorporateConstantsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NiumCorporateConstantsController extends Controller
{
    public function __invoke(Request $request, User $user, NiumCorporateConstantsService $service): JsonResponse
    {
        $validated = $request->validate([
            'region' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z]{2,10}$/'],
            'customerType' => ['required', 'in:CORPORATE'],
            'countryCode' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'category' => ['required', 'string', Rule::in(NiumCorporateConstantsService::CATEGORIES)],
        ]);
        $result = $service->values($user, $validated['region'], $validated['category'], $validated['countryCode'] ?? null);

        return response()->json([
            'region' => strtoupper($validated['region']),
            'customerType' => 'CORPORATE',
            'countryCode' => strtoupper($validated['countryCode'] ?? ''),
            'category' => $validated['category'],
            'values' => $result['values'],
            'source' => $result['source'],
        ]);
    }
}
