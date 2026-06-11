<?php

namespace App\Services\Quotes;

use App\Models\IntegrationProvider;
use App\Services\Airwallex\AirwallexService;
use App\Services\Integrations\ProviderHttpClient;
use App\Services\Nium\NiumService;
use App\Services\Tazapay\TazapayService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PublicProviderRateService
{
    public function __construct(
        private readonly AirwallexService $airwallexService,
        private readonly NiumService $niumService,
        private readonly TazapayService $tazapayService,
        private readonly ManagedExchangeRateService $managedExchangeRateService,
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
            'airwallex' => $this->airwallexQuote($sourceCurrency, $targetCurrency, $sourceAmount),
            'currenxie' => $this->currenxieQuote($provider, $sourceCurrency, $targetCurrency, $sourceAmount),
            'nium' => $this->niumQuote($sourceCurrency, $targetCurrency, $sourceAmount),
            'tazapay' => $this->tazapayQuote($sourceCurrency, $targetCurrency, $sourceAmount),
            'wise' => $this->wiseQuote($provider, $sourceCurrency, $targetCurrency, $sourceAmount),
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

    private function airwallexQuote(string $sourceCurrency, string $targetCurrency, float $sourceAmount): array
    {
        $payload = [
            'buy_currency' => $targetCurrency,
            'sell_currency' => $sourceCurrency,
            'sell_amount' => (string) $sourceAmount,
            'validity' => config('services.airwallex.quote_validity'),
        ];

        $response = $this->airwallexService->post(
            path: (string) config('services.airwallex.quote_endpoint'),
            payload: $payload,
        );
        $responseData = $this->successfulJson($response, 'Airwallex quote preview failed.');

        return $this->quotePayload(
            sourceCurrency: $sourceCurrency,
            targetCurrency: $targetCurrency,
            sourceAmount: $sourceAmount,
            targetAmount: $responseData['buy_amount'] ?? $responseData['target_amount'] ?? null,
            midRate: $responseData['mid_rate']
                ?? Arr::get($responseData, 'rate.mid')
                ?? Arr::get($responseData, 'client_rate.mid'),
            netRate: $responseData['client_rate']
                ?? $responseData['rate']
                ?? Arr::get($responseData, 'rate.client_rate')
                ?? Arr::get($responseData, 'rate_details.client_rate'),
            feeAmount: $responseData['fee_amount'] ?? Arr::get($responseData, 'fees.total') ?? 0,
            expiresAt: $responseData['expires_at']
                ?? $responseData['valid_to']
                ?? $responseData['quote_expiry_time']
                ?? now()->addMinutes(15)->toISOString(),
        );
    }

    private function currenxieQuote(
        IntegrationProvider $provider,
        string $sourceCurrency,
        string $targetCurrency,
        float $sourceAmount,
    ): array {
        $client = new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'currenxie',
            headers: $this->currenxieRequestHeaders(),
        );

        $response = $client->post(
            path: (string) config('services.currenxie.quote_endpoint'),
            payload: [
                'source_currency' => $sourceCurrency,
                'target_currency' => $targetCurrency,
                'source_amount' => $sourceAmount,
            ],
        );
        $responseData = $this->successfulJson($response, 'Currenxie quote preview failed.');

        return $this->quotePayload(
            sourceCurrency: $sourceCurrency,
            targetCurrency: $targetCurrency,
            sourceAmount: $sourceAmount,
            targetAmount: $responseData['target_amount'] ?? null,
            midRate: $responseData['mid_rate'] ?? null,
            netRate: $responseData['net_rate'] ?? $responseData['fx_rate'] ?? null,
            feeAmount: $responseData['fee_amount'] ?? 0,
            expiresAt: $responseData['expires_at'] ?? now()->addMinutes(15)->toISOString(),
        );
    }

    private function niumQuote(string $sourceCurrency, string $targetCurrency, float $sourceAmount): array
    {
        $response = $this->niumService->post(
            path: $this->niumService->path(
                (string) config('services.nium.quote_endpoint'),
                ['client' => $this->niumService->clientId()],
            ),
            payload: [
                'sourceCurrency' => $sourceCurrency,
                'destinationCurrency' => $targetCurrency,
                'sourceAmount' => (string) $sourceAmount,
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

    private function tazapayQuote(string $sourceCurrency, string $targetCurrency, float $sourceAmount): array
    {
        $response = $this->tazapayService->post(
            path: (string) config('services.tazapay.quote_endpoint'),
            payload: [
                'payout_type' => 'local',
                'payout_info' => [
                    'amount' => $sourceAmount,
                    'currency' => $targetCurrency,
                ],
                'holding_info' => [
                    'amount' => $sourceAmount,
                    'currency' => $sourceCurrency,
                ],
            ],
        );
        $responseData = $this->successfulJson($response, 'Tazapay quote preview failed.');
        $quote = (array) ($responseData['data'] ?? $responseData);

        return $this->quotePayload(
            sourceCurrency: $sourceCurrency,
            targetCurrency: $targetCurrency,
            sourceAmount: Arr::get($quote, 'holding_info.amount', $sourceAmount),
            targetAmount: Arr::get($quote, 'payout_info.amount'),
            midRate: Arr::get($quote, 'exchange_rates.holding_to_payout'),
            netRate: Arr::get($quote, 'exchange_rates.holding_to_payout'),
            feeAmount: $this->tazapayFeeAmount($quote),
            expiresAt: $quote['valid_until'] ?? now()->addMinutes(15)->toISOString(),
        );
    }

    private function wiseQuote(
        IntegrationProvider $provider,
        string $sourceCurrency,
        string $targetCurrency,
        float $sourceAmount,
    ): array {
        $profileId = (string) config('services.wise.public_profile_id', '');

        if ($profileId === '') {
            throw new RuntimeException('Wise public profile id is not configured.');
        }

        $client = new ProviderHttpClient(
            provider: $provider,
            serviceConfigKey: 'wise',
        );

        $response = $client->post(
            path: str_replace(
                '{profile}',
                urlencode($profileId),
                (string) config('services.wise.quote_endpoint'),
            ),
            payload: [
                'sourceCurrency' => $sourceCurrency,
                'targetCurrency' => $targetCurrency,
                'sourceAmount' => $sourceAmount,
                'profile' => $profileId,
            ],
        );
        $responseData = $this->successfulJson($response, 'Wise quote preview failed.');

        return $this->quotePayload(
            sourceCurrency: $sourceCurrency,
            targetCurrency: $targetCurrency,
            sourceAmount: $responseData['sourceAmount'] ?? $sourceAmount,
            targetAmount: $responseData['targetAmount'] ?? null,
            midRate: $responseData['rate'] ?? null,
            netRate: $responseData['rate'] ?? null,
            feeAmount: $this->wiseQuoteFee($responseData),
            expiresAt: $responseData['expirationTime']
                ?? $responseData['rateExpirationTime']
                ?? now()->addMinutes(30)->toISOString(),
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

    private function currenxieRequestHeaders(): array
    {
        if (strtolower((string) config('services.currenxie.auth.mode', 'static_headers')) !== 'static_headers') {
            return [];
        }

        return [
            'X-API-KEY' => (string) config('services.currenxie.api_key'),
            'X-API-SECRET' => (string) config('services.currenxie.api_secret'),
        ];
    }

    private function niumQuotePayload(array $responseData): array
    {
        $quote = Arr::get($responseData, 'quotes.0')
            ?? Arr::get($responseData, 'data.quotes.0')
            ?? Arr::get($responseData, 'data')
            ?? $responseData;

        return is_array($quote) ? $quote : [];
    }

    private function tazapayFeeAmount(array $quote): float
    {
        $fixed = (float) Arr::get($quote, 'fee_info.fixed.in_holding_currency', 0);
        $variable = (float) Arr::get($quote, 'fee_info.variable.in_holding_currency', 0);

        return $fixed + $variable;
    }

    private function wiseQuoteFee(array $quote): float
    {
        $matchingPaymentOption = collect($quote['paymentOptions'] ?? [])
            ->first(function ($item) use ($quote): bool {
                return ($item['payOut'] ?? null) === ($quote['payOut'] ?? null);
            });

        return (float) (
            Arr::get($matchingPaymentOption, 'fee.total')
            ?? Arr::get($matchingPaymentOption, 'price.total.value.amount')
            ?? 0
        );
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
