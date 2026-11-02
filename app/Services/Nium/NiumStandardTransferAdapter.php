<?php

namespace App\Services\Nium;

use App\Models\Transfer;
use App\Models\User;
use App\Services\Transfers\TransferEligibilityService;
use App\Support\PrimaryProvider;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** Standard API translation only; Production's durable workflow owns preparation and consent. */
final class NiumStandardTransferAdapter
{
    public function __construct(
        private readonly NiumSgdLocalPayoutWorkflow $workflow,
        private readonly NiumSgdLocalPayoutAuthorization $authorization,
        private readonly NiumPayoutFinancialControls $financial,
        private readonly NiumSgdLocalCugScope $scope,
        private readonly TransferEligibilityService $eligibility,
        private readonly NiumSgdLocalPayoutAmounts $amounts,
    ) {}

    public function review(User $customer, array $input): array
    {
        $admissionLease = app(NiumSgdAdmissionFence::class)->acquire();
        abort_unless($customer->status === 'active', 403);
        $provider = PrimaryProvider::resolveForRequest((int) $input['provider_id']);
        $provider->assertSupportsCapability('transfer');
        $this->eligibility->ensureUserCanCreateForProvider($customer, $provider);
        $beneficiary = $customer->beneficiaries()->findOrFail($input['beneficiary_id']);
        $existing = $customer->transfers()->where('provider_id', $provider->id)
            ->where('client_reference', $input['client_reference'])->first();
        if (isset($input['transfer_id'])) {
            $selected = $customer->transfers()->findOrFail($input['transfer_id']);
            abort_unless($existing?->id === $selected->id, 409, 'The preparation and client reference do not match.');
        }
        if ($existing !== null) {
            $this->assertInput($existing, $input);
            abort_unless(in_array($existing->status, ['draft', 'approval_required', 'approved'], true)
                && $existing->provider_operation_key === null, 409, 'Review the saved payment status; this instruction cannot acquire new terms.');
        }
        $candidate = $existing ?? new Transfer([
            'user_id' => $customer->id, 'provider_id' => $provider->id, 'beneficiary_id' => $beneficiary->id,
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'SGD',
            'source_amount' => $input['source_amount'], 'purpose_code' => $input['purpose_code'],
            'client_reference' => $input['client_reference'], 'reference_text' => $input['reference_text'],
        ]);
        $candidate->setRelation('user', $customer)->setRelation('provider', $provider)->setRelation('beneficiary', $beneficiary);
        $this->scope->assertTransfer($candidate);
        // Local admission only, including explicitly scoped business terms. Check before new FX acquisition.
        $this->verifiedTerms($candidate);
        $transfer = $this->workflow->prepareReview($customer, [
            ...$input, 'transfer_id' => $existing?->id,
            'preparation_id' => $input['preparation_id'] ?? Str::uuid()->toString(),
            'source_currency' => 'USD', 'target_currency' => 'SGD',
            // The expectation alone grants nothing; financial controls require an applicable authorization.
            'expected_fee_amount' => '0', 'expected_fee_currency' => 'USD',
        ]);
        $terms = $this->verifiedTerms($transfer);
        $instruction = $this->authorization->customerInstruction($transfer);
        $token = Crypt::encryptString(json_encode([
            'version' => 1, 'user_id' => $customer->id, 'transfer_id' => $transfer->id,
            'request' => $this->requestBinding($transfer),
            'instruction' => $instruction,
            'submission_token' => NiumSgdLocalPayoutWorkflow::financialTermsToken($instruction),
        ], JSON_THROW_ON_ERROR));
        $payload = $this->customerTransfer($transfer);

        return [...$payload, 'review_token' => $token, 'destination_currency' => 'SGD',
            'fx_expires_at' => $payload['payout_amounts']['fx_lock_expires_at'],
            'conversion_amount' => $payload['payout_amounts']['conversion_amount'],
            'terms' => ['fee_amount' => $terms['fee_amount'], 'currency' => $terms['fee_currency'],
                'total_wallet_debit' => $terms['total_wallet_debit'], 'expires_at' => $terms['expires_at']]];
    }

    /** Replay only a matching recorded receipt; never refresh pricing, FX or dispatch. */
    public function submit(User $customer, array $input): array
    {
        $admissionLease = app(NiumSgdAdmissionFence::class)->acquire();
        $token = $this->decodeToken($input['review_token']);
        abort_unless(($token['user_id'] ?? null) === $customer->id && $customer->status === 'active', 403);
        $provider = PrimaryProvider::resolveForRequest((int) $input['provider_id']);
        $provider->assertSupportsCapability('transfer');
        [$transfer, $replayed] = $this->recordedAcceptance($customer, $input, $token);
        if ($replayed) {
            return [$transfer, true];
        }
        try {
            $this->verifiedTerms($transfer);

            // Production performs provider preflight before taking its own consent transaction lock.
            return [$this->workflow->submit($transfer, $customer, $token['submission_token'], false), false];
        } catch (Throwable $exception) {
            // Another identical Submit may have committed while preflight was running.
            [$current, $accepted] = $this->recordedAcceptance($customer, $input, $token);
            if ($accepted) {
                return [$current, true];
            }

            throw $exception;
        }
    }

    private function recordedAcceptance(User $customer, array $input, array $token): array
    {
        return DB::transaction(function () use ($customer, $input, $token): array {
            $transfer = $customer->transfers()->whereKey($token['transfer_id'])->lockForUpdate()->firstOrFail();
            $this->assertInput($transfer, $input);
            abort_unless(NiumPayoutFxLockContract::sameBinding($token['request'], $this->requestBinding($transfer)),
                409, 'The reviewed instruction changed. Review the saved payment again.');
            $receipt = $transfer->raw_data['sgd_local_workflow']['customer_authorization'] ?? null;
            if ($receipt !== null) {
                abort_unless(($receipt['accepted_by'] ?? null) === $customer->id
                    && ($receipt['basis'] ?? null) === 'authenticated_customer_submit'
                    && ($receipt['payment_terms_accepted'] ?? null) === true
                    && ($receipt['purpose_confirmed'] ?? null) === true
                    && filled($receipt['accepted_at'] ?? null)
                    && hash_equals($token['submission_token'], (string) ($receipt['submission_token'] ?? ''))
                    && NiumPayoutFxLockContract::sameBinding($token['instruction'], $receipt['instruction'] ?? null),
                    409, 'This review does not match the recorded customer acceptance.');

                return [$transfer, true];
            }

            return [$transfer, false];
        });
    }

    /** Explicit public projection: no workflow snapshots, provider identities or authorization records. */
    public function customerTransfer(Transfer $transfer): array
    {
        $amounts = $this->amounts->forTransfer($transfer);

        return [...$transfer->only([
            'id', 'transfer_no', 'provider_id', 'beneficiary_id', 'transfer_type', 'source_currency',
            'target_currency', 'source_amount', 'purpose_code', 'reference_text', 'client_reference',
            'status', 'submitted_at', 'completed_at', 'created_at', 'updated_at',
        ]), 'source_bank_account_id' => null, 'fx_quote_id' => null, 'target_amount' => null,
            'fx_rate' => $amounts['fx_rate'], 'fee_amount' => null, 'fee_currency' => null,
            'payout_amounts' => $amounts,
            'payout_workflow' => $this->workflow->customerStatus($transfer),
        ];
    }

    private function verifiedTerms(Transfer $transfer): array
    {
        $terms = $this->financial->assertFor($transfer);
        // Historical pilot acceptance is never an admission mechanism for the standard API.
        if (isset($terms['authorization_id']) || ($terms['funding_basis'] ?? null) === 'principal_only_pilot') {
            throw new RuntimeException(NiumPayoutFinancialControls::BLOCK_REASON);
        }

        return $terms;
    }

    private function assertInput(Transfer $transfer, array $input): void
    {
        $expected = [
            'provider_id' => (int) $input['provider_id'], 'beneficiary_id' => (int) $input['beneficiary_id'],
            'transfer_type' => 'payout', 'source_currency' => 'USD', 'target_currency' => 'SGD',
            'source_amount' => NiumPayoutFxLockContract::decimal($input['source_amount']),
            'purpose_code' => $input['purpose_code'], 'reference_text' => $input['reference_text'],
            'client_reference' => $input['client_reference'],
        ];
        abort_unless(NiumPayoutFxLockContract::sameBinding($this->requestBinding($transfer), $expected)
            && (! isset($input['transfer_id']) || (int) $input['transfer_id'] === $transfer->id),
            409, 'Client reference or review belongs to a different payment instruction.');
    }

    private function requestBinding(Transfer $transfer): array
    {
        return [...$transfer->only(['provider_id', 'beneficiary_id', 'transfer_type', 'source_currency', 'target_currency']),
            'source_amount' => NiumPayoutFxLockContract::decimal($transfer->source_amount),
            ...$transfer->only(['purpose_code', 'reference_text', 'client_reference'])];
    }

    private function decodeToken(string $value): array
    {
        try {
            $token = json_decode(Crypt::decryptString($value), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($token) || ($token['version'] ?? null) !== 1 || ! is_int($token['transfer_id'] ?? null)
                || ! is_array($token['request'] ?? null) || ! is_array($token['instruction'] ?? null)
                || ! is_string($token['submission_token'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/D', $token['submission_token'])) {
                throw new RuntimeException;
            }

            return $token;
        } catch (Throwable) {
            throw ValidationException::withMessages(['review_token' => 'Review this payment before submitting it.']);
        }
    }
}
