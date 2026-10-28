<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\NiumApiExchangeEvidence;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountAssignmentScope;
use App\Models\NiumVirtualAccountDispatchAttempt;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\NiumVirtualAccountRequest;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Persistence primitives. HTTP must always happen outside these top-level transactions. */
final class NiumVirtualAccountSafetyLedger
{
    public function prepare(UserProviderAccount $account, string $operationId, NiumVirtualAccountRequestIdentity $identity): NiumVirtualAccountRequest
    {
        $this->assertTopLevel();
        if ($operationId === '' || trim($operationId) !== $operationId || strlen($operationId) > 255
            || $identity->snapshot()['provider_account_id'] !== (int) $account->id) {
            throw new RuntimeException('Invalid assignment operation identity.');
        }

        return DB::transaction(function () use ($account, $operationId, $identity): NiumVirtualAccountRequest {
            $account = UserProviderAccount::query()->lockForUpdate()->findOrFail($account->id);
            $this->assertCurrentEligibility($account);
            $scope = $this->lockScope($identity->scopeKey());
            // Read the immutable operation identity without taking the operation lock
            // ahead of its companion request. Same-account preparation is serialized above.
            $operation = Operation::query()->where('user_provider_account_id', $account->id)
                ->where('operation_id', $operationId)->first();

            if ($operation !== null) {
                $request = NiumVirtualAccountRequest::query()->lockForUpdate()->find($operation->id);
                if ($request === null || ! $request->matchesIdentity($identity)) {
                    throw new RuntimeException('Legacy operations cannot be enrolled; existing request identity cannot change.');
                }
                Operation::query()->lockForUpdate()->findOrFail($operation->id);

                return $request;
            }

            if ($scope->active_operation_id !== null) {
                throw new RuntimeException('This wallet and currency already have an unresolved assignment.');
            }
            if (strtolower((string) $account->provider()->value('code')) !== 'nium') {
                throw new RuntimeException('The assignment snapshot must match the Nium provider account.');
            }

            $identity->assertCurrentTarget($account);
            $this->assertLegacyEligible($identity);
            $payload = $identity->snapshot()['payload'];
            $operation = Operation::query()->create([
                'user_provider_account_id' => $account->id,
                'operation_id' => $operationId,
                'currency' => $identity->currency(),
                'account_category' => $payload['accountCategory'],
                'account_type' => $identity->fundingRail(),
                'bank_name' => $payload['bankName'] ?? null,
                'status' => Operation::CLAIMED,
                'claimed_at' => now(),
            ]);
            $request = NiumVirtualAccountRequest::query()->create([
                'operation_id' => $operation->id,
                'scope_key' => $identity->scopeKey(),
                'snapshot' => $identity->snapshot(),
                'payload_sha256' => $identity->payloadHash(),
                'provider_request_id' => (string) Str::uuid(),
                'state_version' => 0, 'operation_state' => Operation::CLAIMED,
            ]);
            $scope->update(['active_operation_id' => $operation->id]);

            return $request;
        });
    }

    /** Advisory only; consumeDispatchAuthorization repeats this check under database locks. */
    public function dispatchAuthorizationEligible(NiumVirtualAccountRequest $request): bool
    {
        return $request->exists
            && $request->operation()->value('status') === Operation::CLAIMED
            && ! $request->dispatchAttempt()->exists()
            && $request->assignmentScope()->value('active_operation_id') === $request->operation_id
            && $request->resolution_api_request_log_id === null
            && $request->resolution_webhook_event_id === null;
    }

    /** A non-null result authorizes one future attempt, never an automatic retry. No HTTP belongs here. */
    public function consumeDispatchAuthorization(NiumVirtualAccountRequest $request, int $expectedVersion): ?NiumVirtualAccountDispatchAttempt
    {
        $connection = DB::connection();
        $this->assertTopLevel();
        $readyToCommit = false;
        try {
            $attempt = $connection->transaction(function () use ($request, $expectedVersion, &$readyToCommit): ?NiumVirtualAccountDispatchAttempt {
                // Override asynchronous commit for this safety record, on the same write connection.
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('SET LOCAL synchronous_commit = on');
                }
                [$request, $operation, , $account] = $this->lockRequest($request);
                if ($request->state_version !== $expectedVersion || ! $this->dispatchAuthorizationEligible($request)) {
                    return null;
                }
                // This locked, current account check is the dispatch eligibility decision.
                // No revocation can commit on this account until authorization commits.
                $this->assertCurrentEligibility($account);
                $request->identity()->assertCurrentTarget($account);
                $this->assertLegacyEligible($request->identity());
                $attempt = NiumVirtualAccountDispatchAttempt::query()->create([
                    'operation_id' => $operation->id, 'consumed_at' => now(),
                ]);
                $request->update(['operation_state' => Operation::SUBMITTING, 'state_version' => $request->state_version + 1]);
                $readyToCommit = true;

                return $attempt;
            }, 1);
            if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
                throw new RuntimeException('Dispatch authorization commit could not be established.');
            }

            return $attempt;
        } catch (Throwable $exception) {
            if ($readyToCommit) {
                // Never return a capability or retry after an uncertain commit/acknowledgement.
                $connection->disconnect();
            }
            throw $exception;
        }
    }

    public function transition(NiumVirtualAccountRequest $request, int $expectedVersion, string $nextState, ?WebhookEvent $webhookEvidence = null): NiumVirtualAccountRequest
    {
        $this->assertTopLevel();

        return DB::transaction(function () use ($request, $expectedVersion, $nextState, $webhookEvidence): NiumVirtualAccountRequest {
            [$request, $operation] = $this->lockRequest($request);
            if ($webhookEvidence !== null) {
                app(NiumVirtualAccountWebhookRecovery::class)->assertClaim($webhookEvidence);
            }
            $this->assertTransition($request, $operation, $expectedVersion, $nextState);
            if (NiumVirtualAccountOperationState::isTerminal($nextState) || $nextState === Operation::SUBMITTING) {
                throw new RuntimeException('Dispatch and terminal resolution require their dedicated evidence primitives.');
            }

            $request->update(['operation_state' => $nextState, 'state_version' => $request->state_version + 1]);

            return $request;
        });
    }

    /**
     * Narrow Phase 1 resolution primitive: persisted, correlated direct POST evidence only.
     * Redacted target URLs are insufficient proof and deliberately fail closed.
     * Authenticated webhook receipts can corroborate this outcome; POST remains the causal proof.
     * Standalone webhook, GET-tag and manual terminal resolution require reviewed contracts.
     * Correlated malformed/missing IDs preserve evidence and return an unresolved request.
     */
    public function resolveFromPostEvidence(NiumVirtualAccountRequest $request, int $expectedVersion, ApiRequestLog $evidence, bool $attachVirtualAccount = false, ?WebhookEvent $webhookEvidence = null): NiumVirtualAccountRequest
    {
        $this->assertTopLevel();

        return DB::transaction(function () use ($request, $expectedVersion, $evidence, $attachVirtualAccount, $webhookEvidence): NiumVirtualAccountRequest {
            [$request, $operation, $scope, $account] = $this->lockRequest($request, allowTerminal: true);
            $evidence = ApiRequestLog::query()->lockForUpdate()->findOrFail($evidence->id);
            $exchange = $evidence->niumExchangeEvidence()->lockForUpdate()->firstOrFail();
            $snapshot = $request->identity()->snapshot();
            $fingerprint = $this->evidenceFingerprint($evidence, $exchange);
            if (NiumVirtualAccountOperationState::isTerminal($request->operation_state)) {
                if ($request->state_version === $expectedVersion
                    && $request->resolution_api_request_log_id === $evidence->id
                    && hash_equals($request->resolution_evidence_sha256, $fingerprint)) {
                    return $request;
                }
                throw new RuntimeException('Stale or conflicting terminal resolution evidence.');
            }
            $request->identity()->assertCurrentTarget($account);
            if ((int) $account->user_id !== $snapshot['user_id'] || (int) $account->provider_id !== $snapshot['provider_id']) {
                throw new RuntimeException('The assignment account ownership changed.');
            }
            $response = $this->verifyPostResponse($request, $operation, $evidence, $exchange, $snapshot);
            if ($webhookEvidence !== null) {
                $webhookEvidence = app(NiumVirtualAccountWebhookRecovery::class)->assertClaim($webhookEvidence);
                if (! NiumPaymentIdValidator::isValid($response['uniquePaymentId'] ?? null)
                    || ! NiumVirtualAccountWebhookContract::permitsAssignment($webhookEvidence, $request->identity(), $response['uniquePaymentId'])) {
                    throw new RuntimeException('Webhook evidence does not agree with this exact POST assignment.');
                }
            }

            $paymentId = $response['uniquePaymentId'] ?? null;
            $validPaymentId = NiumPaymentIdValidator::isValid($paymentId);
            $contradictorySuccess = $evidence->response_status === 200
                && ! NiumVirtualAccountAssignmentResponse::matchesTarget($response, $request->identity());
            if ($contradictorySuccess || (! $validPaymentId && ($evidence->response_status === 200 || array_key_exists('uniquePaymentId', $response)))) {
                // Resolution evidence fields are terminal-only. Keep the already-persisted log
                // and encrypted exchange intact; malformed IDs and contradictory target
                // evidence cannot resolve or attach a VA, or release ownership.
                if (in_array($request->operation_state, [Operation::UNKNOWN, Operation::MANUAL_REVIEW], true)) {
                    if ($request->state_version !== $expectedVersion || ! $request->dispatchAttempt()->exists()) {
                        throw new RuntimeException('Stale or non-monotonic assignment transition.');
                    }

                    return $request;
                }
                $this->assertTransition($request, $operation, $expectedVersion, Operation::UNKNOWN);
                $request->update(['operation_state' => Operation::UNKNOWN, 'state_version' => $request->state_version + 1]);

                return $request;
            }
            if ($evidence->response_status === 200 && ! isset($response['errors']) && $validPaymentId) {
                $nextState = Operation::ASSIGNED;
            } elseif ($snapshot['api_version'] === 'v2' && $evidence->response_status === 400 && $this->isValidationRejection($response)) {
                $nextState = Operation::FAILED;
                $paymentId = null;
            } else {
                throw new RuntimeException('Provider evidence does not establish a definitive assignment outcome.');
            }
            $this->assertTransition($request, $operation, $expectedVersion, $nextState);
            if ($nextState === Operation::ASSIGNED) {
                if (! NiumVirtualAccountWebhookContract::observedReceiptsPermitAssignment($request->identity(), $paymentId)) {
                    if ($request->operation_state !== Operation::MANUAL_REVIEW) {
                        $request->update(['operation_state' => Operation::MANUAL_REVIEW, 'state_version' => $request->state_version + 1]);
                    }

                    return $request;
                }
                $this->lockPaymentIdForWriter($paymentId);
                // A webhook or another operation may already own this identifier. Do not adopt
                // or rewrite that VA. Correlation/recovery of these cases belongs to Phase 3.
                $owned = NiumVirtualAccount::query()->where('provider_payment_id', $paymentId)->lockForUpdate()->exists()
                    || Operation::query()->where('provider_payment_id', $paymentId)->whereKeyNot($operation->id)->exists();
                if ($owned) {
                    if ($request->operation_state !== Operation::MANUAL_REVIEW) {
                        $request->update(['operation_state' => Operation::MANUAL_REVIEW, 'state_version' => $request->state_version + 1]);
                    }

                    return $request;
                }
            }
            $request->update([
                'resolution_api_request_log_id' => $evidence->id,
                'resolution_evidence_sha256' => $fingerprint,
                'state_version' => $request->state_version + 1,
                'operation_state' => $nextState,
                'outcome' => [
                    'provider_payment_id' => $paymentId, 'resolution_code' => 'verified_post_evidence',
                    'resolved_at' => now()->format('Y-m-d H:i:s'),
                ],
            ]);
            if ($attachVirtualAccount && $nextState === Operation::ASSIGNED) {
                $va = NiumVirtualAccount::query()->create([
                    'user_provider_account_id' => $operation->user_provider_account_id,
                    'provider_payment_id' => $paymentId, 'virtual_account_reference' => $paymentId,
                    'currency' => $operation->currency, 'account_category' => $operation->account_category,
                    'account_type' => $operation->account_type, 'status' => 'assigned', 'assigned_at' => now(),
                ]);
                $operation->refresh()->update(['nium_virtual_account_id' => $va->id]);
                app(NiumVirtualAccountDetailEnrichmentService::class)->attached($request, $va);
            }
            $scope->update(['active_operation_id' => null]);

            return $request;
        });
    }

    /** Read-only inspection. Resolution repeats these checks under ledger/evidence locks. */
    public function verifiedPostResponse(NiumVirtualAccountRequest $request, ApiRequestLog $evidence): array
    {
        $operation = $request->operation()->firstOrFail();
        $exchange = $evidence->niumExchangeEvidence()->firstOrFail();
        $snapshot = $request->identity()->snapshot();

        return $this->verifyPostResponse($request, $operation, $evidence, $exchange, $snapshot);
    }

    /** Read-only proof check for enrichment of an ALREADY assigned VA; never resolves state. */
    public function verifiedAssignmentResponse(NiumVirtualAccountRequest $request): array
    {
        $evidence = ApiRequestLog::query()->findOrFail($request->resolution_api_request_log_id);
        $exchange = $evidence->niumExchangeEvidence()->firstOrFail();
        if ($request->operation_state !== Operation::ASSIGNED
            || ! is_string($request->resolution_evidence_sha256)
            || ! hash_equals($request->resolution_evidence_sha256, $this->evidenceFingerprint($evidence, $exchange))) {
            throw new RuntimeException('VA details require the original terminal assignment evidence.');
        }
        $response = $this->verifiedPostResponse($request, $evidence);
        if ($evidence->response_status !== 200 || isset($response['errors'])
            || ! NiumPaymentIdValidator::isValid($response['uniquePaymentId'] ?? null)
            || ! NiumVirtualAccountAssignmentResponse::matchesTarget($response, $request->identity())) {
            throw new RuntimeException('VA details require a verified successful assignment.');
        }

        return $response;
    }

    private function verifyPostResponse(NiumVirtualAccountRequest $request, Operation $operation, ApiRequestLog $evidence, NiumApiExchangeEvidence $exchange, array $snapshot): array
    {
        $headers = $exchange->request_headers;
        $requestIds = $headers['x-request-id'] ?? null;
        $payload = json_decode((string) $exchange->raw_request_body, true, flags: JSON_THROW_ON_ERROR);
        $response = json_decode((string) $exchange->raw_response_body, true, flags: JSON_THROW_ON_ERROR);
        if (is_array($payload)) {
            ksort($payload, SORT_STRING);
        }
        $endpoint = '/api/'.$snapshot['api_version'].'/client/[REDACTED]/customer/[REDACTED]/wallet/[REDACTED]/paymentId';
        $exactTarget = $exchange->assignment_target ?? $exchange->request_url;
        try {
            $evidenceOrigin = NiumVirtualAccountRequestIdentity::origin($exactTarget);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Resolution evidence does not verify this immutable assignment request.', previous: $exception);
        }
        if ($evidence->provider_id !== $snapshot['provider_id'] || $evidence->user_id !== $snapshot['user_id']
            || $evidence->operation !== 'assign_payment_id' || $evidence->external_reference !== $operation->operation_id
            || $evidence->client_hash_id !== $snapshot['client_id'] || $evidence->request_method !== 'POST'
            || $evidence->endpoint_path !== $endpoint || $exchange->request_method !== 'POST'
            || parse_url($exactTarget, PHP_URL_PATH) !== $snapshot['endpoint']
            || $evidenceOrigin !== $snapshot['provider_origin']
            || parse_url($exactTarget, PHP_URL_QUERY) !== null
            || parse_url($exactTarget, PHP_URL_FRAGMENT) !== null
            || ! $exchange->response_received || $requestIds !== [$request->provider_request_id]
            || $payload !== $snapshot['payload'] || ! is_array($response)
            || ! is_string($exchange->request_body_sha256) || ! is_string($exchange->response_body_sha256)
            || ! hash_equals($exchange->request_body_sha256, hash('sha256', (string) $exchange->raw_request_body))
            || ! hash_equals($exchange->response_body_sha256, hash('sha256', (string) $exchange->raw_response_body))) {
            throw new RuntimeException('Resolution evidence does not verify this immutable assignment request.');
        }

        return $response;
    }

    /** Read-only advisory barrier. Must be rechecked inside the Phase 2 cutover/dispatch protocol. */
    public function legacyOperationEligibility(NiumVirtualAccountRequestIdentity $identity): array
    {
        $snapshot = $identity->snapshot();
        $legacy = Operation::query()->join('user_provider_accounts as a', 'a.id', '=', 'nium_virtual_account_operations.user_provider_account_id')
            ->join('integration_providers as p', 'p.id', '=', 'a.provider_id')
            ->whereRaw('LOWER(p.code) = ?', ['nium'])
            ->whereRaw('UPPER(nium_virtual_account_operations.currency) = ?', [$identity->currency()])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('nium_va_requests as r')
                ->whereColumn('r.operation_id', 'nium_virtual_account_operations.id'))
            ->where(function ($query) use ($snapshot): void {
                // Legacy rows have no frozen target/client/origin: unknown wallet mappings block conservatively.
                $query->whereRaw('LOWER(a.external_account_id) = ?', [strtolower($snapshot['wallet_id'])])
                    ->orWhere('a.id', $snapshot['provider_account_id'])
                    ->orWhereNull('a.external_account_id')->orWhere('a.external_account_id', '');
            })->select('nium_virtual_account_operations.*')->get();
        $blocking = $legacy->reject(fn (Operation $operation): bool => NiumVirtualAccountOperationState::hasConclusiveLegacyAssignment($operation))
            ->pluck('id')->all();

        return ['eligible' => $blocking === [], 'blocking_operation_ids' => $blocking];
    }

    private function assertLegacyEligible(NiumVirtualAccountRequestIdentity $identity): void
    {
        if (! $this->legacyOperationEligibility($identity)['eligible']) {
            throw new RuntimeException('Legacy unresolved or ambiguous assignment blocks this wallet and currency.');
        }
    }

    private function evidenceFingerprint(ApiRequestLog $evidence, NiumApiExchangeEvidence $exchange): string
    {
        $values = [
            $evidence->id, $evidence->provider_id, $evidence->user_id, $evidence->operation,
            $evidence->external_reference, $evidence->client_hash_id, $evidence->request_method,
            $evidence->endpoint_path, $evidence->response_status,
            $exchange->request_method, $exchange->request_url, $exchange->response_received,
            $exchange->raw_request_body, $exchange->raw_response_body, $exchange->request_headers,
            $exchange->request_body_sha256, $exchange->response_body_sha256,
        ];
        // Preserve fingerprints of historical Phase 1 evidence that has no target binding.
        if ($exchange->assignment_target !== null) {
            $values[] = $exchange->assignment_target;
        }

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** Collision quarantine among participating ledger resolvers, not a provider ownership domain. */
    public function lockPaymentIdForWriter(string $paymentId): void
    {
        // All participating callers must hold their provider account lock first.
        if (DB::transactionLevel() === 0 || ! NiumPaymentIdValidator::isValid($paymentId)) {
            throw new RuntimeException('Payment ID ownership checks require a valid ID and an active transaction.');
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // A repeatable-read snapshot taken before waiting could miss the winner's commit.
        // Fail closed rather than claim this check is safe at every isolation level.
        if (DB::selectOne('SHOW transaction_isolation')->transaction_isolation !== 'read committed') {
            throw new RuntimeException('Payment ID conflict checks require PostgreSQL READ COMMITTED.');
        }
        $lockKey = (string) hexdec(substr(hash('sha256', 'nium-va-collision:'.$paymentId), 0, 15));
        DB::select('SELECT pg_advisory_xact_lock(CAST(? AS bigint))', [$lockKey]);
        // The following ownership SELECTs run after acquiring the lock with fresh snapshots.
        // Historical/raw writers do not participate: H2 remains a Production blocker.
    }

    private function isValidationRejection(array $response): bool
    {
        $errors = $response['errors'] ?? null;
        if (! is_array($errors) || ! array_is_list($errors) || $errors === [] || array_key_exists('uniquePaymentId', $response)) {
            return false;
        }
        foreach ($errors as $error) {
            if (! is_array($error) || ! in_array($error['code'] ?? null, ['field_missing', 'invalid_input'], true)
                || ! is_string($error['field'] ?? null) || $error['field'] === '') {
                return false;
            }
        }

        return true;
    }

    private function assertTransition(NiumVirtualAccountRequest $request, Operation $operation, int $expectedVersion, string $nextState): void
    {
        if ($request->state_version !== $expectedVersion || ! $request->dispatchAttempt()->exists()
            || ! NiumVirtualAccountOperationState::canTransition($operation->status, $nextState)) {
            throw new RuntimeException('Stale or non-monotonic assignment transition.');
        }
    }

    private function assertTopLevel(): void
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('Ledger mutations require a top-level committed transaction.');
        }
        $connection->reconnectIfMissingConnection();
        if ($connection->getPdo()->inTransaction()) {
            throw new RuntimeException('Ledger mutations require a top-level committed transaction.');
        }
    }

    /** Lock order: provider account, scope, request, operation, then evidence and VA. */
    private function lockRequest(NiumVirtualAccountRequest $request, bool $allowTerminal = false): array
    {
        // Enrolled operation ownership is database-immutable. This non-locking lookup
        // lets every ledger mutation take the account lock before any other row lock.
        $accountId = Operation::query()->whereKey($request->operation_id)->value('user_provider_account_id');
        $account = UserProviderAccount::query()->lockForUpdate()->findOrFail($accountId);
        $scope = NiumVirtualAccountAssignmentScope::query()->lockForUpdate()->findOrFail($request->scope_key);
        $request = NiumVirtualAccountRequest::query()->lockForUpdate()->findOrFail($request->operation_id);
        $operation = Operation::query()->lockForUpdate()->findOrFail($request->operation_id);
        if ((! ($allowTerminal && NiumVirtualAccountOperationState::isTerminal($request->operation_state))
                && $scope->active_operation_id !== $operation->id) || $request->scope_key !== $scope->scope_key
            || $operation->user_provider_account_id !== $account->id
            || $request->identity()->snapshot()['provider_account_id'] !== (int) $account->id
            || $operation->status !== $request->operation_state
            || $request->identity()->scopeKey() !== $scope->scope_key
            || ! hash_equals($request->payload_sha256, $request->identity()->payloadHash())) {
            throw new RuntimeException('The assignment scope is not owned by this immutable request.');
        }

        return [$request, $operation, $scope, $account];
    }

    /** Caller holds this provider account FOR UPDATE until the top-level commit. */
    private function assertCurrentEligibility(UserProviderAccount $account): void
    {
        $eligible = app(NiumProviderAccountStateService::class)->assertEligible($account->user);
        if ((int) $eligible->id !== (int) $account->id) {
            throw new RuntimeException('The requested Nium provider account is not the current eligible account.');
        }
    }

    private function lockScope(string $scopeKey): NiumVirtualAccountAssignmentScope
    {
        DB::table('nium_va_assignment_scopes')->insertOrIgnore([
            'scope_key' => $scopeKey, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return NiumVirtualAccountAssignmentScope::query()->lockForUpdate()->findOrFail($scopeKey);
    }
}
