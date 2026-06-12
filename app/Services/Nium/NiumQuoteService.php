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
    ) {}

    public function createQuote(IntegrationProvider $provider, User $user, array $payload): FxQuote
    {
        $requestPayload = $this->buildQuotePayload($user, $payload);
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

    private function buildQuotePayload(User $user, array $payload): array
    {
        $nium = (array) (($payload['raw_data'] ?? [])['nium'] ?? []);

        $request = array_filter([
            'sourceCurrencyCode' => $payload['source_currency'],
            'destinationCurrencyCode' => $payload['target_currency'],
            'sourceAmount' => isset($payload['source_amount']) ? (float) $payload['source_amount'] : null,
            'destinationAmount' => isset($payload['target_amount']) ? (float) $payload['target_amount'] : null,
            'quoteType' => $nium['quoteType'] ?? $nium['quote_type'] ?? 'payout',
            'conversionSchedule' => $nium['conversionSchedule'] ?? $nium['conversion_schedule'] ?? 'immediate',
            'lockPeriod' => $nium['lockPeriod'] ?? $nium['lock_period'] ?? '5_mins',
            'executionType' => $nium['executionType'] ?? $nium['execution_type'] ?? 'at_conversion_time',
            'customerHashId' => $nium['customerHashId'] ?? $nium['customer_hash_id'] ?? $this->optionalCustomerId($user),
            'quoteIntent' => $nium['quoteIntent'] ?? $nium['quote_intent'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        return isset($nium['request']) && is_array($nium['request'])
            ? array_replace_recursive($request, $nium['request'])
            : $request;
    }

    private function optionalCustomerId(User $user): ?string
    {
        try {
            return $this->niumService->customerId($user);
        } catch (RuntimeException) {
            return null;
        }
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
