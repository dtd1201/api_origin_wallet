<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Models\AmlScreening;
use App\Models\Balance;
use App\Models\BankAccount;
use App\Models\Beneficiary;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use App\Models\NiumDynamicReservation;
use App\Models\NiumRfiCase;
use App\Models\User;
use App\Models\UserProviderAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Internal confirmation/reservation boundary; provider I/O belongs to the outbox worker. */
final class DynamicTransferConfirmationService
{
    public function __construct(
        private readonly DynamicReservationLedger $ledger,
        private readonly ?DynamicPayoutEvidenceSource $evidence = null,
    ) {}

    public function confirm(User $authenticatedActor, int $providerAccountId, int $beneficiaryId,
        int $balanceId, string $clientReference, string $acceptedFingerprint): NiumDynamicIntent
    {
        $this->assertConfirmationEnabled();
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/D', $clientReference) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $acceptedFingerprint) !== 1
            || min($providerAccountId, $beneficiaryId, $balanceId) < 1 || ! $authenticatedActor->exists) {
            throw new RuntimeException('dynamic_confirmation_request_invalid');
        }
        $requestFingerprint = hash('sha256', json_encode([
            'version' => 'nium-dynamic-confirm-request-v1', 'owner' => (string) $authenticatedActor->id,
            'provider_account_id' => $providerAccountId, 'beneficiary_id' => $beneficiaryId,
            'balance_id' => $balanceId, 'client_reference' => $clientReference,
            'accepted_terms_fingerprint' => $acceptedFingerprint,
        ], JSON_THROW_ON_ERROR));

        return OwnerFirst::users([$authenticatedActor->id], function ($context) use ($authenticatedActor, $providerAccountId, $beneficiaryId,
            $balanceId, $clientReference, $acceptedFingerprint, $requestFingerprint): NiumDynamicIntent {
            // A real row serializes even FIRST requests; no reliance on locking a missing intent.
            $user = User::query()->lockForUpdate()->findOrFail($authenticatedActor->id);
            $existing = NiumDynamicIntent::query()->where('user_id', $user->id)
                ->where('reference_sha256', hash('sha256', $clientReference))->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_fingerprint, $requestFingerprint)
                    || ! hash_equals($existing->terms_fingerprint, $acceptedFingerprint)) {
                    throw new RuntimeException('dynamic_confirmation_idempotency_conflict');
                }

                // Retrieval only: even an expired/cancelled original is never recreated.
                return $existing;
            }
            if ($this->evidence === null) {
                throw new RuntimeException('dynamic_authoritative_evidence_source_unavailable');
            }

            $account = UserProviderAccount::query()->where('user_id', $user->id)
                ->lockForUpdate()->findOrFail($providerAccountId);
            $provider = IntegrationProvider::query()->lockForUpdate()->findOrFail($account->provider_id);
            $beneficiary = Beneficiary::query()->where('user_id', $user->id)->where('provider_id', $provider->id)
                ->lockForUpdate()->findOrFail($beneficiaryId);
            $wallet = Balance::query()->where('user_id', $user->id)->where('provider_id', $provider->id)
                ->lockForUpdate()->findOrFail($balanceId);
            $kyc = KycProfile::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $records = new LockedPayoutRecords($user, $provider, $account, $beneficiary, $wallet, $kyc, $acceptedFingerprint);
            $this->assertLocalEligibility($records);
            DynamicPayoutGate::assertWalletClear((int) $provider->id, (string) $wallet->external_account_id);

            // Server clock is read AFTER lock acquisition, never accepted from the client.
            $at = now()->toDateTimeImmutable();
            $evidence = $this->evidence->revalidate($records, $at);
            $evidence->assertCurrent($at);
            $this->assertBindings($records, $evidence);
            if ($evidence->review->context->corridor->sourceCurrency === 'USD'
                && $evidence->review->context->corridor->destinationCurrency !== 'USD'
                && $evidence->review->finalizedPreparationBinding === null) {
                throw new RuntimeException('dynamic_finalized_usd_preparation_unavailable');
            }
            $confirmed = $evidence->review->confirm((string) $user->id, $acceptedFingerprint, $at);
            // Recheck time after adapter work; never persist a quote that expired while validating.
            $commitAt = now()->toDateTimeImmutable();
            if ($commitAt >= $confirmed->quote->expiresAt || $commitAt >= $evidence->validUntil) {
                throw new RuntimeException('dynamic_confirmation_evidence_expired');
            }
            // Reuse AUTO-02A's complete financial/evidence validation at the later instant too.
            $evidence->review->confirm((string) $user->id, $acceptedFingerprint, $commitAt);
            $debit = $confirmed->terms->totalWalletDebit;
            if ($debit === null || $confirmed->terms->transactionFee === null) {
                throw new RuntimeException('dynamic_authoritative_financial_terms_unavailable');
            }
            $amount = DynamicReservationLedger::storage($debit->amount->value, true);
            $corridor = $confirmed->context->corridor;
            $quoteKey = NiumDynamicIntent::quoteConsumptionKey((int) $provider->id, $corridor->environment,
                $corridor->client, $corridor->customer, $confirmed->quote->id);
            if (NiumDynamicIntent::query()->where('quote_consumption_key', $quoteKey)->exists()) {
                throw new RuntimeException('dynamic_quote_already_consumed');
            }

            $intent = NiumDynamicIntent::query()->forceCreate([
                'id' => (string) Str::uuid(), 'user_id' => $user->id, 'provider_id' => $provider->id,
                'provider_account_id' => $account->id, 'beneficiary_id' => $beneficiary->id, 'balance_id' => $wallet->id,
                'client_reference' => $clientReference, 'reference_sha256' => hash('sha256', $clientReference),
                'request_fingerprint' => $requestFingerprint, 'terms_fingerprint' => $confirmed->fingerprint(),
                'quote_consumption_key' => $quoteKey, 'quote_id' => $confirmed->quote->id,
                'quote_expires_at' => $confirmed->quote->expiresAt, 'customer' => $corridor->customer,
                'source_wallet' => $corridor->fundingWallet, 'amount_mode' => $confirmed->quote->request->mode->value,
                'source_currency' => $corridor->sourceCurrency, 'destination_currency' => $corridor->destinationCurrency,
                'total_debit' => $amount, 'consent_actor_id' => $user->id, 'consented_at' => $at,
                'confirmed_terms' => $confirmed->snapshot(), 'consent_binding' => $confirmed->confirmationBinding(),
                'admission_evidence' => $evidence->snapshot(), 'created_at' => $at,
            ]);
            if (! $intent->exists) {
                throw new RuntimeException('dynamic_intent_not_persisted');
            }
            $reservationId = (string) Str::uuid();
            $hold = $this->ledger->reserve($intent, $wallet, $reservationId, $context);
            $reservation = NiumDynamicReservation::query()->forceCreate([
                'id' => $reservationId, 'intent_id' => $intent->id, 'balance_id' => $wallet->id,
                'currency' => $debit->currency, 'amount' => $amount, 'state' => 'held',
                'hold_entry_id' => $hold->id, 'created_at' => $at,
            ]);
            if (! $reservation->exists) {
                throw new RuntimeException('dynamic_reservation_not_persisted');
            }
            $outbox = NiumDynamicPayoutOutbox::query()->forceCreate([
                'id' => (string) Str::uuid(), 'intent_id' => $intent->id, 'reservation_id' => $reservation->id,
                'terms_fingerprint' => $intent->terms_fingerprint, 'state' => 'held', 'dispatchable' => false,
                'attempts' => 0, 'created_at' => $at,
            ]);
            $finalAt = now()->toDateTimeImmutable();
            if (! $outbox->exists || $finalAt >= $evidence->validUntil) {
                throw new RuntimeException('dynamic_outbox_or_admission_evidence_unavailable');
            }
            $evidence->review->confirm((string) $user->id, $acceptedFingerprint, $finalAt);

            return $intent;
        }, 3);
    }

    /** Check the same locked compliance/admission boundary before disclosing READY. No hold or payout. */
    public function validateReview(User $actor, int $accountId, int $beneficiaryId, int $balanceId, string $fingerprint): void
    {
        $this->assertConfirmationEnabled();
        OwnerFirst::users([$actor->id], function ($context) use ($actor, $accountId, $beneficiaryId, $balanceId, $fingerprint): void {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            $account = UserProviderAccount::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($accountId);
            $provider = IntegrationProvider::query()->lockForUpdate()->findOrFail($account->provider_id);
            $beneficiary = Beneficiary::query()->where('user_id', $user->id)->where('provider_id', $provider->id)->lockForUpdate()->findOrFail($beneficiaryId);
            $wallet = Balance::query()->where('user_id', $user->id)->where('provider_id', $provider->id)->lockForUpdate()->findOrFail($balanceId);
            $kyc = KycProfile::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $records = new LockedPayoutRecords($user, $provider, $account, $beneficiary, $wallet, $kyc, $fingerprint);
            $this->assertLocalEligibility($records);
            DynamicPayoutGate::assertWalletClear((int) $provider->id, $wallet->external_account_id);
            if ($this->evidence === null) throw new RuntimeException('dynamic_authoritative_evidence_source_unavailable');
            $at = now()->toDateTimeImmutable();
            $evidence = $this->evidence->revalidate($records, $at);
            $evidence->assertCurrent($at);
            $this->assertBindings($records, $evidence);
            $finalAt = now()->toDateTimeImmutable();
            if ($evidence->validUntil <= $finalAt) throw new RuntimeException('dynamic_confirmation_evidence_expired');
            $evidence->review->confirm((string) $user->id, $fingerprint, $finalAt);
        }, 3);
    }

    public function refreshForExecution(NiumDynamicIntent $intent): void
    {
        OwnerFirst::outside();
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('dynamic_execution_refresh_requires_no_transaction');
        }
        if ($this->evidence === null) {
            throw new RuntimeException('dynamic_authoritative_evidence_source_unavailable');
        }
        $this->evidence->refreshForExecution($intent);
    }

    /** Owner-only local cancellation; available even if new confirmations are switched off. */
    public function cancel(User $authenticatedActor, string $intentId): NiumDynamicIntent
    {
        $this->assertStorage();
        if (! $authenticatedActor->exists || ! Str::isUuid($intentId)) {
            throw new RuntimeException('dynamic_cancellation_request_invalid');
        }

        return OwnerFirst::users([$authenticatedActor->id], function ($context) use ($authenticatedActor, $intentId): NiumDynamicIntent {
            $user = User::query()->lockForUpdate()->findOrFail($authenticatedActor->id);
            $intent = NiumDynamicIntent::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($intentId);
            $reservation = NiumDynamicReservation::query()->where('intent_id', $intent->id)->lockForUpdate()->sole();
            $outbox = NiumDynamicPayoutOutbox::query()->where('intent_id', $intent->id)->lockForUpdate()->sole();
            if ($outbox->reservation_id !== $reservation->id || $outbox->terms_fingerprint !== $intent->terms_fingerprint
                || $outbox->dispatchable || $outbox->attempts !== 0 || $outbox->claimed_at !== null
                || $outbox->submission_started_at !== null || $outbox->provider_reference !== null) {
                throw new RuntimeException('dynamic_cancellation_requires_proof_of_no_submission');
            }
            if ($outbox->state === 'cancelled' && $reservation->state === 'released'
                && $reservation->release_entry_id !== null) {
                return $intent;
            }
            if ($outbox->state !== 'held' || $reservation->state !== 'held'
                || $reservation->release_entry_id !== null) {
                throw new RuntimeException('dynamic_cancellation_requires_proof_of_no_submission');
            }
            $wallet = Balance::query()->lockForUpdate()->findOrFail($intent->balance_id);
            $release = $this->ledger->release($intent, $reservation, $wallet, $context);
            if (! $reservation->forceFill(['state' => 'released', 'release_entry_id' => $release->id,
                'released_by' => $user->id, 'released_at' => now()])->save()
                || ! $outbox->forceFill(['state' => 'cancelled', 'cancelled_at' => now()])->save()) {
                throw new RuntimeException('dynamic_cancellation_not_persisted');
            }

            return $intent;
        }, 3);
    }

    private function assertLocalEligibility(LockedPayoutRecords $r): void
    {
        if ($r->user->status !== 'active' || ! in_array($r->user->kyc_status, ['verified', 'approved'], true)
            || $r->kyc->status !== 'approved' || $r->provider->code !== 'nium' || $r->provider->status !== 'active'
            || $r->account->status !== 'active' || $r->account->provider_status !== 'clear'
            || ! in_array($r->account->provider_sub_status, [null, ''], true)
            || ! in_array($r->account->compliance_status, ['clear', 'approved'], true)
            || ! in_array($r->account->rfi_status, ['clear', 'closed', 'not_required'], true)
            || ! in_array($r->account->odd_status, ['clear', 'not_required'], true)
            || $r->account->reconciliation_status !== 'reconciled' || $r->account->security_conflict_at !== null
            || $r->account->customer_id_verified_at === null || $r->account->wallet_id_verified_at === null
            || ! in_array($r->beneficiary->status, ['active', 'approved', 'verified'], true)
            || ! is_string($r->beneficiary->external_beneficiary_id) || $r->beneficiary->external_beneficiary_id === ''
            || ! is_string($r->wallet->external_account_id) || $r->wallet->external_account_id === ''
            || $r->wallet->external_account_id !== $r->account->external_account_id) {
            throw new RuntimeException('dynamic_customer_account_beneficiary_or_wallet_ineligible');
        }
        $screenings = AmlScreening::query()->where('user_id', $r->user->id)->whereNull('superseded_at')
            ->orderBy('id')->lockForUpdate()->get();
        if ($screenings->isEmpty() || $screenings->contains(fn ($screening): bool =>
            $screening->status !== 'completed' || $screening->compliance_decision !== 'clear'
            || $screening->completed_at === null || $screening->completed_at->isFuture()
            || (string) $screening->kyc_profile_id !== (string) $r->kyc->id)) {
            throw new RuntimeException('dynamic_compliance_hold_or_evidence_unavailable');
        }
        $matches = DB::table('aml_matches')->whereIn('aml_screening_id', $screenings->modelKeys())
            ->orderBy('id')->lockForUpdate()->get();
        if ($matches->contains(fn ($match): bool => $match->status !== 'cleared')) {
            throw new RuntimeException('dynamic_compliance_hold_or_evidence_unavailable');
        }
        if ($r->wallet->bank_account_id !== null) {
            $bank = BankAccount::query()->where('user_id', $r->user->id)->where('provider_id', $r->provider->id)
                ->lockForUpdate()->findOrFail($r->wallet->bank_account_id);
            if ($bank->status !== 'active' || $bank->currency !== $r->wallet->currency
                || $bank->external_account_id !== $r->wallet->external_account_id) {
                throw new RuntimeException('dynamic_customer_account_beneficiary_or_wallet_ineligible');
            }
        }
        $rfi = NiumRfiCase::query()->where('user_provider_account_id', $r->account->id)
            ->orderBy('id')->lockForUpdate()->get();
        if ($rfi->contains(fn ($case): bool => $case->status !== 'closed')) {
            throw new RuntimeException('dynamic_compliance_hold_or_evidence_unavailable');
        }
    }

    private function assertBindings(LockedPayoutRecords $r, DynamicPayoutEvidence $evidence): void
    {
        $context = $evidence->review->context;
        $c = $context->corridor;
        if ($context->providerId !== (string) $r->provider->id || $context->providerAccountId !== (string) $r->account->id
            || $context->beneficiaryId !== (string) $r->beneficiary->id
            || $context->externalBeneficiaryId !== $r->beneficiary->external_beneficiary_id
            || $context->beneficiary->scope->ownerUserId !== (string) $r->user->id
            || $c->customer !== $r->account->external_customer_id || $c->account !== $r->account->external_account_id
            || $c->fundingWallet !== $r->wallet->external_account_id || $c->sourceCurrency !== $r->wallet->currency
            || $c->destinationCurrency !== $r->beneficiary->currency || $c->destinationCountry !== $r->beneficiary->country_code
            || $c->payoutMethod !== $r->beneficiary->payout_method
            || $c->customerType !== match ($r->kyc->applicant_type) {
                'individual' => 'INDIVIDUAL', 'business' => 'CORPORATE', default => null,
            }
            || $c->beneficiaryType !== match ($r->beneficiary->beneficiary_type) {
                'individual' => 'INDIVIDUAL', 'business' => 'CORPORATE', default => null,
            }
            || ! hash_equals($context->receivingAccountFingerprint, $evidence->registeredReceivingAccountSha256)
            || ! hash_equals(DynamicReceivingAccountBinding::fingerprint($r->beneficiary), $evidence->registeredReceivingAccountSha256)) {
            throw new RuntimeException('dynamic_registered_account_or_wallet_binding_changed');
        }
    }

    private function assertConfirmationEnabled(): void
    {
        if (config('nium_dynamic_confirmation.enabled', false) !== true
            || config('nium_dynamic_quotes.quote_contract_enabled', false) !== true
            || config('nium_dynamic_quotes.confirmation_contract_enabled', false) !== true) {
            throw new RuntimeException('dynamic_durable_confirmation_disabled');
        }
        if (config('nium_dynamic_confirmation.automatic_dispatch_enabled', false) === true) {
            DynamicPayoutGate::assertDispatchEnabled();
        }
        $this->assertStorage();
    }

    private function assertStorage(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('dynamic_confirmation_requires_postgresql_row_locks');
        }
    }

    /** Caller holds the owner/intent/outbox locks. No network is permitted in evidence revalidation. */
    public function revalidateForExecution(NiumDynamicIntent $intent): void
    {
        OwnerFirst::current([$intent->user_id]);
        if (DB::transactionLevel() < 1 || $this->evidence === null) {
            throw new RuntimeException('dynamic_authoritative_evidence_source_unavailable');
        }
        $user = User::query()->lockForUpdate()->findOrFail($intent->user_id);
        $account = UserProviderAccount::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($intent->provider_account_id);
        $provider = IntegrationProvider::query()->lockForUpdate()->findOrFail($intent->provider_id);
        $beneficiary = Beneficiary::query()->where('user_id', $user->id)->where('provider_id', $provider->id)
            ->lockForUpdate()->findOrFail($intent->beneficiary_id);
        $wallet = Balance::query()->where('user_id', $user->id)->where('provider_id', $provider->id)
            ->lockForUpdate()->findOrFail($intent->balance_id);
        $kyc = KycProfile::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
        if ((string) $account->provider_id !== (string) $provider->id) {
            throw new RuntimeException('dynamic_provider_account_changed');
        }
        $records = new LockedPayoutRecords($user, $provider, $account, $beneficiary, $wallet, $kyc, $intent->terms_fingerprint, $intent);
        $this->assertLocalEligibility($records);
        DynamicPayoutGate::assertWalletClear((int) $provider->id, $intent->source_wallet, $intent->id);
        $at = now()->toDateTimeImmutable();
        $evidence = $this->evidence->revalidate($records, $at);
        $evidence->assertCurrent($at);
        $this->assertBindings($records, $evidence);
        $review = $evidence->review;
        $binding = $intent->consent_binding;
        $context = $review->context;
        if ($review->instruction === null || $review->finalizedPreparationBinding === null
            || $evidence->quoteConsumptionRule !== 'LOCAL_SINGLE_INTENT_PER_QUOTE'
            || ! is_string($evidence->versions['remittance_instruction'] ?? null)
            || trim($evidence->versions['remittance_instruction']) === ''
            || ($binding['actor'] ?? null) !== (string) $user->id
            || ($binding['financial_terms_fingerprint'] ?? null) !== $intent->terms_fingerprint
            || (string) $intent->consent_actor_id !== (string) $user->id
            || $intent->customer !== $context->corridor->customer
            || $intent->source_wallet !== $context->corridor->fundingWallet
            || $intent->source_currency !== $context->corridor->sourceCurrency
            || $intent->destination_currency !== $context->corridor->destinationCurrency
            || $intent->quote_id !== $review->quote->id || $intent->amount_mode !== $review->quote->request->mode->value
            || $intent->quote_expires_at->toDateTimeImmutable() != $review->quote->expiresAt
            || ! \Brick\Math\BigDecimal::of($intent->total_debit)->isEqualTo($review->terms->totalWalletDebit->amount->value)
            || ! hash_equals($intent->terms_fingerprint, $review->fingerprint())
            || ! hash_equals($intent->terms_fingerprint, hash('sha256', json_encode($intent->confirmed_terms, JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('dynamic_execution_consent_or_terms_changed');
        }
        $finalAt = now()->toDateTimeImmutable();
        $review->confirm((string) $user->id, $intent->terms_fingerprint, $finalAt);
        if ($evidence->validUntil <= $finalAt) {
            throw new RuntimeException('dynamic_execution_evidence_expired');
        }
        $balance = $wallet->raw_data['provider_available_balance'] ?? null;
        if (! is_string($balance) || \Brick\Math\BigDecimal::of(DynamicReservationLedger::storage($balance))
            ->isLessThan(DynamicReservationLedger::storage((string) $wallet->reserved_balance))) {
            throw new RuntimeException('dynamic_execution_provider_funds_unavailable');
        }
    }
}
