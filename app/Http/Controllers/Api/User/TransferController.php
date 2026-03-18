<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Transfers\TransferEligibilityService;
use RuntimeException;

class TransferController extends Controller
{
    public function index(User $user): JsonResponse
    {
        return response()->json(
            $user->transfers()->latest('id')->get()
        );
    }

    public function show(User $user, Transfer $transfer): JsonResponse
    {
        abort_unless($transfer->user_id === $user->id, 404);

        return response()->json(
            $transfer->load(['beneficiary', 'sourceBankAccount', 'approvals', 'transactions'])
        );
    }

    public function store(Request $request, User $user, TransferEligibilityService $eligibilityService): JsonResponse
    {
        $validated = $request->validate([
            'provider_id' => ['required', 'exists:integration_providers,id'],
            'source_bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'beneficiary_id' => ['nullable', 'exists:beneficiaries,id'],
            'fx_quote_id' => ['nullable', 'exists:fx_quotes,id'],
            'transfer_type' => ['required', 'string', 'max:30'],
            'source_currency' => ['required', 'string', 'size:3'],
            'target_currency' => ['required', 'string', 'size:3'],
            'source_amount' => ['required', 'numeric'],
            'target_amount' => ['nullable', 'numeric'],
            'fx_rate' => ['nullable', 'numeric'],
            'fee_amount' => ['nullable', 'numeric'],
            'fee_currency' => ['nullable', 'string', 'size:3'],
            'purpose_code' => ['nullable', 'string', 'max:100'],
            'reference_text' => ['nullable', 'string', 'max:255'],
            'client_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $provider = IntegrationProvider::query()->findOrFail($validated['provider_id']);

        try {
            $eligibilityService->ensureUserCanCreateForProvider($user, $provider);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $fxQuote = null;

        if (isset($validated['fx_quote_id'])) {
            $fxQuote = $user->fxQuotes()
                ->where('provider_id', $provider->id)
                ->findOrFail($validated['fx_quote_id']);
        }

        $transfer = DB::transaction(function () use ($user, $validated): Transfer {
            return $user->transfers()->create([
                ...$validated,
                'transfer_no' => 'TRF-'.Str::upper(Str::random(12)),
                'status' => 'draft',
            ]);
        });

        if ($fxQuote !== null) {
            $transfer->update([
                'target_amount' => $fxQuote->target_amount,
                'fx_rate' => $fxQuote->net_rate,
                'fee_amount' => $fxQuote->fee_amount,
                'raw_data' => array_merge($transfer->raw_data ?? [], [
                    'fx_quote_id' => $fxQuote->id,
                    'quote_ref' => $fxQuote->quote_ref,
                ]),
            ]);
            $transfer = $transfer->fresh();
        }

        return response()->json($transfer, 201);
    }

    public function submit(
        User $user,
        Transfer $transfer,
        ProviderTransferManager $manager,
    ): JsonResponse {
        abort_unless($transfer->user_id === $user->id, 404);

        $provider = IntegrationProvider::query()->findOrFail($transfer->provider_id);

        try {
            $transfer = $manager->submitTransfer(
                provider: $provider,
                transfer: $transfer->load(['user', 'beneficiary', 'sourceBankAccount'])
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Transfer submitted successfully.',
            'transfer' => $transfer,
        ]);
    }

    public function cancel(User $user, Transfer $transfer): JsonResponse
    {
        abort_unless($transfer->user_id === $user->id, 404);

        if (! in_array($transfer->status, ['draft', 'pending'], true)) {
            return response()->json([
                'message' => 'Only draft or pending transfers can be cancelled.',
            ], 422);
        }

        $transfer = DB::transaction(function () use ($transfer): Transfer {
            $transfer->update([
                'status' => 'cancelled',
            ]);

            return $transfer->fresh();
        });

        return response()->json($transfer);
    }
}
