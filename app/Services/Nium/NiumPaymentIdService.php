<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountOperation;
use App\Models\NiumVirtualAccountRequest;
use App\Models\UserProviderAccount;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
        $confirmedCorridor = NiumVirtualAccountAssignmentContract::isConfirmedV1Corridor(
            $currency, $accountCategory, $accountType, $bankName,
        );
        $selectedV1 = $apiVersion === null && $existing === null && $confirmedCorridor;
        $apiVersion ??= $enrolledRequest !== null
            ? $enrolledRequest->identity()->snapshot()['api_version']
            : ($selectedV1 ? 'v1' : 'v2');
        if ($selectedV1) {
            $bankName = 'JPM_SG';
        }
        $enabled = config('services.nium.va_single_dispatch.enabled') === true;
        // Every new Production request reaches admission/contract validation even
        // when disabled. The selected corridor never falls back to legacy V2.
        if ($enrolledRequest !== null || ($existing === null
            && ($confirmedCorridor || $enabled || app()->environment('production')))) {
            return app(NiumVirtualAccountAssignmentService::class)->assign($account, $operationId, $currency, $accountCategory, $accountType, $bankName, $apiVersion);
        }
        if ($apiVersion !== 'v2') {
            throw new RuntimeException('V1 assignment requires the inactive single-dispatch workflow; legacy operations cannot be enrolled.');
        }
        if (($enabled || $confirmedCorridor || app()->environment('production'))
            && $existing?->status === NiumVirtualAccountOperation::PREFLIGHT_FAILED) {
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
        if ($existing === null && config('services.nium.va_single_dispatch.allocation_paused') !== false) {
            throw new RuntimeException('New legacy VA allocation is paused; recovery remains available.');
        }
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

        if ($operation->legacy_target_snapshot === null) {
            $this->reconciliationService->reconcile($operation);
            throw new RuntimeException('Historical legacy operations require verified original evidence; no POST was sent.');
        }

        $operation = $this->reconciliationService->captureBaseline($operation);
        if (! $operation->baseline_complete) {
            throw new RuntimeException('Assign Payment ID preflight is unresolved; no provider POST was sent.');
        }
        // Commit one dispatch claim before HTTP. Build transport exclusively from the
        // immutable target captured under the account lock when the operation was created.
        $operation = $this->authorizeLegacyDispatch($operation);
        $snapshot = $operation->legacy_target_snapshot;
        $account = $operation->providerAccount()->with(['provider', 'user'])->firstOrFail();
        app(NiumLegacyVirtualAccountEvidence::class)->target($operation, $account);
        $this->niumService->assertConfigured();
        $client = new ProviderHttpClient(
            provider: $account->provider, serviceConfigKey: 'nium',
            headers: ['x-request-id' => $operation->legacy_provider_request_id],
            operationalContext: ['operation' => 'assign_payment_id', 'external_reference' => $operation->operation_id,
                'client_hash_id' => $snapshot['client_id']],
            singleDispatchAssignment: true,
        );
        try {
            $response = $client->post(
                path: $snapshot['provider_origin'].$snapshot['endpoint'],
                payload: NiumVirtualAccountAssignmentContract::payload('v2', $snapshot['payload']['currency'],
                    $snapshot['payload']['accountCategory'], $snapshot['payload']['accountType'], $snapshot['payload']['bankName'] ?? null),
                user: $account->user,
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
        if ($resolved->status !== NiumVirtualAccountOperation::ASSIGNED) {
            throw new RuntimeException('Legacy assignment evidence requires manual review; do not repeat the POST.');
        }

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
            $account = UserProviderAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
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

            if ($account->customer_id_verified_at === null || $account->wallet_id_verified_at === null) {
                throw new RuntimeException('A legacy assignment requires verified customer and wallet identifiers.');
            }
            $identity = NiumVirtualAccountRequestIdentity::forAccount($account,
                NiumVirtualAccountAssignmentContract::payload('v2', $currency, $accountCategory, $accountType, $bankName));

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
                'legacy_target_snapshot' => $identity->snapshot(),
                'legacy_provider_request_id' => (string) Str::uuid(),
            ]);

            return [$operation->fresh('virtualAccount'), true];
        });
    }

    private function authorizeLegacyDispatch(NiumVirtualAccountOperation $operation): NiumVirtualAccountOperation
    {
        return DB::transaction(function () use ($operation): NiumVirtualAccountOperation {
            $account = UserProviderAccount::query()->lockForUpdate()->findOrFail($operation->user_provider_account_id);
            $locked = NiumVirtualAccountOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if (config('services.nium.va_single_dispatch.allocation_paused') !== false
                || (int) $locked->user_provider_account_id !== (int) $account->id
                || $locked->submitted_at !== null || ! $locked->baseline_complete
                || ! in_array($locked->status, [NiumVirtualAccountOperation::CLAIMED, NiumVirtualAccountOperation::PREFLIGHT_FAILED], true)
                || NiumVirtualAccountRequest::query()->whereKey($locked->id)->exists()
                || $locked->legacy_target_snapshot === null) {
                throw new RuntimeException('Legacy VA dispatch is paused, already consumed or unproven; no POST was sent.');
            }
            app(NiumLegacyVirtualAccountEvidence::class)->target($locked, $account);
            // Recheck current eligibility without substituting it for original target proof.
            if ((int) $this->accountStateService->assertEligible($account->user)->id !== (int) $account->id) {
                throw new RuntimeException('The legacy assignment account is no longer eligible.');
            }
            $locked->update(['status' => NiumVirtualAccountOperation::SUBMITTING,
                'submitted_at' => now(), 'resolution_code' => null]);

            return $locked->fresh();
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
        $snapshot = $operation->legacy_target_snapshot;
        if ($snapshot === null) {
            return null;
        }
        $logs = ApiRequestLog::query()
            ->where('user_id', $snapshot['user_id'])->where('provider_id', $snapshot['provider_id'])
            ->where('operation', 'assign_payment_id')
            ->where('external_reference', $operation->operation_id)
            ->where('request_headers->x-request-id', $operation->legacy_provider_request_id)->get();

        return $logs->count() === 1 ? $logs->sole()->id : null;
    }
}
