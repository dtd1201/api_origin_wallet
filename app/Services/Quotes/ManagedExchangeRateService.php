<?php

namespace App\Services\Quotes;

use App\Models\ManagedExchangeRate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ManagedExchangeRateService
{
    public function rates(
        string $rateType,
        string $audience,
        string $sourceCurrency,
        string $targetCurrency,
        float $sourceAmount,
    ): array {
        $sourceCurrency = strtoupper($sourceCurrency);
        $targetCurrency = strtoupper($targetCurrency);
        $sourceAmount = round($sourceAmount, 8);
        $audience = $this->normalizeAudience($audience);
        $rates = $this->activeRates($rateType, $audience, $sourceCurrency, $targetCurrency);

        return [
            'data' => $rates
                ->map(fn (ManagedExchangeRate $rate): array => $this->ratePayload($rate, $sourceAmount))
                ->values()
                ->all(),
            'meta' => [
                'rate_type' => $rateType,
                'audience' => $audience,
                'source_currency' => $sourceCurrency,
                'target_currency' => $targetCurrency,
                'source_amount' => $sourceAmount,
                'refreshed_at' => now()->toISOString(),
                'refresh_interval_seconds' => $this->refreshIntervalSeconds(),
            ],
        ];
    }

    public function providerQuote(
        string $providerCode,
        string $audience,
        string $sourceCurrency,
        string $targetCurrency,
        float $sourceAmount,
    ): ?array {
        $rate = $this->activeRateByCode(
            rateType: ManagedExchangeRate::TYPE_PROVIDER,
            audience: $audience,
            sourceCode: $providerCode,
            sourceCurrency: $sourceCurrency,
            targetCurrency: $targetCurrency,
        );

        return $rate ? $this->quotePayload($rate, $sourceAmount) : null;
    }

    public function quotePayload(ManagedExchangeRate $rate, float $sourceAmount): array
    {
        $netRate = $this->netRate($rate);

        return [
            'source_currency' => strtoupper($rate->source_currency),
            'target_currency' => strtoupper($rate->target_currency),
            'source_amount' => $sourceAmount,
            'target_amount' => round($sourceAmount * $netRate, 8),
            'mid_rate' => $rate->mid_rate !== null ? (float) $rate->mid_rate : $netRate,
            'net_rate' => $netRate,
            'buy_rate' => $rate->buy_rate !== null ? (float) $rate->buy_rate : null,
            'sell_rate' => $rate->sell_rate !== null ? (float) $rate->sell_rate : null,
            'fee_amount' => (float) $rate->fee_amount,
            'expires_at' => now()->addSeconds($this->refreshIntervalSeconds())->toISOString(),
            'quoted_at' => ($rate->published_at ?? $rate->updated_at ?? now())->toISOString(),
            'source' => [
                'type' => 'admin_rate',
                'name' => 'Admin configured rate',
                'source_code' => $rate->source_code,
                'source_name' => $rate->source_name,
                'audience' => $rate->audience,
                'attribution_required' => false,
            ],
        ];
    }

    private function activeRateByCode(
        string $rateType,
        string $audience,
        string $sourceCode,
        string $sourceCurrency,
        string $targetCurrency,
    ): ?ManagedExchangeRate {
        return $this->activeRates(
            $rateType,
            $audience,
            strtoupper($sourceCurrency),
            strtoupper($targetCurrency),
        )
            ->first(fn (ManagedExchangeRate $rate): bool => strtolower($rate->source_code) === strtolower($sourceCode));
    }

    private function activeRates(
        string $rateType,
        string $audience,
        string $sourceCurrency,
        string $targetCurrency,
    ): Collection {
        $audience = $this->normalizeAudience($audience);
        $allowedAudiences = $audience === ManagedExchangeRate::AUDIENCE_AUTHENTICATED
            ? [ManagedExchangeRate::AUDIENCE_PUBLIC, ManagedExchangeRate::AUDIENCE_AUTHENTICATED]
            : [ManagedExchangeRate::AUDIENCE_PUBLIC];

        return ManagedExchangeRate::query()
            ->where('rate_type', $rateType)
            ->whereIn('audience', $allowedAudiences)
            ->where('status', 'active')
            ->where('source_currency', strtoupper($sourceCurrency))
            ->where('target_currency', strtoupper($targetCurrency))
            ->orderByRaw("case when audience = ? then 0 else 1 end", [$audience])
            ->orderBy('display_order')
            ->orderBy('source_name')
            ->get()
            ->unique(fn (ManagedExchangeRate $rate): string => strtolower($rate->source_code))
            ->sortBy([
                ['display_order', 'asc'],
                ['source_name', 'asc'],
            ])
            ->values();
    }

    private function ratePayload(ManagedExchangeRate $rate, float $sourceAmount): array
    {
        return [
            'id' => $rate->id,
            'rate_type' => $rate->rate_type,
            'audience' => $rate->audience,
            'source_code' => $rate->source_code,
            'source_name' => $rate->source_name,
            'source_currency' => strtoupper($rate->source_currency),
            'target_currency' => strtoupper($rate->target_currency),
            'source_amount' => $sourceAmount,
            'target_amount' => round($sourceAmount * $this->netRate($rate), 8),
            'buy_rate' => $rate->buy_rate !== null ? (float) $rate->buy_rate : null,
            'sell_rate' => $rate->sell_rate !== null ? (float) $rate->sell_rate : null,
            'mid_rate' => $rate->mid_rate !== null ? (float) $rate->mid_rate : null,
            'net_rate' => $this->netRate($rate),
            'fee_amount' => (float) $rate->fee_amount,
            'status' => $rate->status,
            'display_order' => $rate->display_order,
            'notes' => $rate->notes,
            'published_at' => $rate->published_at?->toISOString(),
            'updated_at' => $rate->updated_at?->toISOString(),
        ];
    }

    private function netRate(ManagedExchangeRate $rate): float
    {
        if ($rate->sell_rate !== null) {
            return (float) $rate->sell_rate;
        }

        if ($rate->mid_rate !== null) {
            return (float) $rate->mid_rate;
        }

        return (float) $rate->buy_rate;
    }

    private function normalizeAudience(string $audience): string
    {
        return $audience === ManagedExchangeRate::AUDIENCE_AUTHENTICATED
            ? ManagedExchangeRate::AUDIENCE_AUTHENTICATED
            : ManagedExchangeRate::AUDIENCE_PUBLIC;
    }

    private function refreshIntervalSeconds(): int
    {
        return max(15, (int) config('services.managed_exchange_rates.refresh_interval_seconds', 300));
    }
}
