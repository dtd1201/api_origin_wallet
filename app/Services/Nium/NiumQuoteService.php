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
        $requestPayload = [
            'sourceCurrency' => strtoupper((string) $payload['source_currency']),
            'destinationCurrency' => strtoupper((string) $payload['target_currency']),
        ];
        $response = $this->niumService->get(
            path: $this->niumService->path(
                (string) config('services.nium.quote_endpoint'),
                [
                    'client' => $this->niumService->clientId(),
                    'customer' => $this->niumService->customerId($user),
                    'wallet' => $this->niumService->walletId($user),
                ],
            ),
            query: $requestPayload,
            user: $user,
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];
        $auditId = $responseData['audit_id'] ?? $responseData['auditId'] ?? null;

        if (! $response->successful() || ! is_numeric($auditId) || ! filled($responseData['hold_expiry_at'] ?? $responseData['holdExpiryAt'] ?? null)) {
            throw new RuntimeException($responseData['message'] ?? 'Nium exchange-rate lock failed.');
        }

        $rate = $responseData['fx_rate'] ?? $responseData['fxRate'] ?? null;

        return DB::transaction(fn () => FxQuote::create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'quote_ref' => (string) $auditId,
            'source_currency' => $payload['source_currency'],
            'target_currency' => $payload['target_currency'],
            'source_amount' => $payload['source_amount'],
            'target_amount' => is_numeric($rate) ? (float) $payload['source_amount'] * (float) $rate : 0,
            'mid_rate' => $responseData['ecb_fx_rate'] ?? $responseData['ecbFxRate'] ?? null,
            'net_rate' => $rate,
            'fee_amount' => 0,
            'expires_at' => $responseData['hold_expiry_at'] ?? $responseData['holdExpiryAt'],
            'raw_data' => array_filter([
                'provider_fx_type' => 'lock_and_hold',
                'audit_id' => (string) $auditId,
                'fx_hold_id' => $responseData['fx_hold_id'] ?? $responseData['fxHoldId'] ?? null,
                'provider_request_id' => $responseData['requestId'] ?? $responseData['request_id'] ?? null,
                'provider_status' => $responseData['status'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''),
        ]));
    }
}
