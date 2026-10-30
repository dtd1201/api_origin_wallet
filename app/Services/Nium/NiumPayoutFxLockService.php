<?php

namespace App\Services\Nium;

use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class NiumPayoutFxLockService
{
    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumProviderAccountStateService $accountState,
    ) {}

    public function createLock(
        IntegrationProvider $provider,
        User $user,
        string $sourceCurrency,
        string $targetCurrency,
        string|float|int $sourceAmount,
    ): FxQuote {
        if (! (bool) config('services.nium.payout_fx_enabled', false)) {
            throw new RuntimeException('Nium payout FX is not enabled.');
        }

        $sourceCurrency = strtoupper(trim($sourceCurrency));
        $targetCurrency = strtoupper(trim($targetCurrency));

        if ($targetCurrency === 'SGD') {
            NiumSgdLocalPayoutGate::assertSubmissionAllowed();
        }

        if (strlen($sourceCurrency) !== 3
            || strlen($targetCurrency) !== 3
            || $sourceCurrency === $targetCurrency
            || NiumPayoutFxLockContract::decimal($sourceAmount) === '0'
            || strlen(explode('.', NiumPayoutFxLockContract::decimal($sourceAmount))[1] ?? '') > 8) {
            throw new RuntimeException('Nium payout FX lock requires a valid cross-currency pair and positive amount.');
        }

        return $this->requestAndStoreLock($provider, $user, $sourceCurrency, $targetCurrency, (string) $sourceAmount);
    }

    /** Separate explicit preparation workflow; never used by listing or ordinary submit. */
    public function createSgdPreparationLock(Transfer $transfer, string $requestId): FxQuote
    {
        if (! (bool) config('services.nium.payout_fx_enabled', false)) {
            throw new RuntimeException('Nium payout FX is not enabled.');
        }
        app(NiumSgdLocalCugScope::class)->assertTransfer($transfer);
        $workflow = $transfer->raw_data['sgd_local_workflow'] ?? [];
        if (! Str::isUuid($requestId) || $transfer->status !== 'draft' || ($workflow['release'] ?? null) !== NiumSgdLocalCugScope::RELEASE
            || ($workflow['state'] ?? null) !== 'locking' || ($workflow['lock_request_id'] ?? null) !== $requestId) {
            throw new RuntimeException('Nium SGD lock requires a claimed explicit preparation request.');
        }
        app(NiumSgdLocalPayoutWorkflow::class)->assertIntent($transfer);
        app(NiumSgdLocalPayoutGate::class)->assertCandidate($transfer->user, $transfer->provider, $transfer->beneficiary, 'SGD');

        return $this->requestAndStoreLock($transfer->provider, $transfer->user, $transfer->source_currency,
            $transfer->target_currency, NiumSgdLocalCugScope::principal($transfer->source_amount), $requestId);
    }

    private function requestAndStoreLock(
        IntegrationProvider $provider, User $user, string $sourceCurrency, string $targetCurrency,
        string $sourceAmount, ?string $requestId = null,
    ): FxQuote {
        $context = $this->context($provider, $user);
        $path = $this->niumService->path(
            (string) config('services.nium.payout_fx_lock_endpoint'),
            [
                'client' => $context['client'],
                'customer' => $context['customer'],
                'wallet' => $context['wallet'],
            ],
        );
        $query = [
            'sourceCurrency' => $sourceCurrency,
            'destinationCurrency' => $targetCurrency,
        ];
        $response = $requestId === null
            ? $this->niumService->get(path: $path, query: $query, user: $user, operation: 'create_payout_fx_lock')
            : $this->niumService->getWithRequestId($path, $query, $user, $requestId, 'create_payout_fx_lock');

        $responseData = $response->json() ?? ['raw' => $response->body()];

        if (! $response->successful() || ! is_array($responseData)) {
            throw new RuntimeException('Nium payout FX lock failed.');
        }
        $contract = NiumPayoutFxLockContract::parse($responseData, $sourceCurrency, $targetCurrency, (string) $sourceAmount);

        return DB::transaction(fn () => FxQuote::create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'quote_ref' => $contract['audit_id'],
            'source_currency' => $sourceCurrency,
            'target_currency' => $targetCurrency,
            'source_amount' => $sourceAmount,
            // Historical database estimate only; never present as provider credit.
            'target_amount' => (string) BigDecimal::of($contract['source_amount'])
                ->multipliedBy($contract['rate'])->toScale(8, RoundingMode::DOWN),
            'mid_rate' => $responseData['ecb_fx_rate'] ?? $responseData['ecbFxRate'] ?? null,
            'net_rate' => $contract['rate'],
            'fee_amount' => 0,
            'expires_at' => $contract['expires_at'],
            'raw_data' => array_filter([
                'provider_fx_type' => 'payout_fx_lock',
                'audit_id' => $contract['audit_id'],
                'payout_fx_lock_contract' => $contract,
                'provider_context' => $context,
                'lock_request_uuid' => $requestId,
                'fx_hold_id' => $responseData['fx_hold_id'] ?? $responseData['fxHoldId'] ?? null,
                'provider_request_id' => $responseData['requestId'] ?? $responseData['request_id'] ?? null,
                'provider_status' => $responseData['status'] ?? null,
                // fee_amount is a legacy non-null storage column, not fee evidence.
                'provider_fee_status' => 'unavailable',
            ], static fn ($value) => $value !== null && $value !== ''),
        ]));
    }

    /** Called inside the submission transaction; never release a consumed hold on failure. */
    public function consumeForSubmission(Transfer $transfer, string $operationKey): void
    {
        if (DB::transactionLevel() < 1 || $transfer->fx_quote_id === null) {
            throw new RuntimeException('Nium FX consumption requires the submission transaction and an attached lock.');
        }
        app(NiumSgdLocalPayoutAuthorization::class)->assertApproved($transfer);

        $quote = FxQuote::query()->findOrFail($transfer->fx_quote_id);
        $sameAudit = FxQuote::query()->where('provider_id', $quote->provider_id)
            ->where('user_id', $quote->user_id)->where('quote_ref', $quote->quote_ref)
            ->orderBy('id')->lockForUpdate()->get();
        $quote = $sameAudit->firstWhere('id', $transfer->fx_quote_id);
        if ($quote === null) {
            throw new RuntimeException('Nium payout FX hold changed before consumption.');
        }
        NiumPayoutFxLockContract::assertMatchesTransfer($quote, $transfer, $this->context($transfer->provider, $transfer->user));
        foreach ($sameAudit as $other) {
            if (isset($other->raw_data['consumed_at'])) {
                throw new RuntimeException('Nium payout FX audit has already been consumed.');
            }
        }
        if (Transfer::query()->whereIn('fx_quote_id', $sameAudit->modelKeys())
            ->where('id', '!=', $transfer->id)->exists()) {
            throw new RuntimeException('Nium payout FX audit is already bound to another transfer.');
        }

        $quote->update(['raw_data' => array_merge($quote->raw_data, [
            'consumed_at' => now()->toISOString(), 'consumed_transfer_id' => $transfer->id,
            'consumed_operation_key' => $operationKey,
        ])]);
    }

    /** Local account evidence only; this method never requests an FX lock. */
    public function context(IntegrationProvider $provider, User $user): array
    {
        $account = $this->accountState->assertEligible($user);
        if (strtolower((string) $provider->code) !== 'nium'
            || $account->provider_id !== $provider->id || $account->reconciliation_status !== 'reconciled') {
            throw new RuntimeException('Nium payout FX requires the matching reconciled provider account.');
        }

        return [
            'client' => $this->niumService->clientId(), 'customer' => (string) $account->external_customer_id,
            'wallet' => (string) $account->external_account_id,
            'base_url' => (string) config('services.nium.base_url'),
        ];
    }
}
