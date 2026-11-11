<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\TransferResource;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumPurposeCodeService;
use App\Services\Nium\NiumTransferPolicy;
use App\Services\Nium\NiumTransferReviewService;
use App\Services\Nium\NiumStandardTransferAdapter;
use App\Services\Transfers\TransferClientReferenceIdempotency;
use App\Services\Transfers\TransferEligibilityService;
use App\Services\Wallet\TransferApprovalService;
use App\Support\PrimaryProvider;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class TransferController extends Controller
{
    public function review(Request $request, User $user, NiumStandardTransferAdapter $adapter): JsonResponse
    {
        abort_unless($request->user()?->id === $user->id, 403);
        $input = $request->validate($this->standardSgdRules());
        try {
            return response()->json($adapter->review($user, $input));
        } catch (Throwable $exception) {
            return $this->standardSgdFailure($exception, $user, $input, $adapter);
        }
    }

    public function index(User $user): JsonResponse
    {
        return response()->json(
            TransferResource::collection($user->transfers()->latest('id')->get())->resolve()
        );
    }

    public function show(User $user, Transfer $transfer): JsonResponse
    {
        abort_unless($transfer->user_id === $user->id, 404);

        return response()->json((new TransferResource(
            $transfer->load(['beneficiary', 'sourceBankAccount', 'transactions'])
        ))->resolve());
    }

    public function store(
        Request $request,
        User $user,
        TransferEligibilityService $eligibilityService,
        TransferApprovalService $approvalService,
        NiumTransferPolicy $niumPolicy,
        NiumPurposeCodeService $purposeCodes,
        TransferClientReferenceIdempotency $idempotency,
    ): JsonResponse {
        $validated = $request->validate([
            'provider_id' => ['sometimes', 'nullable', 'exists:integration_providers,id'],
            'source_bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'beneficiary_id' => ['required', 'exists:beneficiaries,id'],
            'fx_quote_id' => ['nullable', 'exists:fx_quotes,id'],
            'transfer_type' => ['required', 'string', 'max:30'],
            'source_currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'target_currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'source_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,8'],
            'target_amount' => ['nullable', 'numeric'],
            'fx_rate' => ['nullable', 'numeric'],
            'fee_amount' => ['nullable', 'numeric'],
            'fee_currency' => ['nullable', 'string', 'size:3'],
            'purpose_code' => ['nullable', 'string', 'max:100'],
            'reference_text' => ['nullable', 'string', 'max:255'],
            'client_reference' => ['nullable', 'string', 'max:255'],
            'review_token' => ['sometimes', 'string'],
            'accept_payment_terms' => ['sometimes', 'accepted'],
            'purpose_confirmed' => ['sometimes', 'accepted'],
            'raw_data' => ['nullable', 'array'],
            'raw_data.rate_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'raw_data.source' => ['sometimes', 'nullable', 'string', 'max:50'],
            'raw_data.flow' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $provider = PrimaryProvider::resolveForRequest(isset($validated['provider_id']) ? (int) $validated['provider_id'] : null);
        $validated['provider_id'] = $provider->id;

        if (strtolower((string) $provider->code) === 'nium' && $validated['target_currency'] === 'SGD') {
            abort_unless($request->user()?->id === $user->id, 403);
            $input = $request->validate([
                ...$this->standardSgdRules(),
                'transfer_type' => ['required', 'in:payout'],
                'source_currency' => ['required', 'in:USD'],
                'target_currency' => ['required', 'in:SGD'],
                'review_token' => ['required', 'string', 'max:20000'],
                'accept_payment_terms' => ['required', 'accepted'],
                'purpose_confirmed' => ['required', 'accepted'],
            ]);
            $adapter = app(NiumStandardTransferAdapter::class);
            try {
                [$transfer, $replayed] = $adapter->submit($user, $input);

                return response()->json($adapter->customerTransfer($transfer), $replayed ? 200 : 201);
            } catch (Throwable $exception) {
                return $this->standardSgdFailure($exception, $user, $input, $adapter);
            }
        }

        if (isset($validated['beneficiary_id'])) {
            $beneficiary = $user->beneficiaries()->findOrFail($validated['beneficiary_id']);
            if ($beneficiary->provider_id !== $provider->id) {
                return response()->json(['message' => 'Beneficiary provider does not match transfer provider.'], 422);
            }
            if (! in_array(strtolower((string) $beneficiary->status), ['active', 'approved', 'verified'], true)) {
                return response()->json(['message' => 'Transfer beneficiary is not active and cannot receive transfers.'], 422);
            }
            $validated['beneficiary_id'] = $beneficiary->id;
        }

        if (isset($validated['source_bank_account_id'])) {
            $sourceBankAccount = $user->bankAccounts()->findOrFail($validated['source_bank_account_id']);
            if ($sourceBankAccount->provider_id !== $provider->id) {
                return response()->json(['message' => 'Source account provider does not match transfer provider.'], 422);
            }
            $validated['source_bank_account_id'] = $sourceBankAccount->id;
        }

        try {
            $provider->assertSupportsCapability('transfer');
            $eligibilityService->ensureUserCanCreateForProvider($user, $provider);
            if ($niumPolicy->appliesTo($provider)) {
                $authoritativePurposeCodes = $purposeCodes->supported($user);
                if (! filled($validated['client_reference'] ?? null)) {
                    throw ValidationException::withMessages(['client_reference' => 'Client reference is required for Nium transfers.']);
                }
                $validated = $niumPolicy->normalizeCreate($validated, $user, $provider, $beneficiary, $authoritativePurposeCodes);
            }
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException) {
                throw $exception;
            }

            return $this->operationFailureResponse($exception, 'create');
        }

        $fxQuote = null;

        if (isset($validated['fx_quote_id'])) {
            $fxQuote = $user->fxQuotes()
                ->where('provider_id', $provider->id)
                ->findOrFail($validated['fx_quote_id']);

            if ($fxQuote->expires_at === null || $fxQuote->expires_at->isPast()) {
                return response()->json(['message' => 'The selected FX quote has expired.'], 422);
            }

            if (strtoupper($fxQuote->source_currency) !== strtoupper($validated['source_currency'])
                || strtoupper($fxQuote->target_currency) !== strtoupper($validated['target_currency'])
                || $this->decimal($fxQuote->source_amount) !== $this->decimal($validated['source_amount'])) {
                return response()->json(['message' => 'The selected FX quote does not match the transfer corridor or amount.'], 422);
            }

            $validated['fx_quote_id'] = $fxQuote->id;
            $validated['source_currency'] = $fxQuote->source_currency;
            $validated['target_currency'] = $fxQuote->target_currency;
            $validated['source_amount'] = $fxQuote->source_amount;
            $validated['target_amount'] = null;
            $validated['fx_rate'] = $fxQuote->net_rate;
            $validated['fee_amount'] = 0;
            $validated['fee_currency'] = $fxQuote->source_currency;
        } elseif (strtolower((string) $provider->code) === 'nium') {
            $validated['target_amount'] = null;
            $validated['fx_rate'] = null;
            $validated['fee_amount'] = 0;
            $validated['fee_currency'] = $validated['source_currency'];
        }

        unset($validated['review_token'], $validated['accept_payment_terms'], $validated['purpose_confirmed']);

        $clientReference = $validated['client_reference'] ?? 'OW-'.Str::uuid()->toString();
        $existing = $user->transfers()->where('provider_id', $provider->id)->where('client_reference', $clientReference)->first();
        if ($existing !== null) {
            return $this->idempotentCreateResponse($existing, $validated, $idempotency);
        }

        try {
            $transfer = DB::transaction(function () use ($approvalService, $user, $validated, $clientReference): Transfer {
                $transfer = $user->transfers()->create([
                    ...$validated,
                    'transfer_no' => 'TRF-'.Str::upper(Str::random(12)),
                    'client_reference' => $clientReference,
                    'status' => 'draft',
                ]);

                $transfer->update(['status' => $approvalService->initialStatusFor($transfer)]);

                return $transfer->fresh();
            });
        } catch (QueryException $exception) {
            if (! $idempotency->isExpectedUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = $user->transfers()->where('provider_id', $provider->id)->where('client_reference', $clientReference)->first();
            if ($existing === null) {
                throw $exception;
            }

            return $this->idempotentCreateResponse($existing, $validated, $idempotency);
        }

        if ($fxQuote !== null) {
            $transfer->update([
                'target_amount' => null,
                'fx_rate' => $fxQuote->net_rate,
                'fee_amount' => 0,
                'raw_data' => [
                    'fx_quote_id' => $fxQuote->id,
                    'quote_ref' => $fxQuote->quote_ref,
                ],
            ]);
            $transfer = $transfer->fresh();
        }

        return response()->json((new TransferResource($transfer))->resolve(), 201);
    }

    private function standardSgdRules(): array
    {
        return [
            'provider_id' => ['required', 'integer', 'exists:integration_providers,id'],
            'beneficiary_id' => ['required', 'integer'],
            'source_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,8'],
            'purpose_code' => ['required', 'string', 'max:100'],
            'reference_text' => ['required', 'string', 'max:255'],
            'client_reference' => ['required', 'string', 'max:100'],
            'transfer_id' => ['sometimes', 'integer'],
            'preparation_id' => ['sometimes', 'uuid'],
            'transfer_type' => ['sometimes', 'in:payout'],
            'source_currency' => ['sometimes', 'in:USD'],
            'target_currency' => ['sometimes', 'in:SGD'],
            'fx_quote_id' => ['prohibited'], 'fx_rate' => ['prohibited'],
            'fee_amount' => ['prohibited'], 'fee_currency' => ['prohibited'],
            'total_wallet_debit' => ['prohibited'], 'provider_verified' => ['prohibited'],
            'target_amount' => ['prohibited'], 'source_bank_account_id' => ['prohibited'],
            'raw_data' => ['prohibited'], 'accept_uncapped_charges' => ['prohibited'],
            'quote_id' => ['prohibited'], 'pricing' => ['prohibited'],
            'customer_authorization' => ['prohibited'], 'submission_token' => ['prohibited'],
        ];
    }

    private function standardSgdFailure(
        Throwable $exception,
        User $user,
        array $input,
        NiumStandardTransferAdapter $adapter,
    ): JsonResponse {
        if ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
            || $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            throw $exception;
        }
        $transfer = $user->transfers()->where('provider_id', $input['provider_id'])
            ->where('client_reference', $input['client_reference'])->first();

        return response()->json([
            'message' => $exception->getMessage() === \App\Services\Nium\NiumSgdAdmissionFence::CLOSED
                ? \App\Services\Nium\NiumSgdAdmissionFence::CLOSED
                : ($exception->getMessage() === \App\Services\Nium\NiumPayoutFinancialControls::BLOCK_REASON
                    ? \App\Services\Nium\NiumPayoutFinancialControls::BLOCK_REASON
                    : 'SGD LOCAL review or acceptance could not be completed. Check the saved instruction before continuing.'),
            'transfer' => $transfer === null ? null : $adapter->customerTransfer($transfer),
        ], 422);
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 8, '.', '');
    }

    private function idempotentCreateResponse(
        Transfer $existing,
        array $validated,
        TransferClientReferenceIdempotency $idempotency,
    ): JsonResponse {
        if (! $idempotency->matches($existing, $validated)) {
            return response()->json(['message' => 'Client reference is already used for a different transfer request.'], 409);
        }

        return response()->json((new TransferResource($existing))->resolve());
    }

    public function submit(
        User $user,
        Transfer $transfer,
        ProviderTransferManager $manager,
    ): JsonResponse {
        abort_unless($transfer->user_id === $user->id, 404);

        $provider = IntegrationProvider::query()->findOrFail($transfer->provider_id);

        if (NiumTransferReviewService::applies($transfer)) {
            return response()->json(['message' => 'Use standard Review and Customer Submit for consent. SGD remittance remains on HOLD.'], 422);
        }

        try {
            $provider->assertSupportsCapability('transfer');
            $transfer = $manager->submitTransfer(
                provider: $provider,
                transfer: $transfer->load(['user', 'beneficiary', 'sourceBankAccount'])
            );
        } catch (Throwable $exception) {
            return $this->operationFailureResponse($exception, 'submit', $transfer->id);
        }

        return response()->json([
            'message' => $transfer->status === 'submission_unknown'
                ? 'Transfer submission outcome is unknown. Do not resubmit; reconcile or request human review.'
                : 'Transfer submitted successfully.',
            'transfer' => (new TransferResource($transfer))->resolve(),
        ]);
    }

    public function syncStatus(
        User $user,
        Transfer $transfer,
        ProviderTransferManager $manager,
    ): JsonResponse {
        abort_unless($transfer->user_id === $user->id, 404);

        $provider = IntegrationProvider::query()->findOrFail($transfer->provider_id);

        try {
            $provider->assertSupportsCapability('transfer');
            $transfer = $manager->syncTransferStatus(
                provider: $provider,
                transfer: $transfer->load(['user', 'beneficiary', 'sourceBankAccount'])
            );
        } catch (Throwable $exception) {
            return $this->operationFailureResponse($exception, 'sync', $transfer->id);
        }

        return response()->json([
            'message' => 'Transfer status synced successfully.',
            'transfer' => (new TransferResource($transfer))->resolve(),
        ]);
    }

    public function cancel(User $user, Transfer $transfer, \App\Services\Wallet\LedgerService $ledger): JsonResponse
    {
        abort_unless($transfer->user_id === $user->id, 404);

        if (! in_array($transfer->status, ['draft', 'approval_required', 'approved'], true)) {
            return response()->json([
                'message' => 'Only draft, approval required, or approved transfers can be cancelled before provider submission.',
            ], 422);
        }

        $transfer = DB::transaction(function () use ($transfer, $ledger): Transfer {
            $transfer = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($transfer->status, ['draft', 'approval_required', 'approved'], true), 422);
            $transfer->update([
                'status' => 'cancelled',
            ]);
            if (NiumTransferReviewService::applies($transfer)) {
                $ledger->releaseTransferHold($transfer, 'Customer cancelled before provider submission.');
            }

            return $transfer->fresh();
        });

        return response()->json((new TransferResource($transfer))->resolve());
    }

    private function operationFailureResponse(
        Throwable $exception,
        string $operation,
        ?int $transferId = null,
    ): JsonResponse {
        Log::warning('Customer transfer operation failed.', [
            'operation' => $operation,
            'transfer_id' => $transferId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        return response()->json([
            'message' => "Unable to {$operation} transfer. Review its current state and try again.",
        ], 422);
    }
}
