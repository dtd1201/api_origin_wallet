<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Kyc\BusinessRegistryVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessRegistryVerificationController extends Controller
{
    public function __invoke(
        Request $request,
        User $user,
        BusinessRegistryVerificationService $verificationService,
    ): JsonResponse {
        $validated = $request->validate([
            'country_code' => ['required', 'string', 'size:2'],
            'business_registration_number' => ['nullable', 'string', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'business_name' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $verificationService->verify(
            countryCode: (string) $validated['country_code'],
            businessRegistrationNumber: $validated['business_registration_number'] ?? null,
            taxId: $validated['tax_id'] ?? null,
            businessName: $validated['business_name'] ?? null,
        );

        return response()->json([
            'data' => $result,
        ]);
    }
}
