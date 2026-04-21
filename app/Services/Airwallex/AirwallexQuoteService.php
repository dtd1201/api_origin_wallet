<?php

namespace App\Services\Airwallex;

use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\Contracts\QuoteProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AirwallexQuoteService implements QuoteProvider
{
    public function __construct(
        private readonly AirwallexService $airwallexService,
    ) {
    }

    public function createQuote(IntegrationProvider $provider, User $user, array $payload): FxQuote
    {
        $requestPayload = $this->buildQuotePayload($payload);

        $response = $this->airwallexService->post(
            path: (string) config('services.airwallex.quote_endpoint'),
            payload: $requestPayload,
            user: $user,
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful()) {
            throw new RuntimeException($responseData['message'] ?? 'Airwallex quote creation failed.');
        }

        return DB::transaction(fn () => FxQuote::create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'quote_ref' => (string) ($responseData['quote_id'] ?? $responseData['id'] ?? 'QTE-'.Str::upper(Str::random(10))),
            'source_currency' => $payload['source_currency'],
            'target_currency' => $payload['target_currency'],
            'source_amount' => $payload['source_amount'],
            'target_amount' => $responseData['buy_amount']
                ?? $responseData['target_amount']
                ?? $payload['target_amount']
                ?? 0,
            'mid_rate' => $responseData['mid_rate']
                ?? Arr::get($responseData, 'rate.mid')
                ?? Arr::get($responseData, 'client_rate.mid'),
            'net_rate' => $responseData['client_rate']
                ?? $responseData['rate']
                ?? Arr::get($responseData, 'rate.client_rate')
                ?? Arr::get($responseData, 'rate_details.client_rate'),
            'fee_amount' => $responseData['fee_amount']
                ?? Arr::get($responseData, 'fees.total')
                ?? 0,
            'expires_at' => $responseData['expires_at']
                ?? $responseData['valid_to']
                ?? $responseData['quote_expiry_time']
                ?? now()->addMinutes(15),
            'raw_data' => array_merge($responseData, [
                'request_payload' => $requestPayload,
            ]),
        ]));
    }

    private function buildQuotePayload(array $payload): array
    {
        $requestPayload = array_filter([
            'buy_currency' => $payload['target_currency'],
            'sell_currency' => $payload['source_currency'],
            'validity' => config('services.airwallex.quote_validity'),
        ], static fn ($value) => $value !== null && $value !== '');

        if (isset($payload['target_amount']) && $payload['target_amount'] !== null) {
            $requestPayload['buy_amount'] = (string) $payload['target_amount'];
        } else {
            $requestPayload['sell_amount'] = (string) $payload['source_amount'];
        }

        return $requestPayload;
    }
}
