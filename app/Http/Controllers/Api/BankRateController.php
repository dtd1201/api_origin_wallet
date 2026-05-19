<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ManagedExchangeRate;
use App\Services\Quotes\ManagedExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankRateController extends Controller
{
    public function index(Request $request, ManagedExchangeRateService $rateService): JsonResponse
    {
        return response()->json($this->rates($request, $rateService, ManagedExchangeRate::AUDIENCE_PUBLIC));
    }

    public function authenticated(Request $request, ManagedExchangeRateService $rateService): JsonResponse
    {
        return response()->json($this->rates($request, $rateService, ManagedExchangeRate::AUDIENCE_AUTHENTICATED));
    }

    private function rates(Request $request, ManagedExchangeRateService $rateService, string $audience): array
    {
        $validated = $request->validate([
            'source_currency' => ['sometimes', 'string', 'size:3'],
            'target_currency' => ['sometimes', 'string', 'size:3'],
            'source_amount' => ['sometimes', 'numeric', 'gt:0'],
        ]);

        return $rateService->rates(
            rateType: ManagedExchangeRate::TYPE_BANK,
            audience: $audience,
            sourceCurrency: (string) ($validated['source_currency'] ?? 'USD'),
            targetCurrency: (string) ($validated['target_currency'] ?? 'VND'),
            sourceAmount: (float) ($validated['source_amount'] ?? 1),
        );
    }
}
