<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\FxOrder;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Support\PrimaryProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FxOrderController extends Controller
{
    public function index(User $user): JsonResponse
    {
        return response()->json(
            $user->fxOrders()
                ->with('provider:id,code,name,logo_url,status')
                ->latest('id')
                ->get()
        );
    }

    public function show(User $user, FxOrder $fxOrder): JsonResponse
    {
        abort_unless($fxOrder->user_id === $user->id, 404);

        return response()->json($fxOrder->load('provider:id,code,name,logo_url,status'));
    }

    public function store(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'provider_id' => ['sometimes', 'nullable', 'exists:integration_providers,id'],
            'source_currency' => ['required', 'string', 'size:3'],
            'target_currency' => ['required', 'string', 'size:3'],
            'source_amount' => ['required', 'numeric', 'gt:0'],
            'target_amount' => ['nullable', 'numeric'],
            'fx_rate' => ['nullable', 'numeric'],
            'fee_amount' => ['nullable', 'numeric'],
            'fee_currency' => ['nullable', 'string', 'size:3'],
            'raw_data' => ['nullable', 'array'],
        ]);

        if (! in_array(strtolower((string) $user->kyc_status), ['verified', 'approved'], true)) {
            return response()->json([
                'message' => 'User KYC/KYB must be verified before creating an FX order.',
            ], 422);
        }

        $provider = PrimaryProvider::resolveForRequest(isset($validated['provider_id']) ? (int) $validated['provider_id'] : null);

        $user->loadMissing(['profile', 'kycProfile']);

        $fxOrder = DB::transaction(function () use ($user, $provider, $validated): FxOrder {
            return $user->fxOrders()->create([
                'order_no' => 'FXO-'.Str::upper(Str::random(12)),
                'provider_id' => $provider->id,
                'source_currency' => Str::upper($validated['source_currency']),
                'target_currency' => Str::upper($validated['target_currency']),
                'source_amount' => $validated['source_amount'],
                'target_amount' => $validated['target_amount'] ?? null,
                'fx_rate' => $validated['fx_rate'] ?? null,
                'fee_amount' => $validated['fee_amount'] ?? 0,
                'fee_currency' => isset($validated['fee_currency']) ? Str::upper($validated['fee_currency']) : null,
                'status' => 'pending',
                'customer_snapshot' => $this->customerSnapshot($user, $provider),
                'raw_data' => $validated['raw_data'] ?? null,
            ]);
        });

        return response()->json([
            'message' => 'FX order submitted successfully and is pending confirmation.',
            'order' => $fxOrder->fresh('provider:id,code,name,logo_url,status'),
        ], 201);
    }

    private function customerSnapshot(User $user, IntegrationProvider $provider): array
    {
        return [
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'phone' => $user->phone,
                'full_name' => $user->full_name,
                'status' => $user->status,
                'kyc_status' => $user->kyc_status,
            ],
            'profile' => $user->profile?->only([
                'user_type',
                'country_code',
                'company_name',
                'company_reg_no',
                'tax_id',
                'address_line1',
                'address_line2',
                'city',
                'state',
                'postal_code',
            ]),
            'kyc_profile' => $user->kycProfile?->only([
                'status',
                'applicant_type',
                'legal_name',
                'nationality_country_code',
                'residence_country_code',
                'business_name',
                'business_registration_number',
                'tax_id',
                'registered_country_code',
                'country_code',
            ]),
            'provider' => [
                'id' => $provider->id,
                'code' => $provider->code,
                'name' => $provider->name,
                'logo_url' => $provider->logo_url,
            ],
        ];
    }
}
