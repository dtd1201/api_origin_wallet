<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\RecordsAdminAudit;
use App\Http\Controllers\Controller;
use App\Models\FxOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FxOrderController extends Controller
{
    use RecordsAdminAudit;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', 'max:30'],
            'provider_id' => ['sometimes', 'nullable', 'exists:integration_providers,id'],
            'user_id' => ['sometimes', 'nullable', 'exists:users,id'],
        ]);

        $orders = FxOrder::query()
            ->with(['user:id,email,phone,full_name,status,kyc_status', 'provider:id,code,name,logo_url,status'])
            ->when(
                filled($validated['status'] ?? null),
                fn ($query) => $query->where('status', $validated['status'])
            )
            ->when(
                filled($validated['provider_id'] ?? null),
                fn ($query) => $query->where('provider_id', $validated['provider_id'])
            )
            ->when(
                filled($validated['user_id'] ?? null),
                fn ($query) => $query->where('user_id', $validated['user_id'])
            )
            ->latest('id')
            ->paginate(15);

        return response()->json($orders);
    }

    public function show(FxOrder $fxOrder): JsonResponse
    {
        return response()->json(
            $fxOrder->load(['user:id,email,phone,full_name,status,kyc_status', 'provider:id,code,name,logo_url,status'])
        );
    }

    public function update(Request $request, FxOrder $fxOrder): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['pending', 'confirmed', 'rejected', 'cancelled'])],
            'target_amount' => ['sometimes', 'nullable', 'numeric'],
            'fx_rate' => ['sometimes', 'nullable', 'numeric'],
            'fee_amount' => ['sometimes', 'nullable', 'numeric'],
            'fee_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'admin_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'raw_data' => ['sometimes', 'nullable', 'array'],
        ]);

        $before = $fxOrder->toArray();

        $fxOrder = DB::transaction(function () use ($before, $fxOrder, $request, $validated): FxOrder {
            $payload = $this->normalizeUpdatePayload($validated);

            $fxOrder->update($payload);

            $fxOrder = $fxOrder->fresh(['user:id,email,phone,full_name,status,kyc_status', 'provider:id,code,name,logo_url,status']);
            $this->recordAdminAudit($request, 'fx_order.updated', 'fx_order', $fxOrder->id, $before, $fxOrder->toArray());

            return $fxOrder;
        });

        return response()->json($fxOrder);
    }

    public function confirm(Request $request, FxOrder $fxOrder): JsonResponse
    {
        $validated = $request->validate([
            'target_amount' => ['sometimes', 'nullable', 'numeric'],
            'fx_rate' => ['sometimes', 'nullable', 'numeric'],
            'fee_amount' => ['sometimes', 'nullable', 'numeric'],
            'fee_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'admin_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $before = $fxOrder->toArray();

        $fxOrder = DB::transaction(function () use ($before, $fxOrder, $request, $validated): FxOrder {
            $fxOrder->update([
                ...$this->normalizeUpdatePayload($validated),
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'cancelled_at' => null,
            ]);

            $fxOrder = $fxOrder->fresh(['user:id,email,phone,full_name,status,kyc_status', 'provider:id,code,name,logo_url,status']);
            $this->recordAdminAudit($request, 'fx_order.confirmed', 'fx_order', $fxOrder->id, $before, $fxOrder->toArray());

            return $fxOrder;
        });

        return response()->json([
            'message' => 'FX order confirmed successfully.',
            'order' => $fxOrder,
        ]);
    }

    public function reject(Request $request, FxOrder $fxOrder): JsonResponse
    {
        $validated = $request->validate([
            'admin_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $before = $fxOrder->toArray();

        $fxOrder = DB::transaction(function () use ($before, $fxOrder, $request, $validated): FxOrder {
            $fxOrder->update([
                'status' => 'rejected',
                'admin_note' => $validated['admin_note'] ?? $fxOrder->admin_note,
                'cancelled_at' => now(),
            ]);

            $fxOrder = $fxOrder->fresh(['user:id,email,phone,full_name,status,kyc_status', 'provider:id,code,name,logo_url,status']);
            $this->recordAdminAudit($request, 'fx_order.rejected', 'fx_order', $fxOrder->id, $before, $fxOrder->toArray());

            return $fxOrder;
        });

        return response()->json([
            'message' => 'FX order rejected successfully.',
            'order' => $fxOrder,
        ]);
    }

    public function destroy(Request $request, FxOrder $fxOrder): JsonResponse
    {
        $before = $fxOrder->toArray();

        DB::transaction(function () use ($before, $fxOrder, $request): void {
            $this->recordAdminAudit($request, 'fx_order.deleted', 'fx_order', $fxOrder->id, $before, null);
            $fxOrder->delete();
        });

        return response()->json(status: 204);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeUpdatePayload(array $validated): array
    {
        $payload = $validated;

        if (array_key_exists('fee_currency', $payload) && is_string($payload['fee_currency'])) {
            $payload['fee_currency'] = strtoupper($payload['fee_currency']);
        }

        if (($payload['status'] ?? null) === 'confirmed') {
            $payload['confirmed_at'] = now();
            $payload['cancelled_at'] = null;
        }

        if (in_array($payload['status'] ?? null, ['rejected', 'cancelled'], true)) {
            $payload['cancelled_at'] = now();
        }

        return $payload;
    }
}
