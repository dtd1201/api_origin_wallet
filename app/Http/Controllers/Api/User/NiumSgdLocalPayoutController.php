<?php

namespace App\Http\Controllers\Api\User;

use App\Contracts\NiumRemittancePricing;
use App\Http\Controllers\Controller;
use App\Http\Resources\User\TransferResource;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Nium\NiumProvisionalPayoutPricing;
use App\Services\Nium\NiumSgdLocalCugScope;
use App\Services\Nium\NiumSgdLocalOutcomeRecovery;
use App\Services\Nium\NiumSgdLocalPayoutGate;
use App\Services\Nium\NiumSgdLocalPayoutWorkflow;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

final class NiumSgdLocalPayoutController extends Controller
{
    public function store(Request $request, User $user, NiumSgdLocalPayoutWorkflow $workflow): JsonResponse
    {
        $input = $request->validate([
            'provider_id' => ['required', 'integer'], 'beneficiary_id' => ['required', 'integer'],
            'source_currency' => ['required', 'in:USD'], 'target_currency' => ['required', 'in:SGD'],
            'source_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,8'],
            'purpose_code' => ['required', 'string', 'max:100'], 'purpose_confirmed' => ['required', 'accepted'],
            'client_reference' => ['required', 'string', 'max:100'],
            'expected_fee_amount' => ['required', 'string', 'regex:/^0(?:\.0+)?$/'],
            'expected_fee_currency' => ['required', 'in:USD'],
            'fx_quote_id' => ['prohibited'], 'fx_rate' => ['prohibited'], 'raw_data' => ['prohibited'],
            'fee_amount' => ['prohibited'], 'target_amount' => ['prohibited'], 'source_bank_account_id' => ['prohibited'],
        ]);
        try {
            return $this->resource($workflow->prepare($user, $input));
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function lock(Request $request, User $user, Transfer $transfer, NiumSgdLocalPayoutWorkflow $workflow): JsonResponse
    {
        abort_unless($transfer->user_id === $user->id, 404);
        $input = $request->validate([
            'request_id' => ['required', 'uuid'], 'confirm_fx_lock' => ['required', 'accepted'],
        ]);
        try {
            return $this->resource($workflow->requestLock($transfer, $input['request_id']));
        } catch (Throwable $exception) {
            return $this->failure($exception, $transfer->fresh());
        }
    }

    public function requestApproval(Request $request, User $user, Transfer $transfer, NiumSgdLocalPayoutWorkflow $workflow): JsonResponse
    {
        abort_unless($transfer->user_id === $user->id, 404);
        abort_unless($request->user()?->id === $user->id, 403);
        $input = $request->validate([
            'submission_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'accept_payment_terms' => ['required', 'accepted'],
            'purpose_confirmed' => ['required', 'accepted'],
            'accept_uncapped_charges' => ['sometimes', 'boolean'],
        ]);
        try {
            return $this->resource($workflow->submit($transfer, $request->user(), $input['submission_token'], (bool) ($input['accept_uncapped_charges'] ?? false)));
        } catch (Throwable $exception) {
            return $this->failure($exception, $transfer->fresh());
        }
    }

    public function provisionalPricing(Request $request, User $user, Transfer $transfer, NiumProvisionalPayoutPricing $policy): JsonResponse
    {
        abort_unless($transfer->user_id === $user->id, 404);
        $input = $request->validate([
            'policy_id' => ['required', 'in:'.NiumProvisionalPayoutPricing::POLICY_ID],
            'fx_quote_id' => ['required', 'integer'], 'confirm_provisional_pricing' => ['required', 'accepted'],
            'raw_data' => ['prohibited'], 'fee_amount' => ['prohibited'], 'total_wallet_debit' => ['prohibited'],
        ]);
        try {
            return $this->resource($policy->record($transfer, $user, (int) $input['fx_quote_id']));
        } catch (Throwable $exception) {
            return $this->failure($exception, $transfer->fresh());
        }
    }

    public function refreshPricing(User $user, Transfer $transfer, NiumSgdLocalPayoutWorkflow $workflow, NiumRemittancePricing $pricing): JsonResponse
    {
        abort_unless($transfer->user_id === $user->id, 404);
        try {
            app(NiumSgdLocalCugScope::class)->assertTransfer($transfer);
            $workflow->assertIntent($transfer);
            if ($transfer->status !== 'draft' || $transfer->provider_operation_key !== null) {
                throw new \RuntimeException('Only an unsubmitted preparation can refresh provider pricing.');
            }
            // An explicit read-only configuration request; no FX lock or payment dispatch.
            $pricing->refresh($transfer);

            return $this->resource($transfer->fresh());
        } catch (Throwable $exception) {
            return $this->failure($exception, $transfer->fresh());
        }
    }

    public function recoveryReview(User $user, Transfer $transfer, NiumSgdLocalPayoutWorkflow $workflow): JsonResponse
    {
        abort_unless($transfer->user_id === $user->id, 404);
        try {
            return $this->resource($workflow->requestRecoveryReview($transfer));
        } catch (Throwable $exception) {
            return $this->failure($exception, $transfer->fresh());
        }
    }

    public function restartReview(Request $request, User $user, Transfer $transfer, NiumSgdLocalPayoutWorkflow $workflow): JsonResponse
    {
        abort_unless($transfer->user_id === $user->id, 404);
        $request->validate(['confirm_new_review' => ['required', 'accepted']]);
        try {
            return $this->resource($workflow->restartExpiredReview($transfer));
        } catch (Throwable $exception) {
            return $this->failure($exception, $transfer->fresh());
        }
    }

    private function resource(Transfer $transfer): JsonResponse
    {
        return response()->json((new TransferResource($transfer->load(['beneficiary', 'provider', 'user', 'fxQuote'])))->resolve());
    }

    public function recoverCaptured(User $user, Transfer $transfer, NiumSgdLocalOutcomeRecovery $recovery): JsonResponse
    {
        abort_unless($transfer->user_id === $user->id, 404);
        try {
            return $this->resource($recovery->recover($transfer));
        } catch (Throwable $exception) {
            return $this->failure($exception, $transfer->fresh());
        }
    }

    private function failure(Throwable $exception, ?Transfer $transfer = null): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage() === NiumSgdLocalPayoutGate::BLOCK_REASON
                ? NiumSgdLocalPayoutGate::BLOCK_REASON
                : 'SGD Local preparation could not be completed. No payment was submitted. Refresh its status before continuing.',
            'errors' => $exception instanceof ValidationException ? $exception->errors() : null,
            'transfer' => $transfer === null ? null : (new TransferResource($transfer))->resolve(),
        ], 422);
    }
}
