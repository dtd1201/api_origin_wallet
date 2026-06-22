<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\RecordsAdminAudit;
use App\Http\Controllers\Controller;
use App\Models\IntegrationProvider;
use App\Models\ManagedExchangeRate;
use App\Services\Quotes\PublicProviderRateCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManagedExchangeRateController extends Controller
{
    use RecordsAdminAudit;

    public function __construct(private readonly PublicProviderRateCache $publicProviderRateCache) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rate_type' => ['sometimes', Rule::in([ManagedExchangeRate::TYPE_PROVIDER, ManagedExchangeRate::TYPE_BANK])],
            'audience' => ['sometimes', Rule::in([ManagedExchangeRate::AUDIENCE_PUBLIC, ManagedExchangeRate::AUDIENCE_AUTHENTICATED])],
            'source_currency' => ['sometimes', 'string', 'size:3'],
            'target_currency' => ['sometimes', 'string', 'size:3'],
            'status' => ['sometimes', 'string', 'max:30'],
        ]);

        $rates = ManagedExchangeRate::query()
            ->with('provider:id,code,name,logo_url,status')
            ->when($validated['rate_type'] ?? null, fn ($query, $value) => $query->where('rate_type', $value))
            ->when($validated['audience'] ?? null, fn ($query, $value) => $query->where('audience', $value))
            ->when(
                $validated['source_currency'] ?? null,
                fn ($query, $value) => $query->where('source_currency', strtoupper((string) $value))
            )
            ->when(
                $validated['target_currency'] ?? null,
                fn ($query, $value) => $query->where('target_currency', strtoupper((string) $value))
            )
            ->when($validated['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->orderBy('rate_type')
            ->orderBy('audience')
            ->orderBy('display_order')
            ->orderBy('source_name')
            ->paginate(50);

        return response()->json($rates);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedPayload($request);
        $this->assertHasRateValue($validated);
        $validated = $this->normalizePayload($validated);

        $rate = DB::transaction(function () use ($request, $validated): ManagedExchangeRate {
            $rate = ManagedExchangeRate::query()->create($validated);
            $this->recordAdminAudit($request, 'exchange_rate.created', 'managed_exchange_rate', $rate->id, null, $rate->toArray());

            return $rate;
        });
        $this->clearRateCaches();

        return response()->json($rate->load('provider:id,code,name,logo_url,status'), 201);
    }

    public function show(ManagedExchangeRate $exchangeRate): JsonResponse
    {
        return response()->json($exchangeRate->load('provider:id,code,name,logo_url,status'));
    }

    public function update(Request $request, ManagedExchangeRate $exchangeRate): JsonResponse
    {
        $validated = $this->validatedPayload($request, updating: true);
        $candidate = [
            ...$exchangeRate->only(['buy_rate', 'sell_rate', 'mid_rate']),
            ...$validated,
        ];
        $this->assertHasRateValue($candidate);
        $validated = $this->normalizePayload($validated, $exchangeRate);

        $before = $exchangeRate->toArray();

        $rate = DB::transaction(function () use ($before, $exchangeRate, $request, $validated): ManagedExchangeRate {
            $exchangeRate->update($validated);

            $exchangeRate = $exchangeRate->fresh();
            $this->recordAdminAudit($request, 'exchange_rate.updated', 'managed_exchange_rate', $exchangeRate->id, $before, $exchangeRate->toArray());

            return $exchangeRate;
        });
        $this->clearRateCaches();

        return response()->json($rate->load('provider:id,code,name,logo_url,status'));
    }

    public function destroy(Request $request, ManagedExchangeRate $exchangeRate): JsonResponse
    {
        $before = $exchangeRate->toArray();

        DB::transaction(function () use ($before, $exchangeRate, $request): void {
            $this->recordAdminAudit($request, 'exchange_rate.deleted', 'managed_exchange_rate', $exchangeRate->id, $before, null);
            $exchangeRate->delete();
        });
        $this->clearRateCaches();

        return response()->json(status: 204);
    }

    private function validatedPayload(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'rate_type' => [$required, Rule::in([ManagedExchangeRate::TYPE_PROVIDER, ManagedExchangeRate::TYPE_BANK])],
            'audience' => [$required, Rule::in([ManagedExchangeRate::AUDIENCE_PUBLIC, ManagedExchangeRate::AUDIENCE_AUTHENTICATED])],
            'provider_id' => ['sometimes', 'nullable', 'exists:integration_providers,id'],
            'source_code' => [$required, 'string', 'max:100'],
            'source_name' => [$required, 'string', 'max:255'],
            'source_currency' => [$required, 'string', 'size:3'],
            'target_currency' => [$required, 'string', 'size:3'],
            'buy_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'sell_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'mid_rate' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'fee_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', 'max:30'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'published_at' => ['sometimes', 'nullable', 'date'],
        ]);
    }

    private function normalizePayload(array $payload, ?ManagedExchangeRate $existingRate = null): array
    {
        if (isset($payload['source_code'])) {
            $payload['source_code'] = strtolower(trim((string) $payload['source_code']));
        }

        if (isset($payload['source_currency'])) {
            $payload['source_currency'] = strtoupper((string) $payload['source_currency']);
        }

        if (isset($payload['target_currency'])) {
            $payload['target_currency'] = strtoupper((string) $payload['target_currency']);
        }

        $rateType = $payload['rate_type'] ?? $existingRate?->rate_type;
        $sourceCode = $payload['source_code'] ?? $existingRate?->source_code;

        if ($rateType === ManagedExchangeRate::TYPE_PROVIDER && empty($payload['provider_id']) && $sourceCode) {
            $provider = IntegrationProvider::query()
                ->whereRaw('lower(code) = ?', [strtolower((string) $sourceCode)])
                ->first();

            if ($provider) {
                $payload['provider_id'] = $provider->id;
                $payload['source_name'] = $payload['source_name'] ?? $provider->name;
            }
        }

        $payload['fee_amount'] = $payload['fee_amount'] ?? $existingRate?->fee_amount ?? 0;
        $payload['status'] = $payload['status'] ?? $existingRate?->status ?? 'active';
        $payload['display_order'] = $payload['display_order'] ?? $existingRate?->display_order ?? 0;
        $payload['published_at'] = $payload['published_at'] ?? $existingRate?->published_at ?? now();

        return $payload;
    }

    private function assertHasRateValue(array $payload): void
    {
        if (
            blank($payload['buy_rate'] ?? null)
            && blank($payload['sell_rate'] ?? null)
            && blank($payload['mid_rate'] ?? null)
        ) {
            throw ValidationException::withMessages([
                'rate' => ['At least one of buy_rate, sell_rate, or mid_rate is required.'],
            ]);
        }
    }

    private function clearRateCaches(): void
    {
        $this->publicProviderRateCache->flush();
    }
}
