<?php

namespace App\Services\Wise;

use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\Contracts\QuoteProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class WiseQuoteService implements QuoteProvider
{
    use WiseDataFormatter;

    public function __construct(
        private readonly WiseService $wiseService,
    ) {
    }

    public function createQuote(IntegrationProvider $provider, User $user, array $payload): FxQuote
    {
        $requestPayload = $this->buildQuotePayload($payload, $user);
        $profileId = $this->wiseService->profileId($user);
        $response = $this->wiseService->post(
            path: $this->wiseService->path(
                (string) config('services.wise.quote_endpoint'),
                ['profile' => $profileId],
            ),
            payload: $requestPayload,
            user: $user,
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful() || ! filled($responseData['id'] ?? null)) {
            throw new RuntimeException($this->transferFailureMessage($responseData, 'Wise quote creation failed.'));
        }

        return DB::transaction(fn () => FxQuote::create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'quote_ref' => (string) ($responseData['id'] ?? 'QTE-'.Str::upper(Str::random(10))),
            'source_currency' => $payload['source_currency'],
            'target_currency' => $payload['target_currency'],
            'source_amount' => $responseData['sourceAmount'] ?? $payload['source_amount'],
            'target_amount' => $responseData['targetAmount'] ?? $payload['target_amount'] ?? 0,
            'mid_rate' => $responseData['rate'] ?? null,
            'net_rate' => $responseData['rate'] ?? null,
            'fee_amount' => $this->quoteFee($responseData),
            'expires_at' => $responseData['expirationTime']
                ?? $responseData['rateExpirationTime']
                ?? now()->addMinutes(30),
            'raw_data' => array_merge($responseData, [
                'request_payload' => $requestPayload,
            ]),
        ]));
    }

    private function buildQuotePayload(array $payload, User $user): array
    {
        $wise = (array) (($payload['raw_data'] ?? [])['wise'] ?? []);
        $requestPayload = array_filter([
            'sourceCurrency' => $payload['source_currency'],
            'targetCurrency' => $payload['target_currency'],
            'sourceAmount' => Arr::get($wise, 'amount_type') === 'target' ? null : (float) $payload['source_amount'],
            'targetAmount' => Arr::get($wise, 'amount_type') === 'target' && isset($payload['target_amount'])
                ? (float) $payload['target_amount']
                : null,
            'targetAccount' => $wise['targetAccount'] ?? $wise['target_account'] ?? null,
            'payOut' => $wise['payOut'] ?? $wise['pay_out'] ?? null,
            'preferredPayIn' => $wise['preferredPayIn'] ?? $wise['preferred_pay_in'] ?? null,
            'paymentMetadata' => $wise['paymentMetadata'] ?? $wise['payment_metadata'] ?? null,
            'pricingConfiguration' => $wise['pricingConfiguration'] ?? $wise['pricing_configuration'] ?? null,
            'profile' => $this->wiseService->profileId($user),
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);

        if (isset($wise['request']) && is_array($wise['request'])) {
            $requestPayload = array_replace_recursive($requestPayload, $wise['request']);
        }

        if (! isset($requestPayload['sourceAmount']) && ! isset($requestPayload['targetAmount'])) {
            throw new RuntimeException('Wise quote requires either sourceAmount or targetAmount.');
        }

        return $requestPayload;
    }

    private function quoteFee(array $quote): float
    {
        $matchingPaymentOption = collect($quote['paymentOptions'] ?? [])
            ->first(function ($item) use ($quote): bool {
                return ($item['payOut'] ?? null) === ($quote['payOut'] ?? null);
            });

        $fee = Arr::get($matchingPaymentOption, 'fee.total')
            ?? Arr::get($matchingPaymentOption, 'price.total.value.amount')
            ?? 0;

        return (float) $fee;
    }
}
