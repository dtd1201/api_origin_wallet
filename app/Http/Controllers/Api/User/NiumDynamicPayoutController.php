<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\User;

use App\Domain\Nium\DynamicMultiCurrency\Financial\Decimal;
use App\Domain\Nium\DynamicMultiCurrency\Financial\FinalizedUsdPayoutPreparation;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutAmountMode;
use App\Domain\Nium\DynamicMultiCurrency\Financial\PayoutInstruction;
use App\Http\Controllers\Controller;
use App\Models\Balance;
use App\Models\Beneficiary;
use App\Models\FxQuote;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use App\Models\NiumDynamicPayoutQuote;
use App\Models\NiumDynamicReservation;
use App\Models\User;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutCustomerStatus;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutGate;
use App\Services\Nium\DynamicConfirmation\DynamicTransferConfirmationService;
use App\Services\Nium\DynamicConfirmation\OriginDynamicPayoutEvidenceSource;
use App\Services\Nium\NiumProviderAccountStateService;
use App\Services\Nium\NiumDynamicPayoutQuoteService;
use App\Services\Nium\NiumDynamicReviewService;
use App\Services\Nium\NiumUnsupportedBeneficiaryCorridor;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/** Customer boundary only. No controller path posts a payout or trusts client financial terms. */
final class NiumDynamicPayoutController extends Controller
{
    public function __construct(private readonly DynamicTransferConfirmationService $confirmation,
        private readonly NiumProviderAccountStateService $accounts,
        private readonly DynamicPayoutCustomerStatus $status,
        private readonly NiumDynamicPayoutQuoteService $quotes,
        private readonly NiumDynamicReviewService $reviews) {}

    public function options(Request $request, User $user): JsonResponse
    {
        $this->assertActor($request, $user);
        $input = $this->input($request, ['beneficiary_id' => ['required', 'integer', 'min:1']]);
        $beneficiary = $user->beneficiaries()->findOrFail($input['beneficiary_id']);
        [$reasons, $wallet] = $this->candidate($user, $beneficiary);
        $quoteAvailability = ['status' => 'UNAVAILABLE', 'destination_currencies' => [],
            'reason_codes' => $reasons ?: ['dynamic_provider_quote_disabled']];
        if ($reasons === [] && (bool) config('services.nium.payout_fx_enabled', false)) {
            try {
                $this->quotes->availability($user, $beneficiary, $wallet);
                $quoteAvailability = ['status' => 'QUOTE_REFERENCE_ONLY',
                    'destination_currencies' => [$beneficiary->currency], 'payout_rail' => 'LOCAL',
                    'reason_codes' => []];
            } catch (Throwable $exception) {
                $quoteAvailability['reason_codes'] = [$this->quoteReason($exception)];
            }
        }

        return $this->json(['beneficiary' => [
            'id' => $beneficiary->id, 'name' => $beneficiary->company_name ?: $beneficiary->full_name,
            'country_code' => $beneficiary->country_code, 'currency' => $beneficiary->currency,
        ], 'status' => $quoteAvailability['status'], 'source_currency' => 'USD',
            'payout_rail' => $beneficiary->payout_method,
            'supported_corridors' => [], 'destination_currencies' => [],
            'quote_availability' => $quoteAvailability,
            'reason_codes' => $quoteAvailability['reason_codes']]);
    }

    public function quote(Request $request, User $user): JsonResponse
    {
        $this->assertActor($request, $user);
        $input = $this->input($request, [
            'beneficiary_id' => ['required', 'integer', 'min:1'],
            'destination_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D'],
            'amount_mode' => ['required', 'in:SOURCE_AMOUNT,DESTINATION_AMOUNT'],
            // Existing finalized preparation accepts exact amounts with at most two decimals.
            'entered_amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D'],
        ]);
        $beneficiary = $user->beneficiaries()->findOrFail($input['beneficiary_id']);
        if ($input['destination_currency'] !== $beneficiary->currency) {
            throw ValidationException::withMessages(['destination_currency' => 'The selected beneficiary uses a different currency.']);
        }
        $amount = new Decimal($input['entered_amount']);
        if ($amount->isZero() || ($input['amount_mode'] === 'SOURCE_AMOUNT'
            && BigDecimal::of($amount->value)->isLessThanOrEqualTo(FinalizedUsdPayoutPreparation::FEE_USD))) {
            throw ValidationException::withMessages(['entered_amount' => 'Enter a positive receive amount or a USD send amount greater than the included fee.']);
        }
        [$reasons, $wallet] = $this->candidate($user, $beneficiary);
        if ($reasons !== []) {
            return $this->unavailable($reasons);
        }
        if ($input['amount_mode'] === 'SOURCE_AMOUNT'
            && BigDecimal::of($wallet->available_balance)->isLessThan($amount->value)) {
            return $this->unavailable(['dynamic_insufficient_available_funds'], 409);
        }

        if ((bool) config('services.nium.payout_fx_enabled', false)) {
            try {
                $quote = $this->quotes->create($user, $beneficiary, $wallet,
                    PayoutAmountMode::from($input['amount_mode']), $input['entered_amount']);

                return $this->json($this->quotes->project($quote), 201);
            } catch (Throwable $exception) {
                return $this->quoteUnavailable($exception);
            }
        }

        return $this->unavailable(OriginDynamicPayoutEvidenceSource::MISSING_DEPENDENCIES);
    }

    public function review(Request $request, User $user): JsonResponse
    {
        $this->assertActor($request, $user);
        $input = $this->input($request, [
            'beneficiary_id' => ['required', 'integer', 'min:1'],
            'fx_quote_id' => ['required_without:dynamic_quote_id', 'prohibits:dynamic_quote_id', 'integer', 'min:1'],
            'dynamic_quote_id' => ['required_without:fx_quote_id', 'prohibits:fx_quote_id', 'uuid'],
            'instruction' => ['required', 'array:purpose_code,source_of_funds,comment'],
            'instruction.purpose_code' => ['required', 'string', 'max:255', 'regex:/^[^\x00-\x1F\x7F]+$/D'],
            'instruction.source_of_funds' => ['required', 'string', 'max:255', 'regex:/^[^\x00-\x1F\x7F]+$/D'],
            'instruction.comment' => ['required', 'string', 'max:255', 'regex:/^[^\x00-\x1F\x7F]+$/D'],
        ]);
        $beneficiary = $user->beneficiaries()->findOrFail($input['beneficiary_id']);
        $dynamicQuote = isset($input['dynamic_quote_id']);
        $quote = ($dynamicQuote ? NiumDynamicPayoutQuote::query() : FxQuote::query())
            ->where('user_id', $user->id)->where('provider_id', $beneficiary->provider_id)
            ->findOrFail($input[$dynamicQuote ? 'dynamic_quote_id' : 'fx_quote_id']);
        if (config('nium_dynamic_quotes.confirmation_contract_enabled', false) !== true) {
            return $this->unavailable(['dynamic_confirmation_contract_disabled']);
        }
        if (! $dynamicQuote && ($quote->source_currency !== 'USD' || $quote->target_currency !== $beneficiary->currency)) {
            return $this->unavailable(['dynamic_quote_binding_changed'], 409);
        }
        if ($quote->expires_at === null || $quote->expires_at->lessThanOrEqualTo(now())) {
            return $this->unavailable(['dynamic_quote_expired'], 409);
        }
        $instruction = $input['instruction'];
        try {
            new PayoutInstruction($instruction['purpose_code'], $instruction['source_of_funds'], $instruction['comment']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['instruction' => 'Instruction fields must contain at most 255 bytes without surrounding whitespace or control characters.']);
        }
        [$reasons, $wallet] = $this->candidate($user, $beneficiary);
        if ($dynamicQuote && $reasons === []) {
            try {
                $this->quotes->assertCurrent($quote, $user, $beneficiary, $wallet);
                $review = $this->reviews->prepare($user, $beneficiary, $wallet, $quote,
                    new PayoutInstruction($instruction['purpose_code'], $instruction['source_of_funds'], $instruction['comment']));
                if ($review['status'] === 'READY') {
                    $this->confirmation->validateReview($user, $review['provider_account_id'], $beneficiary->id, $wallet->id, $review['accepted_fingerprint']);
                    return $this->json($review);
                }
                return $this->json(['status' => $review['status'], 'reason_codes' => $review['reason_codes'],
                    'review' => $review, 'message' => 'The provider execution contract for these terms is not available.'], 503);
            } catch (Throwable $exception) {
                return $this->quoteUnavailable($exception);
            }
        }

        // Owning an FxQuote and syntactically valid instruction is not provider financial
        // authority or purpose/source-of-funds applicability. Never sign a client review.
        return $this->unavailable($reasons ?: OriginDynamicPayoutEvidenceSource::MISSING_DEPENDENCIES);
    }

    public function submit(Request $request, User $user): JsonResponse
    {
        $this->assertActor($request, $user);
        $input = $this->input($request, [
            'provider_account_id' => ['required', 'integer', 'min:1'],
            'beneficiary_id' => ['required', 'integer', 'min:1'],
            'balance_id' => ['required', 'integer', 'min:1'],
            'client_reference' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/D'],
            'accepted_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ]);
        // Scope selectors before the internal boundary, including unavailable requests.
        $user->beneficiaries()->findOrFail($input['beneficiary_id']);
        $user->providerAccounts()->findOrFail($input['provider_account_id']);
        Balance::query()->where('user_id', $user->id)->findOrFail($input['balance_id']);
        try {
            // Customer Submit promises automatic processing. Never reserve a new
            // customer transfer while its dispatch/reconciliation path is disabled.
            DynamicPayoutGate::assertDispatchEnabled();
            $intent = $this->confirmation->confirm($user, (int) $input['provider_account_id'],
                (int) $input['beneficiary_id'], (int) $input['balance_id'],
                $input['client_reference'], $input['accepted_fingerprint']);

            return $this->json($this->project($intent), $intent->wasRecentlyCreated ? 201 : 200);
        } catch (Throwable $exception) {
            // Only allowlisted customer reasons; no raw exception, provider data or fingerprint.
            $reason = $exception->getMessage();
            if (in_array($reason, OriginDynamicPayoutEvidenceSource::MISSING_DEPENDENCIES, true)) {
                return $this->unavailable(OriginDynamicPayoutEvidenceSource::MISSING_DEPENDENCIES);
            }
            if (in_array($reason, [...NiumDynamicReviewService::PROVIDER_REFUSALS,
                'dynamic_registered_account_payload_changed', 'dynamic_registered_account_customer_scope_unavailable'], true)) {
                return $this->unavailable([$reason]);
            }
            $conflicts = ['dynamic_confirmation_idempotency_conflict', 'dynamic_quote_already_consumed',
                'dynamic_wallet_has_unresolved_payout', 'dynamic_insufficient_available_funds',
                'dynamic_registered_account_or_wallet_binding_changed', 'dynamic_confirmation_evidence_expired',
                'payout_quote_expired', 'payout_confirmation_binding_mismatch'];
            $conflicts[] = 'dynamic_review_binding_changed';
            $conflicts[] = 'dynamic_quote_expired';
            $conflicts[] = 'dynamic_quote_binding_changed';
            if (in_array($reason, $conflicts, true)) {
                return $this->unavailable([$reason], 409);
            }
            $disabled = ['dynamic_durable_confirmation_disabled', 'dynamic_automatic_dispatch_disabled',
                'dynamic_live_dispatch_requires_operator_authorization', 'dynamic_dispatch_requires_reconciliation'];
            if (in_array($reason, $disabled, true)) {
                return $this->unavailable(['dynamic_payout_submission_disabled']);
            }
            Log::warning('Dynamic customer submission unavailable.', ['user_id' => $user->id, 'exception' => $exception::class]);

            return $this->unavailable(['dynamic_payout_admission_unavailable']);
        }
    }

    public function show(Request $request, User $user, string $intent): JsonResponse
    {
        $this->assertActor($request, $user);
        $owned = NiumDynamicIntent::query()->where('user_id', $user->id)->findOrFail($intent);

        try {
            return $this->json($this->project($owned));
        } catch (Throwable $exception) {
            Log::warning('Dynamic customer status unavailable.', ['user_id' => $user->id, 'exception' => $exception::class]);

            return $this->unavailable(['dynamic_transfer_status_unavailable']);
        }
    }

    private function project(NiumDynamicIntent $intent): array
    {
        return $this->status->project($intent,
            NiumDynamicPayoutOutbox::query()->where('intent_id', $intent->id)->sole(),
            NiumDynamicReservation::query()->where('intent_id', $intent->id)->sole());
    }

    /** Local screening only. Passing this never supplies corridor or financial admission evidence. */
    private function candidate(User $user, Beneficiary $beneficiary): array
    {
        if (config('nium_dynamic_quotes.quote_contract_enabled', false) !== true) {
            return [['dynamic_quote_contract_disabled'], null];
        }
        if ($user->status !== 'active' || ! in_array($user->kyc_status, ['approved', 'verified'], true)
            || $beneficiary->provider?->code !== 'nium' || $beneficiary->provider?->status !== 'active'
            || ! in_array($beneficiary->status, ['active', 'approved', 'verified'], true)
            || ! is_string($beneficiary->external_beneficiary_id) || $beneficiary->external_beneficiary_id === '') {
            return [['dynamic_beneficiary_or_customer_ineligible'], null];
        }
        if ($beneficiary->payout_method !== 'LOCAL' || in_array($beneficiary->currency, ['USD', 'SGD'], true)) {
            return [['dynamic_corridor_unsupported'], null];
        }
        try {
            $account = $this->accounts->assertEligible($user);
        } catch (Throwable) {
            return [['dynamic_provider_account_ineligible'], null];
        }
        $wallets = Balance::query()->where('user_id', $user->id)->where('provider_id', $beneficiary->provider_id)
            ->where('currency', 'USD')->where('external_account_id', $account->external_account_id)->limit(2)->get();
        if ((string) $account->provider_id !== (string) $beneficiary->provider_id || $wallets->count() !== 1) {
            return [['dynamic_usd_wallet_unavailable'], null];
        }

        return [[], $wallets->sole()];
    }

    private function assertActor(Request $request, User $user): void
    {
        abort_unless($request->user()?->id === $user->id
            && $request->route()->originalParameter('user') === (string) $user->id, 403);
    }

    private function input(Request $request, array $rules): array
    {
        $topLevel = array_values(array_unique(array_map(static fn (string $key): string => explode('.', $key)[0], array_keys($rules))));
        if (array_diff(array_keys($request->all()), $topLevel) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected request fields. Financial terms must come from the server.']);
        }

        return $request->validate($rules);
    }

    private function unavailable(array $reasons, int $status = 503): JsonResponse
    {
        return $this->json(['status' => 'UNAVAILABLE', 'reason_codes' => $reasons,
            'message' => 'Dynamic payout preparation or submission is unavailable. Check any existing transfer status before trying again.'], $status);
    }

    private function quoteUnavailable(Throwable $exception): JsonResponse
    {
        $reason = $this->quoteReason($exception);

        return $this->unavailable([$reason], in_array($reason, ['dynamic_quote_expired', 'dynamic_quote_already_consumed',
            'dynamic_quote_binding_changed', 'dynamic_insufficient_available_funds',
            'dynamic_wallet_has_unresolved_payout'], true) ? 409 : 503);
    }

    private function quoteReason(Throwable $exception): string
    {
        if ($exception instanceof NiumUnsupportedBeneficiaryCorridor) {
            return 'dynamic_corridor_unsupported';
        }
        $reason = $exception->getMessage();

        return in_array($reason, NiumDynamicReviewService::PROVIDER_REFUSALS, true)
            || in_array($reason, ['dynamic_confirmation_evidence_expired', 'dynamic_review_binding_changed',
                'dynamic_compliance_hold_or_evidence_unavailable', 'dynamic_customer_account_beneficiary_or_wallet_ineligible',
                'dynamic_durable_confirmation_disabled', 'dynamic_registered_account_payload_changed',
                'dynamic_registered_account_customer_scope_unavailable',
                'dynamic_provider_quote_disabled', 'dynamic_quote_registration_evidence_unavailable',
            'dynamic_quote_request_precision_unavailable', 'dynamic_quote_amount_precision_unavailable',
            'dynamic_quote_binding_changed', 'dynamic_quote_expired', 'dynamic_quote_already_consumed', 'dynamic_insufficient_available_funds',
            'dynamic_quote_wallet_balance_unavailable', 'dynamic_quote_environment_unverified',
            'dynamic_quote_endpoint_unverified', 'dynamic_quote_local_eligibility_changed'], true)
            || in_array($reason, ['dynamic_payment_purpose_unsupported', 'dynamic_wallet_has_unresolved_payout',
                'dynamic_wallet_authenticated_snapshot_unavailable',
                'dynamic_wallet_response_scope_unverified', 'dynamic_wallet_response_contract_unavailable',
                'dynamic_wallet_usd_availability_unavailable', 'dynamic_wallet_snapshot_binding_changed',
                'dynamic_instruction_request_precision_unavailable', 'dynamic_instruction_validation_binding_changed',
                'dynamic_payout_instruction_validation_unavailable', 'dynamic_payout_instruction_rejected'], true)
            ? $reason : 'dynamic_provider_quote_contract_unavailable';
    }

    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)->header('Cache-Control', 'private, no-store');
    }
}
