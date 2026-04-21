<?php

namespace App\Services\Tazapay;

use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\Contracts\QuoteProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TazapayQuoteService implements QuoteProvider
{
    public function __construct(
        private readonly TazapayService $tazapayService,
    ) {
    }

    public function createQuote(IntegrationProvider $provider, User $user, array $payload): FxQuote
    {
        $requestPayload = $this->buildQuotePayload($payload);
        $response = $this->tazapayService->post(
            path: (string) config('services.tazapay.quote_endpoint'),
            payload: $requestPayload,
            user: $user,
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];
        $quote = (array) ($responseData['data'] ?? $responseData);

        if (! $response->successful() || ! filled($quote['id'] ?? null)) {
            throw new RuntimeException($responseData['message'] ?? 'Tazapay quote creation failed.');
        }

        return DB::transaction(fn () => FxQuote::create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'quote_ref' => (string) ($quote['id'] ?? 'QTE-'.Str::upper(Str::random(10))),
            'source_currency' => $payload['source_currency'],
            'target_currency' => $payload['target_currency'],
            'source_amount' => Arr::get($quote, 'holding_info.amount', $payload['source_amount']),
            'target_amount' => Arr::get($quote, 'payout_info.amount', $payload['target_amount'] ?? 0),
            'mid_rate' => Arr::get($quote, 'exchange_rates.holding_to_payout'),
            'net_rate' => Arr::get($quote, 'exchange_rates.holding_to_payout'),
            'fee_amount' => $this->resolveFeeAmount($quote),
            'expires_at' => $quote['valid_until'] ?? now()->addMinutes(15),
            'raw_data' => array_merge($responseData, [
                'request_payload' => $requestPayload,
            ]),
        ]));
    }

    private function buildQuotePayload(array $payload): array
    {
        $tazapay = (array) (($payload['raw_data'] ?? [])['tazapay'] ?? []);

        return array_filter([
            'payout_type' => $tazapay['payout_type'] ?? 'local',
            'payout_info' => [
                'amount' => $payload['target_amount'] ?? $payload['source_amount'],
                'currency' => $payload['target_currency'],
            ],
            'holding_info' => array_filter([
                'amount' => Arr::get($tazapay, 'holding_info.amount', $payload['source_amount']),
                'currency' => Arr::get($tazapay, 'holding_info.currency', $payload['source_currency']),
            ], static fn ($value) => $value !== null && $value !== ''),
            'destination_info' => array_filter([
                'amount' => Arr::get($tazapay, 'destination_info.amount', $payload['target_amount']),
                'currency' => Arr::get($tazapay, 'destination_info.currency', $payload['target_currency']),
            ], static fn ($value) => $value !== null && $value !== ''),
            'local' => $tazapay['local'] ?? null,
            'local_payment_network' => $tazapay['local_payment_network'] ?? null,
            'wallet' => $tazapay['wallet'] ?? null,
            'swift' => $tazapay['swift'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function resolveFeeAmount(array $quote): float
    {
        $fixed = (float) Arr::get($quote, 'fee_info.fixed.in_holding_currency', 0);
        $variable = (float) Arr::get($quote, 'fee_info.variable.in_holding_currency', 0);

        return $fixed + $variable;
    }
}
