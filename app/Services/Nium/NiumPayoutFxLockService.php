<?php

namespace App\Services\Nium;

use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NiumPayoutFxLockService
{
    public function __construct(
        private readonly NiumService $niumService,
    ) {}

    public function createLock(
        IntegrationProvider $provider,
        User $user,
        string $sourceCurrency,
        string $targetCurrency,
        float $sourceAmount,
    ): FxQuote {
        if (! (bool) config('services.nium.payout_fx_enabled', false)) {
            throw new RuntimeException('Nium payout FX is not enabled.');
        }

        $sourceCurrency = strtoupper(trim($sourceCurrency));
        $targetCurrency = strtoupper(trim($targetCurrency));

        if (strlen($sourceCurrency) !== 3
            || strlen($targetCurrency) !== 3
            || $sourceCurrency === $targetCurrency
            || $sourceAmount <= 0) {
            throw new RuntimeException('Nium payout FX lock requires a valid cross-currency pair and positive amount.');
        }

        $response = $this->niumService->get(
            path: $this->niumService->path(
                (string) config('services.nium.payout_fx_lock_endpoint'),
                [
                    'client' => $this->niumService->clientId(),
                    'customer' => $this->niumService->customerId($user),
                    'wallet' => $this->niumService->walletId($user),
                ],
            ),
            query: [
                'sourceCurrency' => $sourceCurrency,
                'destinationCurrency' => $targetCurrency,
            ],
            user: $user,
            operation: 'create_payout_fx_lock',
        );

        $responseData = $response->json() ?? ['raw' => $response->body()];

        $auditId = $responseData['audit_id'] ?? $responseData['auditId'] ?? null;
        $expiresAt = $responseData['hold_expiry_at'] ?? $responseData['holdExpiryAt'] ?? null;
        $rate = $responseData['fx_rate'] ?? $responseData['fxRate'] ?? null;

        if (! $response->successful()
            || ! is_numeric($auditId)
            || ! filled($expiresAt)
            || ! is_numeric($rate)) {
            throw new RuntimeException($responseData['message'] ?? 'Nium payout FX lock failed.');
        }

        return DB::transaction(fn () => FxQuote::create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'quote_ref' => (string) $auditId,
            'source_currency' => $sourceCurrency,
            'target_currency' => $targetCurrency,
            'source_amount' => $sourceAmount,
            'target_amount' => $sourceAmount * (float) $rate,
            'mid_rate' => $responseData['ecb_fx_rate'] ?? $responseData['ecbFxRate'] ?? null,
            'net_rate' => $rate,
            'fee_amount' => 0,
            'expires_at' => $expiresAt,
            'raw_data' => array_filter([
                'provider_fx_type' => 'payout_fx_lock',
                'audit_id' => (string) $auditId,
                'fx_hold_id' => $responseData['fx_hold_id'] ?? $responseData['fxHoldId'] ?? null,
                'provider_request_id' => $responseData['requestId'] ?? $responseData['request_id'] ?? null,
                'provider_status' => $responseData['status'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''),
        ]));
    }
}
