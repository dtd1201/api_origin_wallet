<?php

namespace App\Services\Nium;

use App\Models\Balance;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Cache;
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

    public function prepare(User $user, array $input, bool $refreshFunding = false, ?NiumReviewDiagnostics $diagnostics = null): Transfer
    {
        $diagnostics?->stage('prepare_admission');
        $admissionLease = app(NiumSgdAdmissionFence::class)->acquire();
        $diagnostics?->stage('prepare_beneficiary');
        $beneficiary = $user->beneficiaries()->findOrFail($input['beneficiary_id']);
        $candidate = new Transfer([
            'user_id' => $user->id, 'provider_id' => (int) $input['provider_id'],
            'beneficiary_id' => $beneficiary->id, 'transfer_type' => 'payout',
            'source_currency' => $input['source_currency'], 'target_currency' => $input['target_currency'],
            'source_amount' => (string) $input['source_amount'], 'purpose_code' => $input['purpose_code'],
        ]);
        $candidate->setRelation('user', $user)->setRelation('beneficiary', $beneficiary);
        $diagnostics?->stage('prepare_scope');
        $this->scope->assertTransfer($candidate);
        $diagnostics?->stage('prepare_purpose');
        $this->assertPurpose($candidate);
        if ($refreshFunding) {
            $this->prepareFunding($candidate, $diagnostics);
        }
        $diagnostics?->stage('prepare_principal');
        $this->assertPrincipalAvailable($candidate);
        $diagnostics?->stage('prepare_candidate_gate');
        $this->gate->assertCandidate($user, $candidate->provider, $beneficiary, 'SGD');
        $diagnostics?->stage('prepare_fee_expectation');
        if (NiumPayoutFxLockContract::decimal($input['expected_fee_amount']) !== '0' || $input['expected_fee_currency'] !== 'USD') {
            $this->invalid('The stated business expectation is a zero USD fee. Unexpected charges require a new review.');
        }
        $diagnostics?->stage('prepare_intent');
        $intent = $this->intent($candidate);

        $diagnostics?->stage('prepare_persistence');
        return DB::transaction(function () use ($user, $candidate, $input, $intent): Transfer {
            // Serialize idempotency claims per customer; distinct client references are distinct payments.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = $user->transfers()->where('provider_id', $candidate->provider_id)
                ->where('client_reference', $input['client_reference'])->first();
            if ($existing !== null) {
                if (! NiumPayoutFxLockContract::sameBinding($existing->raw_data['sgd_local_workflow']['intent'] ?? null, $intent)
                    || ($existing->reference_text ?? '') !== ($input['reference_text'] ?? '')) {
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
                'purpose_code' => $candidate->purpose_code, 'reference_text' => $input['reference_text'] ?? null, 'status' => 'draft',
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

    /** Prepare a review only: never authorize, approve, reserve funds or dispatch. */
    public function prepareReview(User $customer, array $input, ?NiumReviewDiagnostics $diagnostics = null): Transfer
    {
        $diagnostics?->stage('workflow_admission');
        $admissionLease = app(NiumSgdAdmissionFence::class)->acquire();
        if (isset($input['transfer_id'])) {
            $diagnostics?->stage('workflow_saved_instruction');
            $transfer = $customer->transfers()->findOrFail($input['transfer_id']);
            $this->scope->assertTransfer($transfer);
            $this->assertIntent($transfer);
            if ($transfer->provider_id !== (int) $input['provider_id']
                || $transfer->beneficiary_id !== (int) $input['beneficiary_id']
                || ! BigDecimal::of($transfer->source_amount)->isEqualTo($input['source_amount'])
                || $transfer->purpose_code !== $input['purpose_code']
                || ($transfer->reference_text ?? '') !== ($input['reference_text'] ?? '')) {
                $this->invalid('The saved payment has different details. Reopen its saved details before reviewing.');
            }
        } else {
            $transfer = $this->prepare($customer, $input, refreshFunding: true, diagnostics: $diagnostics);
        }
        $diagnostics?->stage('workflow_purpose');
        $this->assertPurpose($transfer);
        $diagnostics?->stage('workflow_candidate_gate');
        $this->gate->assertCandidate($customer, $transfer->provider, $transfer->beneficiary, 'SGD');
        $this->prepareFunding($transfer, $diagnostics);
        $diagnostics?->stage('workflow_review_state');
        if ($this->canRestartReview($transfer)) {
            $transfer = $this->restartExpiredReview($transfer);
        } elseif ($this->canRefreshTermsReview($transfer)) {
            $transfer = $this->resetReview($transfer, false);
        }
        if ($transfer->status !== 'draft' || $transfer->provider_operation_key !== null) {
            return $transfer; // A retry cannot mutate a submitted/approved payment.
        }
        $state = $transfer->raw_data['sgd_local_workflow']['state'] ?? null;
        if (in_array($state, ['locking', 'lock_unknown'], true)) {
            $diagnostics?->fxOutcome($state);
            $this->invalid('The FX request is pending or uncertain. Refresh or reconcile its status before continuing.');
        }
        if ($transfer->fx_quote_id === null && $state === 'draft') {
            $transfer = $this->requestLock($transfer, $input['preparation_id'], $diagnostics);
        }
        $diagnostics?->stage('workflow_current_lock');
        $this->assertCurrentLock($transfer);
        $diagnostics?->stage('workflow_submission_terms');
        NiumSgdLocalPayoutGate::assertSubmissionAllowed($transfer);

        return $transfer;
    }

    public function requestLock(Transfer $transfer, string $requestId, ?NiumReviewDiagnostics $diagnostics = null): Transfer
    {
        $diagnostics?->stage('fx_lock_preflight');
        $admissionLease = app(NiumSgdAdmissionFence::class)->acquire();
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
        $diagnostics?->stage('fx_lock_claim');
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
            if (isset($workflow['rejected_quote_recoveries'][$requestId])) {
                $this->invalid('This FX request was rejected and retired. A new explicit review is required.');
            }
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
            $diagnostics?->stage('fx_lock_request');
            $diagnostics?->fxOutcome('locking');
            $quote = $this->locks->createSgdPreparationLock($claimed, $requestId);

            $diagnostics?->stage('fx_lock_attachment');
            $result = DB::transaction(function () use ($claimed, $requestId, $quote): Transfer {
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
                NiumPayoutQuoteContract::assertMatchesTransfer($quote, $locked, $this->locks->context($locked->provider, $locked->user));
                $workflow['state'] = 'locked';
                $locked->raw_data = array_merge($locked->raw_data, ['sgd_local_workflow' => $workflow]);
                $locked->save();

                return $locked;
            });
            $diagnostics?->fxOutcome('locked');

            return $result;
        } catch (Throwable $exception) {
            $diagnostics?->fxOutcome('uncertain');
            try {
                $unknownRecorded = DB::transaction(function () use ($transfer, $requestId): bool {
                    $locked = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
                    $workflow = $locked->raw_data['sgd_local_workflow'] ?? [];
                    if (($workflow['lock_request_id'] ?? null) === $requestId && ($workflow['state'] ?? null) === 'locking') {
                        // No automatic retry even if the provider received the GET before capture/attachment failed.
                        $workflow['state'] = 'lock_unknown';
                        $locked->update(['raw_data' => array_merge($locked->raw_data, ['sgd_local_workflow' => $workflow])]);
                    }

                    return ($workflow['state'] ?? null) === 'lock_unknown';
                });
                if ($unknownRecorded) {
                    $diagnostics?->fxOutcome('lock_unknown');
                }
            } catch (Throwable $recordingException) {
                $diagnostics?->cause($exception);
                $diagnostics?->stage('fx_lock_outcome_persistence');
                throw $recordingException;
            }
            throw $exception;
        }
    }

    /** Authenticated Submit records acceptance and enters the existing maker workflow atomically. */
    public function submit(Transfer $transfer, User $customer, string $submissionToken, bool $acceptUncappedCharges): Transfer
    {
        $admissionLease = app(NiumSgdAdmissionFence::class)->acquire();
        $transfer->loadMissing(['user', 'provider', 'beneficiary']);
        $purposes = $this->purposes->supported($transfer->user);
        $this->gate->assertCandidate($transfer->user, $transfer->provider, $transfer->beneficiary, 'SGD');

        return DB::transaction(function () use ($transfer, $customer, $submissionToken, $acceptUncappedCharges, $purposes): Transfer {
            $locked = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($customer->id !== $locked->user_id || $customer->status !== 'active') {
                $this->invalid('Only the active authenticated customer can submit this instruction.');
            }
            $instruction = app(NiumSgdLocalPayoutAuthorization::class)->customerInstruction($locked);
            $this->assertFunding($locked);
            if (! hash_equals(self::financialTermsToken($instruction), $submissionToken)) {
                $this->invalid('Payment or financial terms changed. Review the current instruction before submitting.');
            }
            if ($acceptUncappedCharges) {
                $this->invalid('Uncapped charges are not authorized by the standard SGD contract.');
            }
            if ($locked->status === 'approval_required') {
                $this->assertRequestedReview($locked);

                return $locked; // Same Submit never replaces the original receipt or dispatches.
            }
            if ($locked->status !== 'draft' || $locked->provider_operation_key !== null
                || filled($locked->external_transfer_id) || filled($locked->external_payment_id)) {
                $this->invalid('Only an undispatched draft can be submitted for approval.');
            }
            $raw = $locked->raw_data;
            $raw['sgd_local_workflow']['customer_authorization'] = [
                'accepted_by' => $customer->id, 'accepted_at' => now()->toISOString(),
                'basis' => 'authenticated_customer_submit', 'instruction' => $instruction,
                'payment_terms_accepted' => true, 'purpose_confirmed' => true,
                'submission_token' => $submissionToken,
            ];
            $locked->update(['raw_data' => $raw]);

            return $this->requestApproval($locked, purposes: $purposes);
        });
    }

    public function assertCustomerAuthorization(Transfer $transfer): void
    {
        $receipt = $transfer->raw_data['sgd_local_workflow']['customer_authorization'] ?? [];
        $instruction = app(NiumSgdLocalPayoutAuthorization::class)->customerInstruction($transfer);
        if (($receipt['accepted_by'] ?? null) !== $transfer->user_id
            || ($receipt['basis'] ?? null) !== 'authenticated_customer_submit'
            || ($receipt['payment_terms_accepted'] ?? null) !== true || ($receipt['purpose_confirmed'] ?? null) !== true
            || ! is_string($receipt['accepted_at'] ?? null)
            || ! NiumPayoutFxLockContract::sameBinding($receipt['instruction'] ?? null, $instruction)
            || ($receipt['submission_token'] ?? null) !== self::financialTermsToken($instruction)) {
            $this->invalid('Authenticated customer acceptance of this exact payment and current financial terms is required.');
        }
    }

    public function requestApproval(Transfer $transfer, ?string $financialTermsToken = null, ?array $purposes = null): Transfer
    {
        $admissionLease = app(NiumSgdAdmissionFence::class)->acquire();
        $transfer->loadMissing(['user', 'provider', 'beneficiary']);
        if ($purposes === null) {
            $purposes = $this->purposes->supported($transfer->user);
            $this->gate->assertCandidate($transfer->user, $transfer->provider, $transfer->beneficiary, 'SGD');
        }

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
            // Use the same exact wallet, freshness and reservation checks as Review/dispatch.
            $this->assertFunding($locked);
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
            || filled($transfer->external_transfer_id) || filled($transfer->external_payment_id)
            || ($review['requested_by'] ?? null) !== $transfer->user_id
            || ! NiumPayoutFxLockContract::sameBinding($review['snapshot'] ?? null, $snapshot)) {
            $this->invalid('A matching current maker review is required before checker approval.');
        }

        return $snapshot;
    }

    /** Invalidate only an expired, undispatched review; preserve every historical approval. */
    public function restartExpiredReview(Transfer $transfer): Transfer
    {
        $admissionLease = app(NiumSgdAdmissionFence::class)->acquire();

        return $this->resetReview($transfer, true);
    }

    private function resetReview(Transfer $transfer, bool $replaceFx): Transfer
    {
        return DB::transaction(function () use ($transfer, $replaceFx): Transfer {
            $locked = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->scope->assertTransfer($locked);
            $this->assertIntent($locked);
            if (! ($replaceFx ? $this->canRestartReview($locked) : $this->canRefreshTermsReview($locked))) {
                $this->invalid('Only an invalidated review with no payment operation or reservation can restart.');
            }
            $raw = $locked->raw_data;
            $workflow = $raw['sgd_local_workflow'];
            $workflow['review_history'][] = [
                'review' => $workflow['review'] ?? null, 'approval_id' => $workflow['approval_id'] ?? null,
                'customer_authorization' => $workflow['customer_authorization'] ?? null,
                'approval_snapshot' => $raw['sgd_local_approval'] ?? null, 'fx_quote_id' => $locked->fx_quote_id,
                'lock_request_id' => $workflow['lock_request_id'] ?? null,
                'invalidated_at' => now()->toISOString(),
                'reason' => $replaceFx ? 'expired_fx_explicit_maker_review' : 'changed_terms_customer_review',
            ];
            $invalidate = ['review', 'review_revision', 'customer_authorization', 'approval_id', 'recovery_review'];
            if ($replaceFx) {
                $invalidate = [...$invalidate, 'lock_request_id', 'lock_requested_at'];
            }
            foreach ($invalidate as $key) {
                unset($workflow[$key]);
            }
            $workflow['state'] = $replaceFx ? 'draft' : 'locked';
            unset($raw['sgd_local_approval']);
            $raw['sgd_local_workflow'] = $workflow;
            $locked->update(['status' => 'draft', 'raw_data' => $raw]
                + ($replaceFx ? ['fx_quote_id' => null, 'fx_rate' => null] : []));

            return $locked;
        });
    }

    private function canRestartReview(Transfer $transfer): bool
    {
        return $this->canResetUndispatchedReview($transfer)
            && $transfer->fxQuote->expires_at->lessThanOrEqualTo(now());
    }

    private function canRefreshTermsReview(Transfer $transfer): bool
    {
        if (! in_array($transfer->status, ['approval_required', 'approved'], true)
            || ! $this->canResetUndispatchedReview($transfer) || ! $transfer->fxQuote->expires_at->isFuture()) {
            return false;
        }
        try {
            $this->assertCustomerAuthorization($transfer);

            return false;
        } catch (RuntimeException|ValidationException) {
            return true;
        }
    }

    private function canResetUndispatchedReview(Transfer $transfer): bool
    {
        return ($transfer->raw_data['sgd_local_workflow']['version'] ?? null) === 1
            && ($transfer->raw_data['sgd_local_workflow']['release'] ?? null) === NiumSgdLocalCugScope::RELEASE
            && in_array($transfer->status, ['draft', 'approval_required', 'approved'], true)
            && $transfer->provider_operation_key === null && ! filled($transfer->external_transfer_id)
            && ! filled($transfer->external_payment_id)
            && ! in_array($transfer->raw_data['sgd_local_workflow']['state'] ?? null, ['locking', 'lock_unknown'], true)
            && $transfer->fxQuote !== null && $transfer->fxQuote->expires_at !== null
            && ! isset($transfer->fxQuote->raw_data['consumed_at'])
            && ! LedgerEntry::query()->where('source_type', 'transfer')->where('source_id', (string) $transfer->id)->exists();
    }

    public function customerStatus(Transfer $transfer): array
    {
        $state = $transfer->raw_data['sgd_local_workflow']['state'] ?? 'unavailable';

        return [
            'state' => $state,
            'dispatch_allowed' => false,
            'dispatch_owner' => 'checker_approval',
            'customer_accepted_at' => $transfer->raw_data['sgd_local_workflow']['customer_authorization']['accepted_at'] ?? null,
            'recovery_required' => in_array($state, ['locking', 'lock_unknown'], true)
                || in_array($transfer->status, ['submitting', 'submission_unknown'], true),
        ];
    }

    /** Read-only standard projection. Admission and dispatch never follow from presentation. */
    public function customerPresentation(Transfer $transfer): array
    {
        $internal = $this->presentation($transfer);

        return [...$this->customerStatus($transfer),
            ...array_intersect_key($internal, array_flip([
                'fx_lock_active', 'fx_lock_enabled', 'expected_fee', 'provider_fee_status',
                'can_refresh_review', 'can_reconcile_status', 'blockers',
            ]))];
    }

    public function presentation(Transfer $transfer): array
    {
        $workflow = $transfer->raw_data['sgd_local_workflow'] ?? [];
        $instruction = null;
        $active = false;
        $admissionOpen = false;
        $checkerAllowed = false;
        $blockers = [];
        try {
            app(NiumSgdAdmissionFence::class)->assertOpen();
            $admissionOpen = true;
            $this->scope->assertTransfer($transfer);
            $this->assertIntent($transfer);
            $this->assertCurrentLock($transfer);
            $active = true;
            $instruction = app(NiumSgdLocalPayoutAuthorization::class)->customerInstruction($transfer);
            $this->assertFunding($transfer);
            if ($transfer->status === 'approval_required') {
                $this->assertRequestedReview($transfer);
                $checkerAllowed = true;
            } elseif ($transfer->status === 'approved') {
                app(NiumSgdLocalPayoutAuthorization::class)->assertApproved($transfer);
                $checkerAllowed = true; // Approval replay only; dispatch stays held.
            } else {
                $blockers[] = 'Customer Submit is required before Admin approval.';
            }
        } catch (RuntimeException|ValidationException) {
            $blockers[] = 'Current admission, payment, FX, funding and customer authorization evidence is required.';
        }

        return [...$this->customerStatus($transfer),
            'fx_lock_active' => $active,
            'fx_lock_enabled' => $admissionOpen && (bool) config('services.nium.payout_fx_enabled', false),
            'expected_fee' => ['amount' => '0', 'currency' => 'USD', 'basis' => 'user_expectation'],
            // Existing projection vocabulary; the separate basis identifies business confirmation.
            'provider_fee_status' => $instruction === null ? 'unavailable' : 'confirmed',
            'financial_authorization_basis' => $instruction['financial_terms']['admission_basis'] ?? null,
            'customer_instruction' => $instruction,
            'submission_token' => $instruction === null ? null : self::financialTermsToken($instruction),
            'customer_authorization' => $workflow['customer_authorization'] ?? null,
            'checker_approval_allowed' => $checkerAllowed,
            'can_refresh_review' => $admissionOpen && ($this->canRestartReview($transfer) || $this->canRefreshTermsReview($transfer)),
            'can_reconcile_status' => filled($transfer->external_transfer_id)
                && in_array($transfer->status, ['pending', 'completed', 'failed', 'submission_unknown'], true),
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

    /** Only authenticated review preparation may refresh a stale source snapshot. */
    private function prepareFunding(Transfer $transfer, ?NiumReviewDiagnostics $diagnostics = null): void
    {
        $diagnostics?->stage('funding_source_balance');
        $balance = $this->sourceBalance($transfer);
        $diagnostics?->snapshot($balance);
        $asOf = $this->fundingAsOf($balance);
        if ($asOf !== null && $asOf->greaterThan(now()->subMinutes(5))) {
            $diagnostics?->refresh('not_required');
            $this->assertFunding($transfer, $diagnostics); // Future timestamps and insufficient current funds fail closed.

            return;
        }
        if (! config('wallet.ledger.enabled', true)) {
            $diagnostics?->stage('funding_ledger_disabled');
            $this->invalid('Source-wallet balance verification requires the wallet ledger.');
        }
        $diagnostics?->refresh('required');
        $diagnostics?->stage('funding_refresh_lock');
        // Serialize refreshes across transfers using the same verified wallet. No row lock during HTTP.
        $key = hash('sha256', json_encode([$balance->user_id, $balance->provider_id, $balance->external_account_id, $balance->currency], JSON_THROW_ON_ERROR));
        $lock = Cache::lock('nium:payout-source-balance:'.$key, max(60, 2 * (int) config('services.nium.timeout', 30) + 30));
        if (! $lock->get()) {
            $diagnostics?->refresh('contended');
            $this->invalid('The source-wallet balance is being refreshed. Please retry Review shortly. No payment was submitted.');
        }
        try {
            $diagnostics?->stage('funding_source_balance');
            $balance = $this->sourceBalance($transfer);
            $asOf = $this->fundingAsOf($balance);
            if ($asOf === null || $asOf->lessThanOrEqualTo(now()->subMinutes(5))) {
                $diagnostics?->stage('funding_data_sync_capability');
                $transfer->provider->assertSupportsCapability('data_sync');
                $diagnostics?->refresh('attempting');
                $diagnostics?->stage('funding_provider_sync');
                app(NiumDataSyncService::class)->syncBalances($transfer->provider, $transfer->user);
            }
            $diagnostics?->refresh('completed');
            $diagnostics?->stage('funding_revalidate_scope');
            $this->scope->assertTransfer($transfer);
            if ($transfer->exists) {
                $diagnostics?->stage('funding_revalidate_intent');
                $this->assertIntent($transfer);
            }
            $this->assertFunding($transfer, $diagnostics);
        } catch (Throwable $exception) {
            $diagnostics?->cause($exception);
            throw ValidationException::withMessages(['payout' => 'The source-wallet balance could not be verified. Please retry Review shortly; no payment was submitted.']);
        } finally {
            try {
                $lock->release();
            } catch (Throwable $exception) {
                $diagnostics?->stage('funding_refresh_release');
                throw $exception;
            }
        }
    }

    private function sourceBalance(Transfer $transfer): Balance
    {
        $context = $this->locks->context($transfer->provider, $transfer->user);
        $balances = Balance::query()->where('user_id', $transfer->user_id)->where('provider_id', $transfer->provider_id)
            ->where('external_account_id', $context['wallet'])->whereNull('bank_account_id')
            ->where('currency', $transfer->source_currency)->get();
        if ($balances->count() !== 1) {
            $this->invalid('A unique source-wallet balance belonging to this customer and verified provider wallet is required.');
        }

        return $balances->sole();
    }

    /** The exact source wallet must still have a recent snapshot and sufficient owned funding. */
    public function assertFunding(Transfer $transfer, ?NiumReviewDiagnostics $diagnostics = null): void
    {
        $diagnostics?->stage('funding_source_balance');
        $balance = $this->sourceBalance($transfer);
        $diagnostics?->stage('funding_reservation');
        $hold = LedgerEntry::query()->where('reference', "transfer:{$transfer->id}:hold")->first();
        if ($hold !== null && ($hold->balance_id !== $balance->id
            || ($hold->raw_data['operation_key'] ?? null) !== $transfer->provider_operation_key
            || $hold->entry_type !== 'hold' || $hold->status !== 'posted'
            || ! BigDecimal::of($hold->amount)->isEqualTo(BigDecimal::of($transfer->source_amount)->negated()))) {
            $this->invalid('The source-wallet reservation must belong to this payment operation.');
        }
        $amount = $hold === null ? $balance->available_balance : $balance->reserved_balance;
        // Retain main's conservative provider-snapshot coverage without changing reservation logic.
        if ($hold === null && isset($balance->raw_data['provider_available_balance'])) {
            $providerAvailable = BigDecimal::of($balance->raw_data['provider_available_balance'])->minus($balance->reserved_balance);
            if ($providerAvailable->isLessThan($amount)) {
                $amount = (string) $providerAvailable;
            }
        }
        $asOf = $this->fundingAsOf($balance);
        if (! config('wallet.ledger.enabled', true) || $asOf === null || $asOf->isFuture()
            || $asOf->lessThanOrEqualTo(now()->subMinutes(5))
            || BigDecimal::of($amount)->isLessThan($transfer->source_amount)) {
            $diagnostics?->stage(match (true) {
                ! config('wallet.ledger.enabled', true) => 'funding_ledger_disabled',
                $asOf === null => 'funding_snapshot_missing',
                $asOf->isFuture() => 'funding_snapshot_future',
                $asOf->lessThanOrEqualTo(now()->subMinutes(5)) => 'funding_snapshot_stale',
                $hold === null => 'funding_insufficient_available',
                default => 'funding_insufficient_reserved',
            });
            $this->invalid('A current verified source-wallet balance covering the principal is required.');
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

    private function fundingAsOf(Balance $balance): ?\Illuminate\Support\Carbon
    {
        $value = $balance->raw_data['provider_balance_as_of'] ?? $balance->as_of;
        try {
            return $value === null ? null : \Illuminate\Support\Carbon::parse($value);
        } catch (Throwable) {
            $this->invalid('A valid provider source-wallet snapshot time is required.');
        }
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
        NiumPayoutQuoteContract::assertMatchesTransfer($transfer->fxQuote, $transfer, $this->locks->context($transfer->provider, $transfer->user));
    }

    private function assertPrincipalAvailable(Transfer $transfer): void
    {
        $context = $this->locks->context($transfer->provider, $transfer->user);
        $balance = Balance::query()->where('user_id', $transfer->user_id)->where('provider_id', $transfer->provider_id)
            ->where('external_account_id', $context['wallet'])->whereNull('bank_account_id')
            ->where('currency', $transfer->source_currency)->first();
        if ($balance === null || BigDecimal::of($balance->available_balance)->isLessThan(NiumSgdLocalCugScope::principal($transfer->source_amount))) {
            $this->invalid('A synced funding wallet covering the source principal after reservations is required.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['payout' => $message]);
    }
}
