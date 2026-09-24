<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\FxOrder;
use App\Models\FxQuote;
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
                ->with([
                    'provider:id,code,name,logo_url,status',
                    'quote',
                ])
                ->latest('id')
                ->get()
        );
    }

    public function show(User $user, FxOrder $fxOrder): JsonResponse
    {
        abort_unless($fxOrder->user_id === $user->id, 404);

        return response()->json($fxOrder->load([
            'provider:id,code,name,logo_url,status',
            'quote',
        ]));
    }

    public function store(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'provider_id' => ['sometimes', 'nullable', 'exists:integration_providers,id'],
            'fx_quote_id' => ['required', 'exists:fx_quotes,id'],
            'raw_data' => ['nullable', 'array'],
        ]);

        if (! in_array(strtolower((string) $user->kyc_status), ['verified', 'approved'], true)) {
            return response()->json([
                'message' => 'User KYC/KYB must be verified before creating an FX order.',
            ], 422);
        }

        $quote = FxQuote::query()
            ->whereKey($validated['fx_quote_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        $provider = PrimaryProvider::resolveForRequest(isset($validated['provider_id']) ? (int) $validated['provider_id'] : null);

        $user->loadMissing(['profile', 'kycProfile']);

        $fxOrder = DB::transaction(function () use ($user, $provider, $validated, $quote): FxOrder {
            return $user->fxOrders()->create([
                'order_no' => 'FXO-'.Str::upper(Str::random(12)),
                'provider_id' => $provider->id,
                'fx_quote_id' => $quote->id,
                'source_currency' => $quote->source_currency,
                'target_currency' => $quote->target_currency,
                'source_amount' => $quote->source_amount,
                'target_amount' => $quote->target_amount,
                'fx_rate' => $quote->net_rate,
                'fee_amount' => $quote->fee_amount,
                'fee_currency' => $quote->target_currency,
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
