<?php

namespace App\Http\Controllers\Api\Admin;

use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Http\Controllers\Api\Admin\Concerns\RecordsAdminAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\TransferDetailResource;
use App\Http\Resources\Admin\TransferListResource;
use App\Models\IntegrationProvider;
use App\Models\LedgerEntry;
use App\Models\NiumComplianceEvent;
use App\Models\Transfer;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Wallet\LedgerService;
use App\Services\Wallet\TransferApprovalService;
use App\Support\PrimaryProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class TransferController extends Controller
{
    use RecordsAdminAudit;

    public function index(): JsonResponse
    {
        return TransferListResource::collection(
            Transfer::query()
                ->with([
                    'user:id,email,phone,full_name,status,kyc_status',
                    'provider:id,code,name,logo_url,status',
                    'approvals.approver:id,email,full_name,status,kyc_status',
                ])
                ->latest('id')
                ->paginate(15)
        )->response();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'transfer_no' => ['required', 'string', 'max:50', 'unique:transfers,transfer_no'],
            'user_id' => ['required', 'exists:users,id'],
            'provider_id' => ['sometimes', 'nullable', 'exists:integration_providers,id'],
            'source_bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'beneficiary_id' => ['nullable', 'exists:beneficiaries,id'],
            'external_transfer_id' => ['nullable', 'string', 'max:255'],
            'external_payment_id' => ['nullable', 'string', 'max:255'],
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
            'status' => ['nullable', 'string', 'max:30'],
            'failure_code' => ['nullable', 'string', 'max:100'],
            'failure_reason' => ['nullable', 'string'],
            'submitted_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
            'raw_data' => ['nullable', 'array'],
            'raw_data.rate_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $validated['provider_id'] = PrimaryProvider::resolveForRequest($validated['provider_id'] ?? null)->id;

        abort_if(strtolower((string) IntegrationProvider::query()->findOrFail($validated['provider_id'])->code) === 'nium'
            && strtoupper((string) $validated['target_currency']) === 'SGD', 422,
            'SGD LOCAL requires the standard authenticated customer Review and Submit workflow.');

        $transfer = OwnerFirst::users([$validated['user_id']], fn () => Transfer::create($validated));

        return response()->json((new TransferDetailResource($transfer))->resolve($request), 201);
    }

    public function show(Request $request, Transfer $transfer): JsonResponse
    {
        $transfer->load([
            'user:id,email,phone,full_name,status,kyc_status',
            'provider:id,code,name,logo_url,status',
            'beneficiary',
            'sourceBankAccount',
            'approvals.approver:id,email,full_name,status,kyc_status',
            'transactions',
        ]);

        return response()->json((new TransferDetailResource($transfer))->resolve($request));
    }

    public function syncStatus(Request $request, Transfer $transfer, ProviderTransferManager $manager): JsonResponse
    {
        $provider = IntegrationProvider::query()->findOrFail($transfer->provider_id);
        abort_unless(PrimaryProvider::isPrimary($provider), 404);

        try {
            $provider->assertSupportsCapability('transfer');
            $transfer = $manager->syncTransferStatus(
                provider: $provider,
                transfer: $transfer->load(['user', 'beneficiary', 'sourceBankAccount'])
            );
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Transfer status synced successfully.',
            'transfer' => (new TransferDetailResource($transfer))->resolve($request),
        ]);
    }

    public function approve(
        Request $request,
        Transfer $transfer,
        TransferApprovalService $approvalService,
        ProviderTransferManager $providerTransferManager,
    ): JsonResponse {
        $validated = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $before = $transfer->toArray();

        try {
            $transfer = $approvalService->approve($transfer, $request->user(), $validated['note'] ?? null, $providerTransferManager);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $this->recordAdminAudit($request, 'transfer.approved', 'transfer', $transfer->id, $before, $transfer->toArray());

        return response()->json([
            'message' => 'Transfer approved successfully.',
            'transfer' => (new TransferDetailResource($transfer))->resolve($request),
        ]);
    }

    public function reject(
        Request $request,
        Transfer $transfer,
        TransferApprovalService $approvalService,
        LedgerService $ledgerService,
    ): JsonResponse {
        $validated = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $before = $transfer->toArray();

        try {
            $transfer = $approvalService->reject($transfer, $request->user(), $validated['note'] ?? null);
            $transfer = $transfer->fresh(['approvals.approver', 'beneficiary', 'sourceBankAccount']);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $this->recordAdminAudit($request, 'transfer.rejected', 'transfer', $transfer->id, $before, $transfer->toArray());

        return response()->json([
            'message' => 'Transfer rejected successfully.',
            'transfer' => (new TransferDetailResource($transfer))->resolve($request),
        ]);
    }

    public function update(Request $request, Transfer $transfer): JsonResponse
    {
        $validated = $request->validate([
            'transfer_no' => ['sometimes', 'string', 'max:50', 'unique:transfers,transfer_no,'.$transfer->id],
            'user_id' => ['sometimes', 'exists:users,id'],
            'provider_id' => ['sometimes', 'nullable', 'exists:integration_providers,id'],
            'source_bank_account_id' => ['sometimes', 'nullable', 'exists:bank_accounts,id'],
            'beneficiary_id' => ['sometimes', 'nullable', 'exists:beneficiaries,id'],
            'external_transfer_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'external_payment_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'transfer_type' => ['sometimes', 'string', 'max:30'],
            'source_currency' => ['sometimes', 'string', 'size:3'],
            'target_currency' => ['sometimes', 'string', 'size:3'],
            'source_amount' => ['sometimes', 'numeric'],
            'target_amount' => ['sometimes', 'nullable', 'numeric'],
            'fx_rate' => ['sometimes', 'nullable', 'numeric'],
            'fee_amount' => ['sometimes', 'numeric'],
            'fee_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'purpose_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'reference_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'client_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'max:30'],
            'failure_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'failure_reason' => ['sometimes', 'nullable', 'string'],
            'submitted_at' => ['sometimes', 'nullable', 'date'],
            'completed_at' => ['sometimes', 'nullable', 'date'],
            'raw_data' => ['sometimes', 'nullable', 'array'],
            'raw_data.rate_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $transfer = OwnerFirst::transfer($transfer, function () use ($transfer, $validated): Transfer {
            // Serialize against reservation, submission claim and reconciliation.
            // Route-bound models and payload status are never the authority here.
            $transfer = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            abort_if(\App\Services\Nium\NiumTransferReviewService::applies($transfer), 422,
                'SGD LOCAL instructions are immutable. Use the approval, cancellation or status reconciliation workflow.');
            $this->assertGenericMutationAllowed($transfer);

            if (array_key_exists('provider_id', $validated)) {
                $validated['provider_id'] = PrimaryProvider::resolveForRequest($validated['provider_id'])->id;
            }
            $effectiveProvider = IntegrationProvider::query()->findOrFail($validated['provider_id'] ?? $transfer->provider_id);
            abort_if(strtolower((string) $effectiveProvider->code) === 'nium'
                && strtoupper((string) ($validated['target_currency'] ?? $transfer->target_currency)) === 'SGD', 422,
                'SGD LOCAL requires the standard authenticated customer Review and Submit workflow.');

            $transfer->update($validated);

            return $transfer->fresh();
        }, isset($validated['user_id']) ? [$validated['user_id']] : []);

        return response()->json((new TransferDetailResource($transfer))->resolve($request));
    }

    public function destroy(Transfer $transfer): JsonResponse
    {
        OwnerFirst::transfer($transfer, function () use ($transfer): void {
            $transfer = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            abort_if(\App\Services\Nium\NiumTransferReviewService::applies($transfer), 422,
                'SGD LOCAL payment history cannot be deleted. Cancel an unsubmitted instruction through its workflow.');
            $this->assertGenericMutationAllowed($transfer);
            $transfer->delete();
        });

        return response()->json(status: 204);
    }

    /** Only generic Admin CRUD calls this, while holding the persisted Transfer row lock. */
    private function assertGenericMutationAllowed(Transfer $transfer): void
    {
        // Include canonical references even when historical source metadata is
        // contradictory. Neither journal status nor mutable wallet receipts can
        // make an existing financial record disappear from this boundary.
        $journals = LedgerEntry::query()->where(function ($query) use ($transfer): void {
            $query->where(fn ($source) => $source->where('source_type', 'transfer')->where('source_id', (string) $transfer->id))
                ->orWhereIn('reference', [
                    "transfer:{$transfer->id}:hold", "transfer:{$transfer->id}:debit", "transfer:{$transfer->id}:release",
                ]);
        });
        $niumProviders = IntegrationProvider::query()->select('id')->whereRaw('LOWER(code) = ?', ['nium']);
        $compliance = NiumComplianceEvent::query()->where('transfer_id', $transfer->id);

        // Related persisted Nium evidence also protects an instruction whose
        // provider identity was already corrupted before this patch.
        $isNium = strtolower((string) $transfer->provider?->code) === 'nium'
            || (clone $journals)->whereIn('provider_id', $niumProviders)->exists()
            || $transfer->transactions()->whereIn('provider_id', $niumProviders)->exists()
            || $transfer->apiRequestLogs()->whereIn('provider_id', $niumProviders)->exists()
            || $transfer->fxQuote()->whereIn('provider_id', $niumProviders)->exists()
            || (clone $compliance)->whereIn('provider_id', $niumProviders)->exists();
        if (! $isNium) {
            return;
        }

        $protected = $journals->exists() || $transfer->fx_quote_id !== null
            || $transfer->approvals()->exists() || $transfer->transactions()->exists()
            || $transfer->apiRequestLogs()->exists() || $compliance->exists()
            || ! in_array($transfer->status, ['draft', 'approval_required', 'approved'], true);
        foreach ([
            'provider_operation_key', 'external_transfer_id', 'external_payment_id',
            'submitted_at', 'provider_status_at', 'provider_status', 'provider_status_detail',
            'completed_at', 'failure_code', 'failure_reason',
        ] as $field) {
            $protected = $protected || $transfer->getRawOriginal($field) !== null;
        }
        // Supplement authoritative columns/relations with existing receipt and
        // provider-result markers; raw_data is never the only evidence queried.
        foreach ([
            'wallet.hold_reference', 'wallet.held_amount', 'wallet.debit_reference', 'wallet.release_reference',
            'provider_operation_key', 'provider_request_id', 'provider_status', 'provider_error_code',
            'fx_quote_id', 'quote_ref', 'payment_consent',
        ] as $path) {
            $protected = $protected || Arr::has((array) $transfer->raw_data, $path);
        }

        // Existing editable fields all affect instruction, financial or audit
        // identity. There is no demonstrated safe metadata exception to allow.
        abort_if($protected, 422,
            'Nium transfers with financial history or bound authorization cannot be edited or deleted through generic Admin CRUD.');
    }
}
