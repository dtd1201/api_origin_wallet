<?php

namespace App\Services\Nium;

use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\NiumPayoutPilotAuthorization;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** One exact test authorization. Never a pricing or fee assertion. */
final class NiumPayoutPilotPolicy
{
    public const PILOT = 'greentech-andy-usd5-sgd-local';

    public const UNCAPPED_POLICY = 'one_time_uncapped';

    public const UNCAPPED_RISK = 'additional_nium_charges_are_unknown_uncapped_and_not_reserved';

    /** Admin preparation only. Never acquire FX, create a maker review or dispatch. */
    public function renewalPreview(Transfer $transfer, ?User $authorizer): ?array
    {
        if ($transfer->id !== 3 || $authorizer === null || ! $authorizer->hasPermission('transfers.approve')) {
            return null;
        }
        $view = ['policy' => self::UNCAPPED_POLICY, 'risk_acknowledgement' => self::UNCAPPED_RISK,
            'expected_fee' => ['amount' => '0', 'currency' => 'USD', 'basis' => 'internal_expectation_not_provider_verified'],
            'provider_fee_status' => 'unverified', 'total_wallet_debit' => null, 'renewal_allowed' => false];
        try {
            [$grant, $effective, $binding, $revision] = $this->renewalContext($transfer, $authorizer);
            if ($effective->expires_at->isFuture()) {
                if (! NiumPayoutFxLockContract::sameBinding($effective->binding, $binding)) {
                    throw new RuntimeException('The current risk acceptance belongs to different FX terms.');
                }

                return [...$view, 'state' => 'current_authorization', 'authorization_id' => $grant->id,
                    'authorization_revision' => $revision,
                    'expires_at' => $effective->expires_at->toISOString()];
            }

            return [...$view, 'state' => 'explicit_risk_renewal_required', 'renewal_allowed' => true,
                'authorization_id' => $grant->id, 'previous_revision' => $revision ?? $grant->id,
                'fx_quote_id' => $binding['snapshot']['fx_quote_id'], 'fx_rate' => $binding['snapshot']['rate'],
                'expires_at' => $binding['snapshot']['expires_at'],
                'confirmation_token' => $this->renewalToken($grant, $binding, $revision, $authorizer)];
        } catch (RuntimeException $exception) {
            return [...$view, 'state' => 'blocked', 'reason' => $exception->getMessage()];
        }
    }

    /** Append a new risk acceptance; retain the original grant, binding, expiry and approval history. */
    public function renewOneTime(Transfer $transfer, User $authorizer, string $reference, bool $acceptUncappedCharges, string $requestId, string $confirmationToken): NiumPayoutPilotAuthorization
    {
        return DB::transaction(function () use ($transfer, $authorizer, $reference, $acceptUncappedCharges, $requestId, $confirmationToken): NiumPayoutPilotAuthorization {
            $transfer = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            [$grant, $effective, $binding, $revision] = $this->renewalContext($transfer, $authorizer, true);
            if (! $acceptUncappedCharges || trim($reference) === '' || strlen($reference) > 200 || ! Str::isUuid($requestId)) {
                throw new RuntimeException('A new explicit uncapped-charge acceptance, reference and request UUID are required.');
            }
            $verification = $grant->verification;
            $history = $verification['one_time_renewals'] ?? [];
            foreach ($history as $receipt) {
                if ($receipt['request_id'] === $requestId) {
                    if ($receipt['verified_by'] === $authorizer->id
                        && $receipt['verification']['authorization_reference'] === trim($reference)
                        && hash_equals($receipt['confirmation_token'], $confirmationToken)
                        && NiumPayoutFxLockContract::sameBinding($receipt['binding'], $binding)) {
                        return $grant; // A repeated preparation click never creates another acceptance.
                    }
                    throw new RuntimeException('This risk-acceptance request UUID already belongs to different terms.');
                }
            }
            if ($effective->expires_at->isFuture()
                || Carbon::parse($binding['snapshot']['expires_at'])->lessThanOrEqualTo(now())
                || ! hash_equals($this->renewalToken($grant, $binding, $revision, $authorizer), $confirmationToken)) {
                throw new RuntimeException('Refresh the expired authorization and explicitly review the current FX and risk terms.');
            }
            $history[] = ['id' => Str::uuid()->toString(), 'previous_revision' => $revision ?? $grant->id,
                'request_id' => $requestId, 'confirmation_token' => $confirmationToken, 'verified_by' => $authorizer->id,
                'binding' => $binding, 'expires_at' => $binding['snapshot']['expires_at'],
                'verification' => ['authorization_reference' => trim($reference), 'risk_acknowledgement' => self::UNCAPPED_RISK,
                    'authorized_at' => now()->toISOString(), 'basis' => 'explicit_authenticated_admin_risk_renewal']];
            $verification['one_time_renewals'] = $history;
            $grant->forceFill(['verification' => $verification])->save();

            return $grant;
        });
    }

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
        $revision = null;
        if ($grant !== null && $grant->policy === self::UNCAPPED_POLICY) {
            [$grant, $revision] = $this->effectiveOneTime($grant);
        }
        if ($grant === null || $grant->pilot !== self::PILOT || $grant->transfer_id !== $transfer->id
            || $grant->revoked_at !== null || $grant->expires_at->lessThanOrEqualTo(now())
            || ($grant->consumed_operation_key !== null && $grant->consumed_operation_key !== $transfer->provider_operation_key)
            || ($transfer->status === 'submitting' && $grant->consumed_operation_key !== $transfer->provider_operation_key)
            || ! NiumPayoutFxLockContract::sameBinding($grant->binding, $this->binding($transfer))) {
            throw new RuntimeException('The one-use pilot authorization does not match this exact transfer and FX lock.');
        }
        $this->assertVerifier(User::query()->findOrFail($grant->verified_by), $transfer);
        if ($grant->policy === self::UNCAPPED_POLICY) {
            $terms = $this->uncappedTerms($transfer, $grant);

            return $revision === null ? $terms : [...$terms, 'authorization_revision' => $revision];
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

    private function renewalContext(Transfer $transfer, User $authorizer, bool $lock = false): array
    {
        if ($transfer->id !== 3 || config('services.nium.payout_pilot.enabled', false) !== true) {
            throw new RuntimeException('Risk renewal is limited to Transfer 3 with the existing pilot explicitly enabled.');
        }
        $this->assertScope($transfer);
        $this->assertVerifier($authorizer, $transfer);
        $historicalApprovals = array_filter(array_column($transfer->raw_data['sgd_local_workflow']['review_history'] ?? [], 'approval_id'));
        if ($transfer->status !== 'draft' || $transfer->provider_operation_key !== null || filled($transfer->external_transfer_id)
            || ($transfer->raw_data['sgd_local_workflow']['state'] ?? null) !== 'locked'
            || bccomp($transfer->source_amount, '5', 8) !== 0
            || $transfer->approvals()->whereNotIn('id', $historicalApprovals)->exists()
            || LedgerEntry::query()->where('source_type', 'transfer')->where('source_id', '3')->exists()) {
            throw new RuntimeException('Restart the undispatched expired review through the maker workflow before renewing risk acceptance.');
        }
        if ($transfer->fxQuote?->expires_at === null || $transfer->fxQuote->expires_at->lessThanOrEqualTo(now())) {
            throw new RuntimeException('The maker must explicitly request and review a fresh FX lock; Admin approval cannot replace the rate.');
        }
        $binding = $this->binding($transfer);
        $query = NiumPayoutPilotAuthorization::query()->whereKey(config('services.nium.payout_pilot.authorization_id'));
        $grant = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($grant === null || $grant->transfer_id !== 3 || $grant->pilot !== self::PILOT || $grant->policy !== self::UNCAPPED_POLICY
            || $grant->revoked_at !== null || $grant->consumed_at !== null || $grant->consumed_operation_key !== null
            || $grant->charge_ceiling !== null || $grant->reserve_remaining !== null
            || $grant->reserve_ledger_entry_id !== null || $grant->funding_ledger_entry_id !== null
            || ($grant->verification['risk_acknowledgement'] ?? null) !== self::UNCAPPED_RISK) {
            throw new RuntimeException('The selected unconsumed, unrevoked one-time authorization for Transfer 3 is required.');
        }
        $this->assertRenewalBinding($grant->binding, $binding);
        [$effective, $revision] = $this->effectiveOneTime($grant);
        $balanceQuery = Balance::query()->where('user_id', $transfer->user_id)->where('provider_id', $transfer->provider_id)
            ->where('external_account_id', $binding['snapshot']['provider_context']['wallet'])->where('currency', 'USD')->whereNull('bank_account_id');
        $balance = ($lock ? $balanceQuery->lockForUpdate() : $balanceQuery)->sole();
        $this->assertBalance($transfer, $balance, $binding['snapshot']['provider_context']['wallet']);
        if (bccomp($balance->available_balance, '5', 8) < 0) {
            throw new RuntimeException('A current authenticated source-wallet balance must cover USD 5 principal.');
        }

        return [$grant, $effective, $binding, $revision];
    }

    /** Read an effective view only; never save it over the original authorization. */
    private function effectiveOneTime(NiumPayoutPilotAuthorization $grant): array
    {
        $history = $grant->verification['one_time_renewals'] ?? [];
        if (! is_array($history) || ! array_is_list($history)) {
            throw new RuntimeException('Risk-acceptance history is invalid.');
        }
        if ($history === []) {
            return [$grant, null];
        }
        if ($grant->transfer_id !== 3 || $grant->pilot !== self::PILOT || $grant->policy !== self::UNCAPPED_POLICY) {
            throw new RuntimeException('Renewed risk acceptance cannot be copied to another pilot or transfer.');
        }
        $previous = $grant->id;
        $previousExpiry = $grant->expires_at;
        $seen = [];
        foreach ($history as $receipt) {
            if (! is_array($receipt) || ! is_string($receipt['id'] ?? null) || ! Str::isUuid($receipt['id'])
                || ! is_string($receipt['request_id'] ?? null) || ! Str::isUuid($receipt['request_id'])
                || ! is_string($receipt['confirmation_token'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $receipt['confirmation_token'])
                || isset($seen[$receipt['id']]) || isset($seen[$receipt['request_id']])
                || ($receipt['previous_revision'] ?? null) !== $previous
                || ! is_int($receipt['verified_by'] ?? null)
                || ($receipt['binding']['transfer_id'] ?? null) !== 3
                || ($receipt['binding']['transfer_no'] ?? null) !== ($grant->binding['transfer_no'] ?? null)
                || ($receipt['binding']['client_reference'] ?? null) !== ($grant->binding['client_reference'] ?? null)
                || ($receipt['verification']['risk_acknowledgement'] ?? null) !== self::UNCAPPED_RISK
                || ($receipt['verification']['basis'] ?? null) !== 'explicit_authenticated_admin_risk_renewal'
                || ! is_string($receipt['verification']['authorization_reference'] ?? null)
                || trim($receipt['verification']['authorization_reference']) === ''
                || strlen($receipt['verification']['authorization_reference']) > 200
                || ($receipt['expires_at'] ?? null) !== ($receipt['binding']['snapshot']['expires_at'] ?? null)
                || Carbon::parse(NiumPayoutFxLockContract::expiry($receipt['verification']['authorized_at'] ?? null))->isFuture()
                || Carbon::parse(NiumPayoutFxLockContract::expiry($receipt['expires_at'] ?? null))->lessThanOrEqualTo(Carbon::parse($receipt['verification']['authorized_at']))) {
                throw new RuntimeException('Risk-acceptance history is inconsistent or unverifiable.');
            }
            $this->assertRenewalBinding($grant->binding, $receipt['binding']);
            if ($previousExpiry->isAfter(Carbon::parse($receipt['verification']['authorized_at']))) {
                throw new RuntimeException('An active risk acceptance cannot be superseded by a renewal.');
            }
            $seen[$receipt['id']] = true;
            $seen[$receipt['request_id']] = true;
            $previous = $receipt['id'];
            $previousExpiry = Carbon::parse($receipt['expires_at']);
        }
        $effective = clone $grant;
        $effective->forceFill(['binding' => $receipt['binding'], 'expires_at' => $receipt['expires_at'],
            'verification' => $receipt['verification'], 'verified_by' => $receipt['verified_by']]);

        return [$effective, $previous];
    }

    /** Renewal changes FX terms only; the original wallet, beneficiary and payment intent remain bound. */
    private function assertRenewalBinding(array $original, array $binding): void
    {
        foreach (['fx_quote_id', 'audit_id', 'rate', 'expires_at'] as $key) {
            unset($original['snapshot'][$key], $binding['snapshot'][$key]);
        }
        if (! NiumPayoutFxLockContract::sameBinding($original, $binding)) {
            throw new RuntimeException('Risk renewal cannot change the original pilot payment intent or source wallet.');
        }
    }

    private function renewalToken(NiumPayoutPilotAuthorization $grant, array $binding, ?string $revision, User $authorizer): string
    {
        return NiumSgdLocalPayoutWorkflow::financialTermsToken(['authorization_id' => $grant->id,
            'previous_revision' => $revision ?? $grant->id, 'binding' => $binding,
            'authorizer_id' => $authorizer->id, 'risk_acknowledgement' => self::UNCAPPED_RISK]);
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
