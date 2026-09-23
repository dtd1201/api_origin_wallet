<?php

namespace App\Services\Nium;

use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Services\Integrations\Contracts\QuoteProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NiumQuoteService implements QuoteProvider
{
    public function __construct(
        private readonly NiumService $niumService,
    ) {}

    public function createQuote(IntegrationProvider $provider, User $user, array $payload): FxQuote
    {
        if (! (bool) config('services.nium.payout_fx_enabled', false)) {
            throw new RuntimeException('Nium payout FX is not enabled.');
        }

        $sourceCurrency = strtoupper(trim((string) ($payload['source_currency'] ?? '')));
        $targetCurrency = strtoupper(trim((string) ($payload['target_currency'] ?? '')));
        $sourceAmount = (float) ($payload['source_amount'] ?? 0);

        if (strlen($sourceCurrency) !== 3
            || strlen($targetCurrency) !== 3
            || $sourceCurrency === $targetCurrency
            || $sourceAmount <= 0) {
            throw new RuntimeException('Nium FX quote requires a valid cross-currency pair and positive source amount.');
        }

        $requestPayload = [
            'sourceCurrencyCode' => $sourceCurrency,
            'destinationCurrencyCode' => $targetCurrency,
            'sourceAmount' => $sourceAmount,
            'customerHashId' => $this->niumService->customerId($user),
            'quoteType' => 'payout',
            'conversionSchedule' => 'immediate',
            'executionType' => 'manual',
            'lockPeriod' => '5_mins',
            'quoteIntent' => 'EXECUTABLE',
        ];

        $response = $this->niumService->post(
            path: $this->niumService->path(
                (string) config('services.nium.quote_endpoint'),
                [
                    'client' => $this->niumService->clientId(),
                ],
            ),
            payload: $requestPayload,
            user: $user,
            operation: 'create_fx_quote',
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];

        $quoteId = $responseData['id'] ?? null;
        $expiresAt = $responseData['expiryTime'] ?? null;

        if (! $response->successful() || ! filled($quoteId) || ! filled($expiresAt)) {
            throw new RuntimeException($responseData['message'] ?? 'Nium executable FX quote failed.');
        }

        $midRate = $responseData['exchangeRate'] ?? null;
        $netRate = $responseData['netExchangeRate'] ?? $midRate;

        $resolvedSourceAmount = is_numeric($responseData['sourceAmount'] ?? null)
            ? (float) $responseData['sourceAmount']
            : $sourceAmount;

        $targetAmount = is_numeric($responseData['destinationAmount'] ?? null)
            ? (float) $responseData['destinationAmount']
            : (is_numeric($netRate) ? $resolvedSourceAmount * (float) $netRate : 0);

        return DB::transaction(fn () => FxQuote::create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'quote_ref' => (string) $quoteId,
            'source_currency' => $sourceCurrency,
            'target_currency' => $targetCurrency,
            'source_amount' => $resolvedSourceAmount,
            'target_amount' => $targetAmount,
            'mid_rate' => is_numeric($midRate) ? $midRate : null,
            'net_rate' => is_numeric($netRate) ? $netRate : null,
            'fee_amount' => 0,
            'expires_at' => $expiresAt,
            'raw_data' => array_filter([
                'provider_fx_type' => 'modern_quote',
                'provider_quote_id' => (string) $quoteId,
                'quote_type' => $responseData['quoteType'] ?? 'payout',
                'quote_intent' => $responseData['quoteIntent'] ?? 'EXECUTABLE',
                'conversion_schedule' => $responseData['conversionSchedule'] ?? 'immediate',
                'execution_type' => $responseData['executionType'] ?? 'manual',
                'lock_period' => $responseData['lockPeriod'] ?? '5_mins',
                'markup_rate' => $responseData['markupRate'] ?? null,
                'client_markup_rate' => $responseData['clientMarkupRate'] ?? null,
                'provider_request_id' => $responseData['requestId'] ?? $responseData['request_id'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''),
        ]));
    }
}
