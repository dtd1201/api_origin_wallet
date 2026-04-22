<?php

namespace App\Services\Nium;

use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\Contracts\QuoteProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class NiumQuoteService implements QuoteProvider
{
    public function __construct(
        private readonly NiumService $niumService,
    ) {
    }

    public function createQuote(IntegrationProvider $provider, User $user, array $payload): FxQuote
    {
        $requestPayload = $this->buildQuotePayload($payload);
        $response = $this->niumService->post(
            path: $this->niumService->path(
                (string) config('services.nium.quote_endpoint'),
                ['client' => $this->niumService->clientId()],
            ),
            payload: $requestPayload,
            user: $user,
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];
        $quote = $this->quotePayload($responseData);

        if (! $response->successful() || ! filled($quote['id'] ?? $quote['quoteId'] ?? null)) {
            throw new RuntimeException($responseData['message'] ?? 'Nium quote creation failed.');
        }

        return DB::transaction(fn () => FxQuote::create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'quote_ref' => (string) ($quote['id'] ?? $quote['quoteId'] ?? 'QTE-'.Str::upper(Str::random(10))),
            'source_currency' => $payload['source_currency'],
            'target_currency' => $payload['target_currency'],
            'source_amount' => $quote['sourceAmount'] ?? $quote['sellAmount'] ?? $payload['source_amount'],
            'target_amount' => $quote['destinationAmount'] ?? $quote['buyAmount'] ?? $payload['target_amount'] ?? 0,
            'mid_rate' => $quote['midRate'] ?? $quote['mid_rate'] ?? $quote['exchangeRate'] ?? null,
            'net_rate' => $quote['fxRate'] ?? $quote['rate'] ?? $quote['exchangeRate'] ?? null,
            'fee_amount' => $quote['feeAmount'] ?? $quote['fee'] ?? 0,
            'expires_at' => $quote['expiresAt'] ?? $quote['quoteExpiry'] ?? now()->addMinutes(15),
            'raw_data' => array_merge($responseData, [
                'request_payload' => $requestPayload,
            ]),
        ]));
    }

    private function buildQuotePayload(array $payload): array
    {
        $nium = (array) (($payload['raw_data'] ?? [])['nium'] ?? []);

        return array_filter([
            'sourceCurrency' => $payload['source_currency'],
            'destinationCurrency' => $payload['target_currency'],
            'sourceAmount' => (string) $payload['source_amount'],
            'destinationAmount' => isset($payload['target_amount']) ? (string) $payload['target_amount'] : null,
            'lockPeriod' => $nium['lock_period'] ?? null,
            'conversionSchedule' => $nium['conversion_schedule'] ?? null,
            'request' => $nium['request'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function quotePayload(array $responseData): array
    {
        $quote = Arr::get($responseData, 'quotes.0')
            ?? Arr::get($responseData, 'data.quotes.0')
            ?? Arr::get($responseData, 'data')
            ?? $responseData;

        return is_array($quote) ? $quote : [];
    }
}
