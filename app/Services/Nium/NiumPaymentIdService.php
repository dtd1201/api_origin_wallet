<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountOperation;
use App\Models\NiumVirtualAccountRequest;
use App\Models\UserProviderAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class NiumPaymentIdService
{
    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumProviderAccountStateService $accountStateService,
        private readonly NiumVirtualAccountReconciliationService $reconciliationService,
    ) {}

    public function assign(
        UserProviderAccount $account,
        string $currency,
        string $accountCategory,
        string $accountType,
        ?string $bankName = null,
        ?string $operationId = null,
        bool $autoSelectBank = false,
        ?string $apiVersion = null,
    ): NiumVirtualAccount|NiumVirtualAccountOperation {
        if ($apiVersion !== null) {
            NiumVirtualAccountAssignmentContract::assertVersion($apiVersion);
        }
        $account->loadMissing('user');

        $eligibleAccount = $this->accountStateService->assertEligible($account->user);

        if ((int) $eligibleAccount->id !== (int) $account->id) {
            throw new RuntimeException(
                'The requested Nium provider account is not the current eligible account.'
            );
        }

        $account = $eligibleAccount;
        $currency = strtoupper($currency);
        $accountCategory = strtoupper($accountCategory);
        $accountType = strtoupper($accountType);

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1
            || ! in_array($accountCategory, ['SELF_FUNDING_ACCOUNT', 'COLLECTION_ACCOUNT', 'SELF_FUNDING_AND_COLLECTION_ACCOUNT'], true)
            || ! in_array($accountType, ['LOCAL', 'WIRES', 'LOCAL_AND_WIRES'], true)) {
            throw new RuntimeException('Invalid Nium Assign Payment ID currency, account category, or account type.');
        }
        $operationId = trim((string) $operationId);
        if ($operationId === '') {
            throw new RuntimeException('Nium Assign Payment ID requires an explicit operation identifier.');
        }
        if (strlen($operationId) > 255) {
            throw new RuntimeException('Nium Assign Payment ID operation identifier is too long.');
        }

        $existing = NiumVirtualAccountOperation::query()->where('user_provider_account_id', $account->id)
            ->where('operation_id', $operationId)->first();
        $hasLedger = Schema::hasTable('nium_va_requests');
        $enrolledRequest = $hasLedger && $existing !== null
            ? NiumVirtualAccountRequest::query()->whereKey($existing->id)->first() : null;
        // An omitted version resumes the stored contract. Never re-version a saved
        // operation when the corridor configuration changes; explicit mismatches
        // still fail the existing immutable identity check in the orchestrator.
        $selectedV1 = $apiVersion === null && $existing === null
            && (string) config('services.nium.greentech_v1_account_id') === (string) $account->id
            && $currency === 'USD'
            && $accountCategory === 'SELF_FUNDING_ACCOUNT'
            && $accountType === 'WIRES'
            && ($bankName === null || $bankName === 'JPM_SG');
        $apiVersion ??= $enrolledRequest !== null
            ? $enrolledRequest->identity()->snapshot()['api_version']
            : ($selectedV1 ? 'v1' : 'v2');
        if ($selectedV1) {
            $bankName = 'JPM_SG';
        }
        $enabled = config('services.nium.va_single_dispatch.enabled') === true;
        // The selected corridor must reach the durable gate even when disabled:
        // it must never fall back to the legacy GET + V2 POST engine.
        if ($enrolledRequest !== null || $selectedV1 || ($enabled && $existing === null)) {
            return app(NiumVirtualAccountAssignmentService::class)->assign($account, $operationId, $currency, $accountCategory, $accountType, $bankName, $apiVersion);
        }
        if ($apiVersion !== 'v2') {
            throw new RuntimeException('V1 assignment requires the inactive single-dispatch workflow; legacy operations cannot be enrolled.');
        }
        if ($enabled && $existing?->status === NiumVirtualAccountOperation::PREFLIGHT_FAILED) {
            throw new RuntimeException('Historical assignments cannot be redispatched through the single-dispatch cutover.');
        }
        if ($hasLedger && $existing === null && NiumVirtualAccountRequest::query()
            ->join('nium_virtual_account_operations as o', 'o.id', '=', 'nium_va_requests.operation_id')
            ->join('user_provider_accounts as a', 'a.id', '=', 'o.user_provider_account_id')
            ->where(fn ($q) => $q->where('a.id', $account->id)
                ->orWhereRaw('LOWER(a.external_account_id) = ?', [strtolower((string) $account->external_account_id)]))->exists()) {
            throw new RuntimeException('A ledger-enrolled wallet cannot fall back to legacy assignment when the feature is disabled.');
        }

        // The HTTP API has no caller-selected bank. Preserve its legacy selection here;
        // the ledger path above resolves it only for a new request and freezes replays.
        if ($autoSelectBank && $bankName === null) {
            $bankName = app(NiumPaymentIdBankResolver::class)->resolve($account->user, $currency);
        }
        [$operation, $claimed] = $this->claim(
            $account,
            $operationId,
            $currency,
            $accountCategory,
            $accountType,
            $bankName,
        );

        if (! $claimed && $operation->status !== NiumVirtualAccountOperation::PREFLIGHT_FAILED) {
            return $this->resume($operation);
        }

        $operation = $this->reconciliationService->captureBaseline($operation);
        if (! $operation->baseline_complete) {
            throw new RuntimeException('Assign Payment ID preflight is unresolved; no provider POST was sent.');
        }
        $operation->update([
            'status' => NiumVirtualAccountOperation::SUBMITTING,
            'submitted_at' => now(),
            'resolution_code' => null,
        ]);

        $payload = array_filter([
            'currency' => $currency,
            'accountCategory' => $accountCategory,
            'accountType' => $accountType,
            'bankName' => $bankName,
        ], static fn ($value) => $value !== null && $value !== '');
        try {
            $response = $this->niumService->post(
                path: $this->niumService->path((string) config('services.nium.assign_payment_id_endpoint'), [
                    'client' => $this->niumService->clientId(),
                    'customer' => $this->niumService->customerId($account->user),
                    'wallet' => $this->niumService->walletId($account->user),
                ]),
                payload: $payload,
                user: $account->user,
                operation: 'assign_payment_id',
                externalReference: $operationId,
            );
        } catch (ConnectionException|NiumEvidencePersistenceException $exception) {
            $this->markUnknown($operation, 'provider_outcome_unknown');

            throw $exception;
        }
        $data = $response->json() ?? [];
        $paymentId = $data['uniquePaymentId'] ?? $data['payment_id'] ?? null;

        if (in_array($response->status(), [408, 429], true) || $response->serverError()) {
            $this->markUnknown($operation, 'provider_outcome_unknown');

            throw new RuntimeException('Assign Payment ID outcome is unknown; do not retry the POST.');
        }

        if (
            ! $response->successful()
            || ! is_string($paymentId)
            || $paymentId === ''
        ) {
            $operation->update([
                'status' => NiumVirtualAccountOperation::FAILED,
                'resolution_code' => 'provider_rejected_or_invalid_response',
                'resolved_at' => now(),
                'api_request_log_id' => $this->requestLogId($operation),
            ]);
            throw new RuntimeException('Assign Payment ID failed.');
        }

        $isPending = strtoupper($paymentId) === 'INITIALIZED';

        if ($isPending) {
            $operation->update([
                'status' => NiumVirtualAccountOperation::PENDING,
                'resolution_code' => 'provider_initialized',
                'api_request_log_id' => $this->requestLogId($operation),
            ]);

            return $operation->virtualAccount()->firstOrFail();
        }

        $resolved = $this->reconciliationService->assign(
            $operation,
            $paymentId,
            'provider_response_payment_id',
        );
        $resolved->update(['api_request_log_id' => $this->requestLogId($resolved)]);

        return $resolved->virtualAccount()->firstOrFail();
    }

    /** @return array{NiumVirtualAccountOperation, bool} */
    private function claim(
        UserProviderAccount $account,
        string $operationId,
        string $currency,
        string $accountCategory,
        string $accountType,
        ?string $bankName,
    ): array {
        return DB::transaction(function () use (
            $account,
            $operationId,
            $currency,
            $accountCategory,
            $accountType,
            $bankName,
        ): array {
            UserProviderAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $existing = NiumVirtualAccountOperation::query()
                ->where('user_provider_account_id', $account->id)
                ->where('operation_id', $operationId)
                ->first();

            if ($existing !== null) {
                $sameRequest = $existing->user_provider_account_id === $account->id
                    && $existing->currency === $currency
                    && $existing->account_category === $accountCategory
                    && $existing->account_type === $accountType
                    && (string) $existing->bank_name === (string) $bankName;
                if (! $sameRequest) {
                    throw new RuntimeException('Nium Assign Payment ID operation identifier is already bound to another request.');
                }

                return [$existing->fresh('virtualAccount'), false];
            }

            $virtualAccount = NiumVirtualAccount::query()->create([
                'user_provider_account_id' => $account->id,
                'currency' => $currency,
                'account_category' => $accountCategory,
                'account_type' => $accountType,
                'status' => 'pending',
            ]);
            $operation = NiumVirtualAccountOperation::query()->create([
                'user_provider_account_id' => $account->id,
                'nium_virtual_account_id' => $virtualAccount->id,
                'operation_id' => $operationId,
                'currency' => $currency,
                'account_category' => $accountCategory,
                'account_type' => $accountType,
                'bank_name' => $bankName,
                'status' => NiumVirtualAccountOperation::CLAIMED,
                'claimed_at' => now(),
            ]);

            return [$operation->fresh('virtualAccount'), true];
        });
    }

    private function resume(NiumVirtualAccountOperation $operation): NiumVirtualAccount
    {
        if ($operation->status === NiumVirtualAccountOperation::ASSIGNED) {
            return $operation->virtualAccount()->firstOrFail();
        }
        if ($operation->status === NiumVirtualAccountOperation::FAILED) {
            throw new RuntimeException('Assign Payment ID operation previously failed and will not be reposted.');
        }
        if (in_array($operation->status, [
            NiumVirtualAccountOperation::UNKNOWN,
            NiumVirtualAccountOperation::PENDING,
            NiumVirtualAccountOperation::MANUAL_REVIEW,
        ], true)) {
            $operation = $this->reconciliationService->reconcile($operation);
        }

        return $operation->virtualAccount()->firstOrFail();
    }

    private function markUnknown(NiumVirtualAccountOperation $operation, string $code): void
    {
        $operation->update([
            'status' => NiumVirtualAccountOperation::UNKNOWN,
            'resolution_code' => $code,
            'api_request_log_id' => $this->requestLogId($operation),
        ]);
    }

    private function requestLogId(NiumVirtualAccountOperation $operation): ?int
    {
        $userId = $operation->providerAccount()->value('user_id');

        return ApiRequestLog::query()
            ->where('user_id', $userId)
            ->where('operation', 'assign_payment_id')
            ->where('external_reference', $operation->operation_id)
            ->latest('id')
            ->value('id');
    }
}
