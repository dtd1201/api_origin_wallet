<?php

namespace App\Services\Nium;

use App\Models\FxQuote;
use App\Models\IntegrationProvider;
use App\Models\User;
use App\Models\Transfer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
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

        if (strlen($sourceCurrency) !== 3
            || strlen($targetCurrency) !== 3
            || $sourceCurrency === $targetCurrency
            || NiumPayoutFxLockContract::decimal($sourceAmount) === '0'
            || strlen(explode('.', NiumPayoutFxLockContract::decimal($sourceAmount))[1] ?? '') > 8) {
            throw new RuntimeException('Nium payout FX lock requires a valid cross-currency pair and positive amount.');
        }

        if ($targetCurrency === 'SGD') {
            throw new RuntimeException('SGD quotes require the saved standard Review workflow.');
        }
        $context = $this->context($provider, $user);

        $response = $this->niumService->get(
            path: $this->niumService->path(
                (string) config('services.nium.payout_fx_lock_endpoint'),
                [
                    'client' => $context['client'],
                    'customer' => $context['customer'],
                    'wallet' => $context['wallet'],
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
            'target_amount' => (float) $sourceAmount * (float) $contract['rate'],
            'mid_rate' => $responseData['ecb_fx_rate'] ?? $responseData['ecbFxRate'] ?? null,
            'net_rate' => $contract['rate'],
            'fee_amount' => 0,
            'expires_at' => $contract['expires_at'],
            'raw_data' => array_filter([
                'provider_fx_type' => 'payout_fx_lock',
                'audit_id' => $contract['audit_id'],
                'payout_fx_lock_contract' => $contract,
                'provider_context' => $context,
                'fx_hold_id' => $responseData['fx_hold_id'] ?? $responseData['fxHoldId'] ?? null,
                'provider_request_id' => $responseData['requestId'] ?? $responseData['request_id'] ?? null,
                'provider_status' => $responseData['status'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''),
        ]));
    }

    /** The workflow persists its request claim before quote I/O. No legacy SGD lock path. */
    public function createSgdPreparationLock(Transfer $transfer, string $requestId): FxQuote
    {
        $lease = app(NiumSgdAdmissionFence::class)->acquire();
        if (! (bool) config('services.nium.payout_fx_enabled', false)) {
            throw new RuntimeException('Nium payout FX is not enabled.');
        }
        $transfer = Transfer::query()->findOrFail($transfer->id);
        app(NiumSgdLocalCugScope::class)->assertTransfer($transfer);
        app(NiumSgdLocalPayoutWorkflow::class)->assertIntent($transfer);
        $workflow = $transfer->raw_data['sgd_local_workflow'] ?? [];
        if (! Str::isUuid($requestId) || $transfer->status !== 'draft'
            || ($workflow['release'] ?? null) !== NiumSgdLocalCugScope::RELEASE
            || ($workflow['state'] ?? null) !== 'locking'
            || ($workflow['lock_request_id'] ?? null) !== $requestId
            || $transfer->provider_operation_key !== null
            || filled($transfer->external_transfer_id) || filled($transfer->external_payment_id)) {
            throw new RuntimeException('A claimed saved standard SGD instruction is required for this quote.');
        }
        app(NiumSgdLocalPayoutGate::class)->assertCandidate($transfer->user, $transfer->provider, $transfer->beneficiary, 'SGD');
        NiumSgdLocalPayoutGate::assertSubmissionAllowed($transfer);
        $context = $this->context($transfer->provider, $transfer->user);
        $amount = NiumSgdLocalCugScope::principal($transfer->source_amount);
        $response = $this->niumService->postWithRequestId(
            $this->niumService->path((string) config('services.nium.quote_endpoint'), ['client' => $context['client']]),
            NiumPayoutQuoteContract::request($context['customer'], 'USD', 'SGD', $amount),
            $transfer->user, $requestId, 'create_payout_fx_lock',
            relatedTransferId: $transfer->id,
        );
        if (! $response->successful()) {
            throw new RuntimeException('Nium payout quote failed; reconcile the saved request before another attempt.');
        }
        $responseData = NiumPayoutQuoteContract::decodeResponse($response->body());
        $contract = NiumPayoutQuoteContract::parse($responseData, 'USD', 'SGD', $amount);

        return DB::transaction(fn () => FxQuote::create([
            'user_id' => $transfer->user_id, 'provider_id' => $transfer->provider_id,
            'quote_ref' => $contract['quote_id'], 'source_currency' => 'USD', 'target_currency' => 'SGD',
            'source_amount' => $amount,
            // Storage estimate only, never final beneficiary credit.
            'target_amount' => (string) BigDecimal::of($amount)->multipliedBy($contract['rate'])->toScale(8, RoundingMode::DOWN),
            'net_rate' => $contract['rate'], 'fee_amount' => 0, 'expires_at' => $contract['expires_at'],
            'raw_data' => [
                'provider_fx_type' => 'modern_quote', 'provider_quote_id' => $contract['quote_id'],
                'quote_type' => 'payout', 'quote_intent' => 'EXECUTABLE',
                'execution_type' => 'at_conversion_time', 'conversion_schedule' => 'immediate',
                'payout_quote_contract' => $contract, 'provider_context' => $context,
                'workflow_transfer_id' => $transfer->id, 'lock_request_uuid' => $requestId,
                'provider_fee_status' => 'unavailable',
            ],
        ]));
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
