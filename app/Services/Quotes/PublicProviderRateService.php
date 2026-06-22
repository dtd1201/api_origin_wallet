<?php

namespace App\Services\Quotes;

use App\Models\IntegrationProvider;
use App\Services\Nium\NiumService;
use App\Support\PrimaryProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PublicProviderRateService
{
    public function __construct(
        private readonly NiumService $niumService,
        private readonly ManagedExchangeRateService $managedExchangeRateService,
        private readonly PublicProviderRateCache $rateCache,
    ) {}

    public function rates(
        string $sourceCurrency,
        string $targetCurrency,
        float $sourceAmount,
        string $audience = 'public',
    ): array {
        $sourceCurrency = strtoupper($sourceCurrency);
        $targetCurrency = strtoupper($targetCurrency);
        $sourceAmount = round($sourceAmount, 8);
        $audience = $audience === 'authenticated' ? 'authenticated' : 'public';
        $cacheTtl = $this->cacheTtlSeconds();
        $cacheKey = implode(':', [
            'public_provider_rates',
            'v'.$this->rateCache->version(),
            $sourceCurrency,
            $targetCurrency,
            number_format($sourceAmount, 8, '.', ''),
            $audience,
        ]);

        return Cache::remember($cacheKey, now()->addSeconds($cacheTtl), function () use (
            $sourceCurrency,
            $targetCurrency,
            $sourceAmount,
            $cacheTtl,
            $audience,
        ): array {
            $providers = IntegrationProvider::query()
                ->where('code', PrimaryProvider::code())
                ->orderBy('id')
                ->get();

            return [
                'data' => $providers
                    ->map(fn (IntegrationProvider $provider): array => $this->providerRate(
                        $provider,
                        $sourceCurrency,
                        $targetCurrency,
                        $sourceAmount,
                        $audience,
                    ))
                    ->values()
                    ->all(),
                'meta' => [
                    'source_currency' => $sourceCurrency,
                    'target_currency' => $targetCurrency,
                    'source_amount' => $sourceAmount,
                    'audience' => $audience,
                    'refreshed_at' => now()->toISOString(),
                    'refresh_interval_seconds' => $cacheTtl,
                ],
            ];
        });
    }

    private function providerRate(
        IntegrationProvider $provider,
        string $sourceCurrency,
        string $targetCurrency,
        float $sourceAmount,
        string $audience,
    ): array {
        $base = [
            'provider' => [
                'id' => $provider->id,
                'code' => $provider->code,
                'name' => $provider->name,
                'logo_url' => $provider->logo_url,
                'status' => $provider->status,
                'is_available_for_onboarding' => $provider->isAvailableForOnboarding(),
                'supports_beneficiaries' => $provider->supportsBeneficiaries(),
                'supports_data_sync' => $provider->supportsDataSync(),
                'supports_quotes' => $provider->supportsQuotes(),
                'supports_transfers' => $provider->supportsTransfers(),
                'supports_webhooks' => $provider->supportsWebhooks(),
                'is_configured' => $provider->isConfigured(),
            ],
            'quote_status' => 'unavailable',
            'quote' => null,
            'message' => null,
        ];

        if ($provider->status !== 'active') {
            return [
                ...$base,
                'message' => 'Provider is not active.',
            ];
        }

        $managedQuote = $this->managedExchangeRateService->providerQuote(
            providerCode: $provider->code,
            audience: $audience,
            sourceCurrency: $sourceCurrency,
            targetCurrency: $targetCurrency,
            sourceAmount: $sourceAmount,
        );

        if ($managedQuote !== null) {
            return [
                ...$base,
                'quote_status' => 'managed',
                'quote' => $managedQuote,
                'message' => $audience === 'authenticated'
                    ? 'Showing authenticated admin configured rate.'
                    : 'Showing public admin configured rate.',
            ];
        }

        if (! $provider->supportsQuotes()) {
            return $this->marketReferenceRate(
                base: $base,
                sourceCurrency: $sourceCurrency,
                targetCurrency: $targetCurrency,
                sourceAmount: $sourceAmount,
                message: 'Provider does not support public FX quotes. Showing market reference rate.',
            );
        }

        if (! $provider->isConfigured()) {
            return $this->marketReferenceRate(
                base: $base,
                sourceCurrency: $sourceCurrency,
                targetCurrency: $targetCurrency,
                sourceAmount: $sourceAmount,
                message: 'Provider is not configured. Showing market reference rate.',
            );
        }

        try {
            $quote = $this->liveQuote($provider, $sourceCurrency, $targetCurrency, $sourceAmount);

            return [
                ...$base,
                'quote_status' => 'ready',
                'quote' => $quote,
            ];
        } catch (\Throwable $exception) {
            return [
                ...$base,
                'quote_status' => 'error',
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function liveQuote(
        IntegrationProvider $provider,
        string $sourceCurrency,
        string $targetCurrency,
        float $sourceAmount,
    ): array {
        $providerCode = strtolower($provider->code);

        return match ($providerCode) {
            'nium' => $this->niumQuote($sourceCurrency, $targetCurrency, $sourceAmount),
            default => throw new RuntimeException('Public quote preview is not implemented for this provider.'),
        };
    }

    private function marketReferenceRate(
        array $base,
        string $sourceCurrency,
        string $targetCurrency,
        float $sourceAmount,
        string $message,
    ): array {
        try {
            return [
                ...$base,
                'quote_status' => 'reference',
                'quote' => $this->marketReferenceQuote($sourceCurrency, $targetCurrency, $sourceAmount),
                'message' => $message,
            ];
        } catch (\Throwable $exception) {
            return [
                ...$base,
                'quote_status' => 'unavailable',
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function marketReferenceQuote(
        string $sourceCurrency,
        string $targetCurrency,
        float $sourceAmount,
    ): array {
        $sourceCurrency = strtoupper($sourceCurrency);
        $targetCurrency = strtoupper($targetCurrency);
        $cacheKey = 'public_provider_rates:market_reference:'.$sourceCurrency;
        $ttl = max(60, (int) config('services.public_provider_rates.market_cache_ttl_seconds', 3600));

        $rateData = Cache::remember($cacheKey, now()->addSeconds($ttl), function () use ($sourceCurrency): array {
            $baseUrl = rtrim((string) config('services.public_provider_rates.market_base_url'), '/');
            $response = Http::timeout(10)
                ->acceptJson()
                ->get($baseUrl.'/'.urlencode($sourceCurrency));
            $responseData = $response->json() ?? [];

            if (! $response->successful() || ($responseData['result'] ?? null) !== 'success') {
                throw new RuntimeException($responseData['error-type'] ?? 'Market reference rate lookup failed.');
            }

            return $responseData;
        });

        $rate = $this->nullableFloat(Arr::get($rateData, 'rates.'.$targetCurrency));

        if ($rate === null) {
            throw new RuntimeException("Market reference rate is not available for {$sourceCurrency}/{$targetCurrency}.");
        }

        return [
            ...$this->quotePayload(
                sourceCurrency: $sourceCurrency,
                targetCurrency: $targetCurrency,
                sourceAmount: $sourceAmount,
                targetAmount: $sourceAmount * $rate,
                midRate: $rate,
                netRate: $rate,
                feeAmount: 0,
                expiresAt: $rateData['time_next_update_utc'] ?? now()->addHour()->toISOString(),
            ),
            'source' => [
                'type' => 'market_reference',
                'name' => 'ExchangeRate-API Open Access',
                'url' => 'https://www.exchangerate-api.com/docs/free',
                'provider_url' => $rateData['provider'] ?? 'https://www.exchangerate-api.com',
                'time_last_update_utc' => $rateData['time_last_update_utc'] ?? null,
                'time_next_update_utc' => $rateData['time_next_update_utc'] ?? null,
                'attribution_required' => true,
            ],
        ];
    }

    private function niumQuote(string $sourceCurrency, string $targetCurrency, float $sourceAmount): array
    {
        $response = $this->niumService->post(
            path: $this->niumService->path(
                (string) config('services.nium.quote_endpoint'),
                ['client' => $this->niumService->clientId()],
            ),
            payload: [
                'sourceCurrencyCode' => $sourceCurrency,
                'destinationCurrencyCode' => $targetCurrency,
                'sourceAmount' => $sourceAmount,
                'quoteType' => 'payout',
                'conversionSchedule' => 'immediate',
                'lockPeriod' => '5_mins',
                'executionType' => 'at_conversion_time',
            ],
        );
        $responseData = $this->successfulJson($response, 'Nium quote preview failed.');
        $quote = $this->niumQuotePayload($responseData);

        return $this->quotePayload(
            sourceCurrency: $sourceCurrency,
            targetCurrency: $targetCurrency,
            sourceAmount: $quote['sourceAmount'] ?? $quote['sellAmount'] ?? $sourceAmount,
            targetAmount: $quote['destinationAmount'] ?? $quote['buyAmount'] ?? null,
            midRate: $quote['midRate'] ?? $quote['mid_rate'] ?? $quote['exchangeRate'] ?? null,
            netRate: $quote['fxRate'] ?? $quote['rate'] ?? $quote['exchangeRate'] ?? null,
            feeAmount: $quote['feeAmount'] ?? $quote['fee'] ?? 0,
            expiresAt: $quote['expiresAt'] ?? $quote['quoteExpiry'] ?? now()->addMinutes(15)->toISOString(),
        );
    }

    private function quotePayload(
        string $sourceCurrency,
        string $targetCurrency,
        mixed $sourceAmount,
        mixed $targetAmount,
        mixed $midRate,
        mixed $netRate,
        mixed $feeAmount,
        mixed $expiresAt,
    ): array {
        $resolvedNetRate = $this->nullableFloat($netRate);
        $resolvedTargetAmount = $this->nullableFloat($targetAmount);

        if ($resolvedTargetAmount === null && $resolvedNetRate !== null) {
            $resolvedTargetAmount = $this->nullableFloat($sourceAmount) * $resolvedNetRate;
        }

        return [
            'source_currency' => $sourceCurrency,
            'target_currency' => $targetCurrency,
            'source_amount' => $this->nullableFloat($sourceAmount),
            'target_amount' => $resolvedTargetAmount,
            'mid_rate' => $this->nullableFloat($midRate),
            'net_rate' => $resolvedNetRate,
            'fee_amount' => $this->nullableFloat($feeAmount) ?? 0.0,
            'expires_at' => is_string($expiresAt) ? $expiresAt : now()->addMinutes(15)->toISOString(),
            'quoted_at' => now()->toISOString(),
        ];
    }

    private function successfulJson(Response $response, string $fallbackMessage): array
    {
        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful()) {
            throw new RuntimeException($responseData['message'] ?? $fallbackMessage);
        }

        return $responseData;
    }

    private function niumQuotePayload(array $responseData): array
    {
        $quote = Arr::get($responseData, 'quotes.0')
            ?? Arr::get($responseData, 'data.quotes.0')
            ?? Arr::get($responseData, 'data')
            ?? $responseData;

        return is_array($quote) ? $quote : [];
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function cacheTtlSeconds(): int
    {
        return max(5, min(60, (int) config('services.public_provider_rates.cache_ttl_seconds', 15)));
    }
}
