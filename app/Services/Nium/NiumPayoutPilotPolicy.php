<?php

namespace App\Services\Nium;

use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\NiumPayoutPilotAuthorization;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** One exact test authorization. Never a pricing or fee assertion. */
final class NiumPayoutPilotPolicy
{
    public const PILOT = 'greentech-andy-usd5-sgd-local';

    public const UNCAPPED_POLICY = 'one_time_uncapped';

    public const UNCAPPED_RISK = 'additional_nium_charges_are_unknown_uncapped_and_not_reserved';

    /** Explicit business risk acceptance for this one transfer; no provider I/O. */
    public function authorizeOneTime(Transfer $transfer, User $authorizer, string $reference, bool $acceptUncappedCharges): NiumPayoutPilotAuthorization
    {
        return DB::transaction(function () use ($transfer, $authorizer, $reference, $acceptUncappedCharges): NiumPayoutPilotAuthorization {
            $transfer = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->assertScope($transfer);
            $this->assertVerifier($authorizer, $transfer);
            if (! $acceptUncappedCharges || trim($reference) === '' || strlen($reference) > 200
                || bccomp($transfer->source_amount, '5', 8) !== 0) {
                throw new RuntimeException('This exact USD 5 test requires an explicit authorization reference accepting uncapped additional NIUM charges.');
            }
            if ($transfer->status !== 'draft' || $transfer->provider_operation_key !== null || $transfer->approvals()->exists()
                || LedgerEntry::query()->where('source_type', 'transfer')->where('source_id', (string) $transfer->id)->exists()) {
                throw new RuntimeException('Only the undispatched pilot draft can receive this authorization.');
            }
            $binding = $this->binding($transfer);
            $balance = Balance::query()->where('user_id', $transfer->user_id)->where('provider_id', $transfer->provider_id)
                ->where('external_account_id', $binding['snapshot']['provider_context']['wallet'])
                ->where('currency', 'USD')->whereNull('bank_account_id')->lockForUpdate()->sole();
            $this->assertBalance($transfer, $balance, $binding['snapshot']['provider_context']['wallet']);
            if (bccomp($balance->available_balance, '5', 8) < 0) {
                throw new RuntimeException('Available funds in the authenticated source wallet must cover USD 5 principal.');
            }

            return NiumPayoutPilotAuthorization::query()->forceCreate(['id' => Str::uuid()->toString(), 'pilot' => self::PILOT,
                'policy' => self::UNCAPPED_POLICY, 'transfer_id' => $transfer->id, 'verified_by' => $authorizer->id,
                'binding' => $binding, 'verification' => ['authorization_reference' => trim($reference),
                    'risk_acknowledgement' => self::UNCAPPED_RISK, 'authorized_at' => now()->toISOString()],
                'expires_at' => $transfer->fxQuote->expires_at]);
        });
    }

    /**
     * Trusted local operations entry point, never exposed by an HTTP controller.
     * The named verifier must review the original funding and binding charge-ceiling
     * documents. Their attestation is recorded, not presented as a NIUM API quote.
     */
    public function authorize(Transfer $transfer, User $verifier, LedgerEntry $funding, string $ceiling, array $verification): NiumPayoutPilotAuthorization
    {
        return DB::transaction(function () use ($transfer, $verifier, $funding, $ceiling, $verification): NiumPayoutPilotAuthorization {
            $transfer = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->assertScope($transfer);
            $this->assertVerifier($verifier, $transfer);
            if ($transfer->status !== 'draft' || $transfer->provider_operation_key !== null || $transfer->approvals()->exists()
                || LedgerEntry::query()->where('source_type', 'transfer')->where('source_id', (string) $transfer->id)->exists()) {
                throw new RuntimeException('Only the undispatched pilot draft can receive this authorization.');
            }
            $ceiling = NiumSgdLocalCugScope::principal($ceiling);
            foreach (['funding_reference', 'charge_bound_reference', 'review_document_sha256'] as $key) {
                if (! is_string($verification[$key] ?? null) || trim($verification[$key]) === '') {
                    throw new RuntimeException('Independent funding and charge-bound verification references are required.');
                }
            }
            if (! preg_match('/^[a-f0-9]{64}$/D', $verification['review_document_sha256'])
                || ($verification['attestation'] ?? null) !== 'settled_separate_funding_and_binding_all_charges_usd_ceiling_same_wallet') {
                throw new RuntimeException('Verification must cover separate settled funding and every possible additional USD charge in this wallet.');
            }
            $binding = $this->binding($transfer);
            $funding = LedgerEntry::query()->lockForUpdate()->findOrFail($funding->id);
            $balance = Balance::query()->whereKey($funding->balance_id)->lockForUpdate()->firstOrFail();
            if ($funding->entry_type !== 'credit' || $funding->status !== 'posted' || $funding->posted_at === null
                || $funding->posted_at->isFuture() || $funding->source_type === 'transfer'
                || $funding->user_id !== $transfer->user_id || $funding->provider_id !== $transfer->provider_id
                || $funding->currency !== 'USD' || bccomp($funding->amount, $ceiling, 8) < 0
                || $verification['funding_reference'] !== $funding->reference) {
                throw new RuntimeException('A distinct posted USD funding credit covering the charge ceiling is required.');
            }
            $this->assertBalance($transfer, $balance, $binding['snapshot']['provider_context']['wallet']);
            if (bccomp($balance->available_balance, bcadd($ceiling, $transfer->source_amount, 8), 8) < 0) {
                throw new RuntimeException('Verified available funds must cover both principal and the separately funded reserve.');
            }
            $id = Str::uuid()->toString();
            $balance->available_balance = bcsub($balance->available_balance, $ceiling, 8);
            $balance->reserved_balance = bcadd($balance->reserved_balance, $ceiling, 8);
            $raw = $balance->raw_data ?? [];
            $raw['_origin_ledger']['pilot_reserve_id'] = $id;
            $balance->raw_data = $raw;
            $balance->save();
            $hold = LedgerEntry::query()->create(['balance_id' => $balance->id, 'user_id' => $transfer->user_id,
                'provider_id' => $transfer->provider_id, 'reference' => "nium:pilot:{$id}:reserve", 'entry_type' => 'hold',
                'status' => 'posted', 'currency' => 'USD', 'amount' => '-'.$ceiling, 'balance_after' => $balance->available_balance,
                'source_type' => 'nium_payout_pilot', 'source_id' => $id, 'posted_at' => now(),
                'description' => 'Separately funded pilot charge reserve; not principal or a fee quote.',
                'raw_data' => ['transfer_id' => $transfer->id, 'funding_ledger_entry_id' => $funding->id, 'verified_by' => $verifier->id]]);

            return NiumPayoutPilotAuthorization::query()->forceCreate(['id' => $id, 'pilot' => self::PILOT,
                'transfer_id' => $transfer->id, 'funding_ledger_entry_id' => $funding->id, 'reserve_ledger_entry_id' => $hold->id,
                'verified_by' => $verifier->id, 'binding' => $binding, 'verification' => $verification,
                'funding_sha256' => $this->fundingHash($funding), 'charge_ceiling' => $ceiling, 'reserve_remaining' => $ceiling,
                'expires_at' => $transfer->fxQuote->expires_at]);
        });
    }

    public function terms(Transfer $transfer): array
    {
        if (config('services.nium.payout_pilot.enabled', false) !== true) {
            throw new RuntimeException('The pilot policy is disabled.');
        }
        $this->assertScope($transfer);
        $grant = NiumPayoutPilotAuthorization::query()->find(config('services.nium.payout_pilot.authorization_id'));
        if ($grant === null || $grant->pilot !== self::PILOT || $grant->transfer_id !== $transfer->id
            || $grant->revoked_at !== null || $grant->expires_at->lessThanOrEqualTo(now())
            || ($grant->consumed_operation_key !== null && $grant->consumed_operation_key !== $transfer->provider_operation_key)
            || ($transfer->status === 'submitting' && $grant->consumed_operation_key !== $transfer->provider_operation_key)
            || ! NiumPayoutFxLockContract::sameBinding($grant->binding, $this->binding($transfer))) {
            throw new RuntimeException('The one-use pilot authorization does not match this exact transfer and FX lock.');
        }
        $this->assertVerifier(User::query()->findOrFail($grant->verified_by), $transfer);
        if ($grant->policy === self::UNCAPPED_POLICY) {
            return $this->uncappedTerms($transfer, $grant);
        }
        if ($grant->policy !== 'funded_reserve') {
            throw new RuntimeException('Unrecognized pilot authorization policy.');
        }
        $funding = LedgerEntry::query()->findOrFail($grant->funding_ledger_entry_id);
        $hold = LedgerEntry::query()->findOrFail($grant->reserve_ledger_entry_id);
        $balance = Balance::query()->findOrFail($hold->balance_id);
        $this->assertBalance($transfer, $balance, $grant->binding['snapshot']['provider_context']['wallet']);
        $principalHold = LedgerEntry::query()->where('reference', "transfer:{$transfer->id}:hold")->first();
        $required = bcadd($grant->charge_ceiling, $principalHold === null ? '0' : $transfer->source_amount, 8);
        if (! hash_equals($grant->funding_sha256, $this->fundingHash($funding))
            || $funding->balance_id !== $hold->balance_id || $hold->reference !== "nium:pilot:{$grant->id}:reserve"
            || $hold->source_type !== 'nium_payout_pilot' || $hold->source_id !== $grant->id
            || $hold->user_id !== $transfer->user_id || $hold->provider_id !== $transfer->provider_id
            || $hold->currency !== 'USD' || $hold->entry_type !== 'hold' || $hold->status !== 'posted'
            || bccomp($hold->amount, '-'.$grant->charge_ceiling, 8) !== 0
            || bccomp($grant->reserve_remaining, $grant->charge_ceiling, 8) !== 0
            || bccomp($balance->reserved_balance, $required, 8) < 0) {
            throw new RuntimeException('The verified pilot funding or protected reserve changed.');
        }

        return ['funding_basis' => 'principal_only_pilot', 'authorization_id' => $grant->id,
            'expires_at' => $grant->expires_at->toISOString(), 'reservation_amount' => NiumSgdLocalCugScope::principal($transfer->source_amount),
            'fee_amount' => null, 'fee_currency' => 'USD', 'fee_status' => 'unverified', 'total_wallet_debit' => null,
            'pilot_reserve' => ['currency' => 'USD', 'amount' => $grant->charge_ceiling, 'charge_ceiling' => $grant->charge_ceiling,
                'verification_basis' => 'independent_operator_attestation', 'verified_by' => $grant->verified_by,
                'review_document_sha256' => $grant->verification['review_document_sha256']]];
    }

    /** Same transaction as the existing operation claim and principal reservation. */
    public function consume(Transfer $transfer, array $terms): void
    {
        if (($terms['funding_basis'] ?? null) !== 'principal_only_pilot') {
            return;
        }
        if (DB::transactionLevel() < 1 || $transfer->provider_operation_key === null) {
            throw new RuntimeException('Pilot consumption requires the atomic operation claim.');
        }
        $grant = NiumPayoutPilotAuthorization::query()->whereKey($terms['authorization_id'])->lockForUpdate()->firstOrFail();
        if ($grant->consumed_operation_key !== null) {
            throw new RuntimeException('The pilot authorization has already been consumed.');
        }
        $this->terms($transfer);
        $grant->forceFill(['consumed_operation_key' => $transfer->provider_operation_key, 'consumed_at' => now()])->save();
    }

    /** Called only for a new authenticated fee event inside reconciliation's savepoint. */
    public function applyFee(Transfer $transfer, Balance $balance, string $amount): string
    {
        $terms = $transfer->raw_data['sgd_local_approval']['financial_terms'] ?? [];
        if (($terms['funding_basis'] ?? null) !== 'principal_only_pilot') {
            return $amount;
        }
        // Recovery/accounting must continue with activation disabled or authorization expired.
        $grant = NiumPayoutPilotAuthorization::query()->whereKey($terms['authorization_id'])->lockForUpdate()->firstOrFail();
        if ($grant->policy === self::UNCAPPED_POLICY) {
            if ($grant->transfer_id !== $transfer->id || $grant->consumed_operation_key !== $transfer->provider_operation_key
                || $balance->user_id !== $transfer->user_id || $balance->provider_id !== $transfer->provider_id
                || $balance->currency !== 'USD' || $balance->external_account_id !== $grant->binding['snapshot']['provider_context']['wallet']) {
                throw new RuntimeException('The original one-time charge authorization does not match this wallet and operation.');
            }

            return $amount; // No fee reserve or verified ceiling was claimed.
        }
        $hold = LedgerEntry::query()->findOrFail($grant->reserve_ledger_entry_id);
        if ($grant->transfer_id !== $transfer->id || $grant->consumed_operation_key !== $transfer->provider_operation_key
            || $hold->balance_id !== $balance->id || bccomp($balance->reserved_balance, $grant->reserve_remaining, 8) < 0) {
            throw new RuntimeException('Original pilot fee reserve requires financial review.');
        }
        $covered = bccomp($amount, $grant->reserve_remaining, 8) <= 0 ? $amount : $grant->reserve_remaining;
        $balance->reserved_balance = bcsub($balance->reserved_balance, $covered, 8);
        $grant->forceFill(['reserve_remaining' => bcsub($grant->reserve_remaining, $covered, 8)])->save();

        return bcsub($amount, $covered, 8);
    }

    private function uncappedTerms(Transfer $transfer, NiumPayoutPilotAuthorization $grant): array
    {
        if (bccomp($transfer->source_amount, '5', 8) !== 0
            || ($grant->verification['risk_acknowledgement'] ?? null) !== self::UNCAPPED_RISK
            || ! filled($grant->verification['authorization_reference'] ?? null)
            || $grant->charge_ceiling !== null || $grant->reserve_remaining !== null
            || $grant->reserve_ledger_entry_id !== null || $grant->funding_ledger_entry_id !== null) {
            throw new RuntimeException('The exact one-time uncapped-charge authorization is required.');
        }
        $wallet = $grant->binding['snapshot']['provider_context']['wallet'];
        $hold = LedgerEntry::query()->where('reference', "transfer:{$transfer->id}:hold")->first();
        $balance = Balance::query()->where('user_id', $transfer->user_id)->where('provider_id', $transfer->provider_id)
            ->where('external_account_id', $wallet)->where('currency', 'USD')->whereNull('bank_account_id')->sole();
        $this->assertBalance($transfer, $balance, $wallet);
        if ($hold === null ? bccomp($balance->available_balance, '5', 8) < 0
            : ($hold->balance_id !== $balance->id || $hold->user_id !== $transfer->user_id || $hold->provider_id !== $transfer->provider_id
                || $hold->currency !== 'USD' || $hold->entry_type !== 'hold' || $hold->status !== 'posted'
                || $hold->source_type !== 'transfer' || $hold->source_id !== (string) $transfer->id
                || ($hold->raw_data['operation_key'] ?? null) !== $transfer->provider_operation_key
                || bccomp($hold->amount, '-5', 8) !== 0 || bccomp($balance->reserved_balance, '5', 8) < 0)) {
            throw new RuntimeException('Available USD 5 principal or its live owned reservation is required.');
        }

        return ['funding_basis' => 'principal_only_pilot', 'policy' => self::UNCAPPED_POLICY, 'authorization_id' => $grant->id,
            'authorization_reference' => $grant->verification['authorization_reference'], 'authorized_by' => $grant->verified_by,
            'expires_at' => $grant->expires_at->toISOString(), 'reservation_amount' => '5',
            'fee_amount' => null, 'fee_currency' => 'USD', 'fee_status' => 'unverified', 'total_wallet_debit' => null,
            'pilot_reserve' => null, 'charge_ceiling' => null, 'risk_acknowledgement' => self::UNCAPPED_RISK];
    }

    private function assertScope(Transfer $transfer): void
    {
        // Production identities from the existing controlled-test runbook; the grant
        // additionally binds authenticated provider identities and the full FX intent.
        if ($transfer->user_id !== 12 || $transfer->beneficiary_id !== 9
            || bccomp(NiumSgdLocalCugScope::principal($transfer->source_amount), '5', 8) > 0
            || ! filled($transfer->client_reference)) {
            throw new RuntimeException('Pilot is limited to GREENTECH (12), Andy (9), and at most USD 5.');
        }
        app(NiumSgdLocalCugScope::class)->assertTransfer($transfer);
    }

    private function binding(Transfer $transfer): array
    {
        return ['transfer_id' => $transfer->id, 'transfer_no' => $transfer->transfer_no, 'client_reference' => $transfer->client_reference,
            'snapshot' => app(NiumSgdLocalPayoutAuthorization::class)->snapshot($transfer, $transfer->provider_operation_key)];
    }

    private function assertVerifier(User $verifier, Transfer $transfer): void
    {
        if ($verifier->id === $transfer->user_id || $verifier->status !== 'active' || ! $verifier->hasPermission('transfers.approve')) {
            throw new RuntimeException('An active independent authorized verifier is required.');
        }
    }

    private function assertBalance(Transfer $transfer, Balance $balance, string $wallet): void
    {
        if (! config('wallet.ledger.enabled', true) || $balance->user_id !== $transfer->user_id
            || $balance->provider_id !== $transfer->provider_id || $balance->external_account_id !== $wallet
            || $balance->bank_account_id !== null || $balance->currency !== 'USD' || $balance->as_of === null
            || $balance->as_of->isFuture() || $balance->as_of->lessThanOrEqualTo(now()->subMinutes(5))) {
            throw new RuntimeException('A current verified source-wallet balance and enabled ledger are required.');
        }
    }

    private function fundingHash(LedgerEntry $entry): string
    {
        return hash('sha256', json_encode($entry->only(['id', 'balance_id', 'user_id', 'provider_id', 'reference',
            'entry_type', 'status', 'currency', 'amount', 'source_type', 'source_id', 'posted_at', 'raw_data']), JSON_THROW_ON_ERROR));
    }
}
