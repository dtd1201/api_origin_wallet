<?php

namespace App\Services\Nium;

use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** Controlled maker/checker workflow. Unknown charging terms never grant financial admission. */
final class NiumSgdLocalPayoutWorkflow
{
    public function __construct(
        private readonly NiumSgdLocalCugScope $scope,
        private readonly NiumPayoutFxLockService $locks,
        private readonly NiumPurposeCodeService $purposes,
        private readonly NiumSgdLocalPayoutGate $gate,
    ) {}

    public function prepare(User $user, array $input): Transfer
    {
        $beneficiary = $user->beneficiaries()->findOrFail($input['beneficiary_id']);
        $candidate = new Transfer([
            'user_id' => $user->id, 'provider_id' => $input['provider_id'],
            'beneficiary_id' => $beneficiary->id, 'transfer_type' => 'payout',
            'source_currency' => $input['source_currency'], 'target_currency' => $input['target_currency'],
            'source_amount' => (string) $input['source_amount'], 'purpose_code' => $input['purpose_code'],
        ]);
        $candidate->setRelation('user', $user)->setRelation('beneficiary', $beneficiary);
        $this->scope->assertTransfer($candidate);
        $this->assertPurpose($candidate);
        $this->assertPrincipalAvailable($candidate);
        $this->gate->assertCandidate($user, $candidate->provider, $beneficiary, 'SGD');
        if (NiumPayoutFxLockContract::decimal($input['expected_fee_amount']) !== '0' || $input['expected_fee_currency'] !== 'USD') {
            $this->invalid('The stated business expectation is a zero USD fee. Unexpected charges require a new review.');
        }
        $intent = $this->intent($candidate);

        return DB::transaction(function () use ($user, $candidate, $input, $intent): Transfer {
            // Serialize idempotency claims per customer; distinct client references are distinct payments.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = $user->transfers()->where('provider_id', $candidate->provider_id)
                ->where('client_reference', $input['client_reference'])->first();
            if ($existing !== null) {
                if (! NiumPayoutFxLockContract::sameBinding($existing->raw_data['sgd_local_workflow']['intent'] ?? null, $intent)) {
                    $this->invalid('This client reference already belongs to a different payment intent.');
                }

                return $existing;
            }

            return $user->transfers()->create([
                'provider_id' => $candidate->provider_id, 'beneficiary_id' => $candidate->beneficiary_id,
                'transfer_no' => 'TRF-'.Str::upper(Str::random(12)), 'client_reference' => $input['client_reference'],
                'transfer_type' => 'payout', 'source_currency' => $candidate->source_currency, 'target_currency' => $candidate->target_currency,
                'source_amount' => NiumSgdLocalCugScope::principal($candidate->source_amount), 'target_amount' => null,
                'fee_amount' => 0, 'fee_currency' => $candidate->source_currency,
                'purpose_code' => $candidate->purpose_code, 'status' => 'draft',
                'raw_data' => ['sgd_local_workflow' => [
                    'version' => 1, 'release' => NiumSgdLocalCugScope::RELEASE, 'state' => 'draft',
                    'maker_user_id' => $user->id, 'intent' => $intent,
                    'expected_fee' => ['amount' => '0', 'currency' => 'USD', 'basis' => 'user_expectation'],
                    'provider_fee_status' => 'unavailable',
                    'business_policy' => app(NiumPayoutFinancialControls::class)->businessPolicy($candidate),
                ]],
            ]);
        });
    }

    public function requestLock(Transfer $transfer, string $requestId): Transfer
    {
        $this->scope->assertTransfer($transfer);
        $this->assertIntent($transfer);
        $this->assertPurpose($transfer);
        $this->assertPrincipalAvailable($transfer);
        if (! Str::isUuid($requestId)) {
            $this->invalid('A valid explicit FX request UUID is required.');
        }
        if (! (bool) config('services.nium.payout_fx_enabled', false)) {
            $this->invalid('FX locking has not been enabled for the reviewed release.');
        }
        $claimed = DB::transaction(function () use ($transfer, $requestId): ?Transfer {
            $locked = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->scope->assertTransfer($locked);
            $this->assertIntent($locked);
            $historicalApprovals = array_filter(array_column($locked->raw_data['sgd_local_workflow']['review_history'] ?? [], 'approval_id'));
            if ($locked->status !== 'draft' || $locked->provider_operation_key !== null
                || $locked->approvals()->whereNotIn('id', $historicalApprovals)->exists()) {
                $this->invalid('Only an unapproved preparation draft can explicitly request a fresh lock.');
            }
            $workflow = $locked->raw_data['sgd_local_workflow'];
            if (($workflow['lock_request_id'] ?? null) === $requestId && ($workflow['state'] ?? null) === 'locked') {
                $this->assertCurrentLock($locked);

                return null; // Same completed request: return its result without another provider GET.
            }
            $locked->loadMissing('fxQuote');
            if (($workflow['state'] ?? null) === 'locked' && $locked->fxQuote?->expires_at?->isFuture()) {
                $this->invalid('The existing FX hold is still current. Review that hold instead of creating another.');
            }
            if (in_array($workflow['state'] ?? null, ['locking', 'lock_unknown'], true)) {
                $this->invalid('The FX request is pending or uncertain. Do not repeat it; request reconciliation.');
            }
            if (($workflow['lock_request_id'] ?? null) === $requestId) {
                $this->invalid('This FX request was already attempted. A new explicit review is required.');
            }
            $workflow['state'] = 'locking';
            $workflow['lock_request_id'] = $requestId;
            $workflow['lock_requested_at'] = now()->toISOString();
            $locked->update(['raw_data' => array_merge($locked->raw_data, ['sgd_local_workflow' => $workflow])]);

            return $locked;
        });
        if ($claimed === null) {
            return $transfer->fresh();
        }

        try {
            // Provider I/O occurs after the claim commits, never under a row lock.
            $quote = $this->locks->createSgdPreparationLock($claimed, $requestId);

            return DB::transaction(function () use ($claimed, $requestId, $quote): Transfer {
                $locked = Transfer::query()->lockForUpdate()->findOrFail($claimed->id);
                $this->scope->assertTransfer($locked);
                $this->assertIntent($locked);
                $workflow = $locked->raw_data['sgd_local_workflow'];
                if ($locked->status === 'draft' && ($workflow['state'] ?? null) === 'locked'
                    && ($workflow['lock_request_id'] ?? null) === $requestId
                    && (string) $locked->fxQuote?->quote_ref === (string) $quote->quote_ref) {
                    $this->assertCurrentLock($locked);

                    return $locked; // An explicit local capture recovery already attached this outcome.
                }
                if ($locked->status !== 'draft' || ($workflow['state'] ?? null) !== 'locking'
                    || ($workflow['lock_request_id'] ?? null) !== $requestId) {
                    $this->invalid('The payment changed while locking FX. Its provider evidence requires review.');
                }
                $locked->fx_quote_id = $quote->id;
                $locked->fx_rate = $quote->net_rate;
                NiumPayoutFxLockContract::assertMatchesTransfer($quote, $locked, $this->locks->context($locked->provider, $locked->user));
                $workflow['state'] = 'locked';
                $locked->raw_data = array_merge($locked->raw_data, ['sgd_local_workflow' => $workflow]);
                $locked->save();

                return $locked;
            });
        } catch (Throwable $exception) {
            DB::transaction(function () use ($transfer, $requestId): void {
                $locked = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
                $workflow = $locked->raw_data['sgd_local_workflow'] ?? [];
                if (($workflow['lock_request_id'] ?? null) === $requestId && ($workflow['state'] ?? null) === 'locking') {
                    // No automatic retry even if the provider received the GET before capture/attachment failed.
                    $workflow['state'] = 'lock_unknown';
                    $locked->update(['raw_data' => array_merge($locked->raw_data, ['sgd_local_workflow' => $workflow])]);
                }
            });
            throw $exception;
        }
    }

    public function requestApproval(Transfer $transfer, ?string $financialTermsToken = null): Transfer
    {
        $transfer->loadMissing(['user', 'provider', 'beneficiary']);
        $purposes = $this->purposes->supported($transfer->user);
        $this->gate->assertCandidate($transfer->user, $transfer->provider, $transfer->beneficiary, 'SGD');

        return DB::transaction(function () use ($transfer, $purposes, $financialTermsToken): Transfer {
            $locked = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->scope->assertTransfer($locked);
            $this->assertIntent($locked);
            $this->assertCurrentLock($locked);
            $terms = NiumSgdLocalPayoutGate::assertSubmissionAllowed($locked);
            if ($financialTermsToken !== null && ! hash_equals(self::financialTermsToken($terms), $financialTermsToken)) {
                $this->invalid('Provider charges changed. Refresh and explicitly review the current total debit.');
            }
            if (! collect($purposes)->contains('code', $locked->purpose_code)) {
                $this->invalid('Select a truthful supported NIUM payment purpose.');
            }
            if ($locked->status === 'approval_required') {
                $this->assertRequestedReview($locked);

                return $locked;
            }
            if ($locked->status !== 'draft' || $locked->provider_operation_key !== null) {
                $this->invalid('Only a preparation draft can request a new approval.');
            }
            $this->assertPrincipalAvailable($locked);
            $balance = Balance::query()->where('user_id', $locked->user_id)->where('provider_id', $locked->provider_id)
                ->where('currency', $locked->source_currency)->latest('as_of')->first();
            if ($balance === null || BigDecimal::of($balance->available_balance)->isLessThan($terms['reservation_amount'] ?? $terms['total_wallet_debit'])) {
                $this->invalid('Available funding must cover the principal and all confirmed provider charges.');
            }
            $raw = $locked->raw_data;
            $raw['sgd_local_workflow']['review_revision'] = Str::uuid()->toString();
            $locked->raw_data = $raw;
            $snapshot = app(NiumSgdLocalPayoutAuthorization::class)->reviewSnapshot($locked);
            $raw['sgd_local_workflow']['review'] = [
                'requested_by' => $locked->user_id, 'requested_at' => now()->toISOString(), 'snapshot' => $snapshot,
            ];
            $raw['sgd_local_workflow']['state'] = 'approval_required';
            $fields = ['status' => 'approval_required', 'raw_data' => $raw];
            if ($snapshot['financial_terms']['fee_amount'] !== null) {
                $fields += ['fee_amount' => $snapshot['financial_terms']['fee_amount'],
                    'fee_currency' => $snapshot['financial_terms']['fee_currency']];
            }
            $locked->update($fields);

            return $locked;
        });
    }

    public function assertRequestedReview(Transfer $transfer): array
    {
        $snapshot = app(NiumSgdLocalPayoutAuthorization::class)->reviewSnapshot($transfer);
        $review = $transfer->raw_data['sgd_local_workflow']['review'] ?? [];
        if ($transfer->status !== 'approval_required' || $transfer->provider_operation_key !== null
            || ($review['requested_by'] ?? null) !== $transfer->user_id
            || ! NiumPayoutFxLockContract::sameBinding($review['snapshot'] ?? null, $snapshot)) {
            $this->invalid('A matching current maker review is required before checker approval.');
        }

        return $snapshot;
    }

    /** Invalidate only an expired, undispatched review; preserve every historical approval. */
    public function restartExpiredReview(Transfer $transfer): Transfer
    {
        return DB::transaction(function () use ($transfer): Transfer {
            $locked = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->scope->assertTransfer($locked);
            $this->assertIntent($locked);
            if (! $this->canRestartReview($locked)) {
                $this->invalid('Only an expired review with no payment operation or reservation can restart.');
            }
            $raw = $locked->raw_data;
            $workflow = $raw['sgd_local_workflow'];
            $workflow['review_history'][] = [
                'review' => $workflow['review'] ?? null, 'approval_id' => $workflow['approval_id'] ?? null,
                'approval_snapshot' => $raw['sgd_local_approval'] ?? null, 'fx_quote_id' => $locked->fx_quote_id,
                'lock_request_id' => $workflow['lock_request_id'] ?? null,
                'invalidated_at' => now()->toISOString(), 'reason' => 'expired_fx_explicit_maker_review',
            ];
            foreach (['review', 'review_revision', 'approval_id', 'lock_request_id', 'lock_requested_at', 'recovery_review'] as $key) {
                unset($workflow[$key]);
            }
            $workflow['state'] = 'draft';
            unset($raw['sgd_local_approval']);
            $raw['sgd_local_workflow'] = $workflow;
            $locked->update(['status' => 'draft', 'fx_quote_id' => null, 'fx_rate' => null, 'raw_data' => $raw]);

            return $locked;
        });
    }

    private function canRestartReview(Transfer $transfer): bool
    {
        return in_array($transfer->status, ['draft', 'approval_required', 'approved'], true)
            && $transfer->provider_operation_key === null && ! filled($transfer->external_transfer_id)
            && ! in_array($transfer->raw_data['sgd_local_workflow']['state'] ?? null, ['locking', 'lock_unknown'], true)
            && $transfer->fxQuote !== null && $transfer->fxQuote->expires_at !== null
            && $transfer->fxQuote->expires_at->lessThanOrEqualTo(now())
            && ! isset($transfer->fxQuote->raw_data['consumed_at'])
            && ! LedgerEntry::query()->where('source_type', 'transfer')->where('source_id', (string) $transfer->id)->exists();
    }

    /** Persist a local review request, never acquire a hold or infer a provider outcome. */
    public function requestRecoveryReview(Transfer $transfer): Transfer
    {
        return DB::transaction(function () use ($transfer): Transfer {
            $locked = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->scope->assertTransfer($locked);
            $this->assertIntent($locked);
            $recovery = $this->recovery($locked);
            if ($recovery['recovery_kind'] === null) {
                $this->invalid('This operation does not require outcome recovery.');
            }
            $raw = $locked->raw_data;
            $identity = hash('sha256', json_encode([
                $recovery['recovery_kind'], $raw['sgd_local_workflow']['lock_request_id'] ?? null,
                $locked->provider_operation_key, $locked->fx_quote_id,
            ], JSON_THROW_ON_ERROR));
            if (($raw['sgd_local_workflow']['recovery_review']['identity'] ?? null) !== $identity) {
                $raw['sgd_local_workflow']['recovery_review'] = [
                    'identity' => $identity, 'kind' => $recovery['recovery_kind'],
                    'requested_at' => now()->toISOString(), 'requested_by' => $locked->user_id,
                    'operation_key' => $locked->provider_operation_key,
                    'lock_request_id' => $raw['sgd_local_workflow']['lock_request_id'] ?? null,
                    'status' => 'awaiting_authoritative_evidence',
                ];
                $locked->update(['raw_data' => $raw]);
            }

            return $locked;
        });
    }

    private function recovery(Transfer $transfer): array
    {
        $workflow = $transfer->raw_data['sgd_local_workflow'] ?? [];
        $kind = null;
        if (in_array($workflow['state'] ?? null, ['locking', 'lock_unknown'], true)) {
            $kind = 'fx_outcome_unknown';
        } elseif (in_array($transfer->status, ['submitting', 'submission_unknown'], true)
            && ! filled($transfer->external_transfer_id)) {
            $kind = 'provider_reference_missing';
        } elseif (in_array($transfer->status, ['approval_required', 'approved'], true)
            && ($transfer->fxQuote?->expires_at === null || $transfer->fxQuote->expires_at->lessThanOrEqualTo(now()))) {
            $kind = 'fx_expired_review_required';
        }
        $review = $workflow['recovery_review'] ?? null;

        return [
            'recovery_kind' => $kind,
            'can_request_recovery_review' => $kind !== null,
            'can_recover_captured_outcome' => in_array($kind, ['fx_outcome_unknown', 'provider_reference_missing'], true),
            'recovery_review_requested_at' => ($review['kind'] ?? null) === $kind ? ($review['requested_at'] ?? null) : null,
            'can_reconcile_status' => filled($transfer->external_transfer_id)
                && in_array($transfer->status, ['submitting', 'submission_unknown', 'pending', 'processing', 'completed', 'failed', 'cancelled', 'rejected'], true),
        ];
    }

    public function presentation(Transfer $transfer): array
    {
        $workflow = $transfer->raw_data['sgd_local_workflow'] ?? [];
        $active = false;
        try {
            $this->scope->assertTransfer($transfer);
            $this->assertIntent($transfer);
            $this->assertCurrentLock($transfer);
            $active = in_array($workflow['state'] ?? null, ['locked', 'approval_required', 'approved'], true);
        } catch (RuntimeException|ValidationException) {
            // Presentation is read-only and cannot refresh missing or expired evidence.
        }

        $businessPolicy = null;
        try {
            $businessPolicy = app(NiumPayoutFinancialControls::class)->businessPolicy($transfer);
        } catch (RuntimeException) {
            // An invalid principal cannot become an admissible funding policy.
        }

        $terms = null;
        $blockers = [];
        try {
            $terms = NiumSgdLocalPayoutGate::assertSubmissionAllowed($transfer);
        } catch (RuntimeException) {
            $blockers[] = NiumSgdLocalPayoutGate::BLOCK_REASON;
        }
        $checkerAllowed = false;
        if ($active && $terms !== null && in_array($transfer->status, ['approval_required', 'approved'], true)) {
            try {
                if ($transfer->status === 'approved') {
                    app(NiumSgdLocalPayoutAuthorization::class)->assertApproved($transfer);
                } else {
                    $this->assertRequestedReview($transfer);
                }
                $checkerAllowed = true;
            } catch (RuntimeException|ValidationException) {
                $blockers[] = 'The maker review changed. A new explicit review is required.';
            }
        }

        $uncapped = ($terms['policy'] ?? null) === NiumPayoutPilotPolicy::UNCAPPED_POLICY
            || ($transfer->raw_data['sgd_local_approval']['financial_terms']['policy'] ?? null) === NiumPayoutPilotPolicy::UNCAPPED_POLICY;
        if ($uncapped && $businessPolicy !== null) {
            $businessPolicy['expected_fee_amount'] = null;
        }

        return [
            'state' => $workflow['state'] ?? 'unavailable', 'fx_lock_active' => $active,
            'fx_lock_enabled' => (bool) config('services.nium.payout_fx_enabled', false),
            'expected_fee' => ['amount' => $uncapped ? null : '0', 'currency' => 'USD',
                'basis' => $uncapped ? 'unknown_fees_explicitly_accepted' : 'user_expectation'],
            'business_policy' => $businessPolicy,
            'provisional_pricing' => app(NiumProvisionalPayoutPricing::class)->presentation($transfer),
            ...$this->recovery($transfer), 'can_restart_review' => $this->canRestartReview($transfer),
            'provider_fee_status' => $terms === null || ($terms['fee_status'] ?? null) === 'unverified' ? 'unavailable' : 'confirmed',
            'pilot_policy' => ($terms['funding_basis'] ?? null) === 'principal_only_pilot'
                ? ['authorization_id' => $terms['authorization_id'], 'reservation_amount' => $terms['reservation_amount'],
                    'policy' => $uncapped ? NiumPayoutPilotPolicy::UNCAPPED_POLICY : 'funded_reserve',
                    'reserve_currency' => 'USD', 'reserve_amount' => $terms['pilot_reserve']['amount'] ?? null,
                    'charge_ceiling' => $terms['pilot_reserve']['charge_ceiling'] ?? null,
                    'risk_acknowledgement' => $terms['risk_acknowledgement'] ?? null,
                    'fee_status' => 'unverified', 'total_wallet_debit' => null] : null,
            'financial_terms_token' => $terms === null ? null : self::financialTermsToken($terms),
            'can_refresh_pricing' => ! $uncapped && $transfer->status === 'draft' && $transfer->provider_operation_key === null,

            'approval_request_allowed' => $active && $terms !== null && $transfer->status === 'draft',
            'checker_approval_allowed' => $checkerAllowed,
            // The existing checker approval endpoint owns dispatch. No second customer action.
            'dispatch_allowed' => false, 'dispatch_owner' => 'checker_approval',
            'blockers' => $blockers,
        ];
    }

    public static function financialTermsToken(array $terms): string
    {
        return hash('sha256', json_encode($terms, JSON_THROW_ON_ERROR));
    }

    public function assertIntent(Transfer $transfer): void
    {
        if (($transfer->raw_data['sgd_local_workflow']['release'] ?? null) !== NiumSgdLocalCugScope::RELEASE
            || ! NiumPayoutFxLockContract::sameBinding($transfer->raw_data['sgd_local_workflow']['intent'] ?? null, $this->intent($transfer))) {
            $this->invalid('Payment details or verified account binding changed. Cancel this preparation and request a new review.');
        }
    }

    private function intent(Transfer $transfer): array
    {
        return [
            'maker_user_id' => $transfer->user_id, 'provider_id' => $transfer->provider_id,
            'beneficiary_id' => $transfer->beneficiary_id,
            'external_beneficiary_id' => $transfer->beneficiary->external_beneficiary_id,
            'provider_context' => $this->locks->context($transfer->provider, $transfer->user),
            'source_currency' => $transfer->source_currency, 'target_currency' => $transfer->target_currency,
            'source_amount' => NiumSgdLocalCugScope::principal($transfer->source_amount),
            'purpose_code' => $transfer->purpose_code, 'expected_fee_amount' => '0', 'expected_fee_currency' => 'USD',
        ];
    }

    private function assertPurpose(Transfer $transfer): void
    {
        if (! filled($transfer->purpose_code) || ! collect($this->purposes->supported($transfer->user))->contains('code', $transfer->purpose_code)) {
            $this->invalid('Select a truthful supported NIUM payment purpose.');
        }
    }

    private function assertCurrentLock(Transfer $transfer): void
    {
        $transfer->loadMissing('fxQuote');
        if ($transfer->fxQuote === null) {
            $this->invalid('Explicitly request a fresh FX lock before review.');
        }
        NiumPayoutFxLockContract::assertMatchesTransfer($transfer->fxQuote, $transfer, $this->locks->context($transfer->provider, $transfer->user));
    }

    private function assertPrincipalAvailable(Transfer $transfer): void
    {
        $balance = Balance::query()->where('user_id', $transfer->user_id)->where('provider_id', $transfer->provider_id)
            ->where('currency', $transfer->source_currency)->latest('as_of')->first();
        if ($balance === null || BigDecimal::of($balance->available_balance)->isLessThan(NiumSgdLocalCugScope::principal($transfer->source_amount))) {
            $this->invalid('A synced funding wallet covering the source principal after reservations is required.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['payout' => $message]);
    }
}
