<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountOperation;
use App\Models\NiumVirtualAccountRequest;
use App\Models\UserProviderAccount;
use App\Models\WebhookEvent;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class NiumVirtualAccountReconciliationService
{
    private const PAGE_SIZE = 20;

    private const MAX_PAGES = 5;

    public function __construct(private readonly NiumService $niumService) {}

    public function captureBaseline(NiumVirtualAccountOperation $operation): NiumVirtualAccountOperation
    {
        $operation = $operation->fresh();
        if ($this->enrolledRequest($operation) !== null || $operation->submitted_at !== null
            || ! in_array($operation->status, [NiumVirtualAccountOperation::CLAIMED, NiumVirtualAccountOperation::PREFLIGHT_FAILED], true)) {
            return $operation;
        }
        try {
            $result = $this->fetch($operation);
        } catch (Throwable) {
            $result = ['items' => [], 'complete' => false];
        }

        return DB::transaction(function () use ($operation, $result): NiumVirtualAccountOperation {
            UserProviderAccount::query()->lockForUpdate()->findOrFail($operation->user_provider_account_id);
            $locked = NiumVirtualAccountOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($this->enrolledRequest($locked) !== null || $locked->submitted_at !== null
                || (int) $locked->user_provider_account_id !== (int) $operation->user_provider_account_id
                || ! in_array($locked->status, [NiumVirtualAccountOperation::CLAIMED, NiumVirtualAccountOperation::PREFLIGHT_FAILED], true)) {
                return $locked;
            }
            $locked->update([
                'status' => $result['complete'] ? NiumVirtualAccountOperation::CLAIMED : NiumVirtualAccountOperation::PREFLIGHT_FAILED,
                'baseline_payment_ids' => array_values(array_unique(array_filter(array_map(
                    fn (array $item): ?string => $this->paymentId($item), $result['items'],
                )))),
                'baseline_complete' => $result['complete'],
                'resolution_code' => $result['complete'] ? null : 'baseline_lookup_incomplete',
            ]);

            return $locked->fresh();
        });
    }

    public function reconcile(NiumVirtualAccountOperation $operation): NiumVirtualAccountOperation
    {
        $operation = $operation->fresh();
        if (($request = $this->enrolledRequest($operation)) !== null) {
            return $this->reconcileEnrolled($request);
        }

        if (in_array($operation->status, [NiumVirtualAccountOperation::ASSIGNED, NiumVirtualAccountOperation::FAILED], true)) {
            return $operation;
        }

        try {
            $paymentId = app(NiumLegacyVirtualAccountEvidence::class)->assignedPaymentId(
                $operation, $operation->providerAccount()->firstOrFail(),
            );
        } catch (Throwable) {
            // A GET remains useful evidence, but cannot manufacture POST causality.
            // fetch() validates and uses original target proof, never today's mapping.
            try {
                $this->fetch($operation);
            } catch (Throwable) {
                // Existing exchange evidence is retained; do not POST or guess an owner.
            }

            return $this->legacyReview($operation, 'legacy_assignment_evidence_unproven');
        }

        return $this->assign($operation, $paymentId, 'verified_legacy_post_evidence');
    }

    public function assign(
        NiumVirtualAccountOperation $operation,
        string $paymentId,
        string $resolutionCode,
        ?WebhookEvent $webhook = null,
    ): NiumVirtualAccountOperation {
        return DB::transaction(function () use ($operation, $paymentId, $resolutionCode, $webhook): NiumVirtualAccountOperation {
            // Same ordering and same literal-ID lock as the durable ledger resolver.
            $account = UserProviderAccount::query()->lockForUpdate()->findOrFail($operation->user_provider_account_id);
            $locked = NiumVirtualAccountOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($this->enrolledRequest($locked) !== null) {
                throw new RuntimeException('Enrolled assignments require verified ledger evidence resolution.');
            }
            if ((int) $locked->user_provider_account_id !== (int) $account->id) {
                throw new RuntimeException('Legacy operation account changed while waiting for its lock.');
            }
            if (in_array($locked->status, [NiumVirtualAccountOperation::ASSIGNED, NiumVirtualAccountOperation::FAILED], true)) {
                // Preserve terminal historical records, including on a conflicting direct call.
                if ($locked->status === NiumVirtualAccountOperation::ASSIGNED && $locked->provider_payment_id === $paymentId) {
                    return $locked->fresh('virtualAccount');
                }
                throw new RuntimeException('A terminal legacy assignment cannot be reassigned.');
            }
            try {
                $proof = app(NiumLegacyVirtualAccountEvidence::class);
                $identity = $proof->target($locked, $account);
                if (! NiumPaymentIdValidator::isValid($paymentId) || $proof->assignedPaymentId($locked, $account) !== $paymentId
                    || ! NiumVirtualAccountWebhookContract::observedReceiptsPermitAssignment($identity, $paymentId)) {
                    throw new RuntimeException('legacy_assignment_evidence_conflict');
                }
                if ($webhook !== null) {
                    app(NiumVirtualAccountWebhookRecovery::class)->assertClaim($webhook);
                    if (! NiumVirtualAccountWebhookContract::permitsAssignment($webhook, $identity, $paymentId)) {
                        throw new RuntimeException('legacy_webhook_evidence_conflict');
                    }
                }
            } catch (Throwable) {
                return $this->markLegacyReview($locked, 'legacy_assignment_evidence_unproven');
            }

            app(NiumVirtualAccountSafetyLedger::class)->lockPaymentIdForWriter($paymentId);
            $candidate = $locked->nium_virtual_account_id === null ? null
                : NiumVirtualAccount::query()->lockForUpdate()->find($locked->nium_virtual_account_id);
            $ownedElsewhere = NiumVirtualAccount::query()->where('provider_payment_id', $paymentId)
                ->when($candidate !== null, fn ($q) => $q->whereKeyNot($candidate->id))->exists();
            $otherOperation = NiumVirtualAccountOperation::query()->whereKeyNot($locked->id)
                ->where(function ($q) use ($paymentId, $candidate): void {
                    $q->where('provider_payment_id', $paymentId);
                    if ($candidate !== null) {
                        $q->orWhere('nium_virtual_account_id', $candidate->id);
                    }
                })->exists();
            if ($ownedElsewhere || $otherOperation || (filled($locked->provider_payment_id) && $locked->provider_payment_id !== $paymentId)) {
                return $this->markLegacyReview($locked, 'payment_id_already_owned');
            }
            if ($locked->nium_virtual_account_id !== null && ($candidate === null
                || (int) $candidate->user_provider_account_id !== (int) $account->id
                || $candidate->currency !== $locked->currency || $candidate->account_category !== $locked->account_category
                || $candidate->account_type !== $locked->account_type
                || (filled($candidate->virtual_account_reference) && $candidate->virtual_account_reference !== $paymentId)
                || (! blank($candidate->provider_payment_id) && $candidate->provider_payment_id !== $paymentId)
                || ! in_array($candidate->status, ['pending', 'assigned'], true)
                || ($candidate->status === 'assigned' && $candidate->provider_payment_id !== $paymentId))) {
                // An unsuitable existing reference is a conflict, never permission to create.
                return $this->markLegacyReview($locked, 'legacy_virtual_account_ownership_conflict');
            }
            if ($candidate === null) {
                $candidate = NiumVirtualAccount::query()->create([
                    'user_provider_account_id' => $account->id,
                    'currency' => $locked->currency, 'account_category' => $locked->account_category,
                    'account_type' => $locked->account_type, 'status' => 'pending',
                ]);
            }
            $candidate->update([
                'provider_payment_id' => $paymentId, 'virtual_account_reference' => $paymentId,
                'status' => 'assigned', 'assigned_at' => $candidate->assigned_at ?? now(),
            ]);
            $locked->update([
                'nium_virtual_account_id' => $candidate->id, 'provider_payment_id' => $paymentId,
                'status' => NiumVirtualAccountOperation::ASSIGNED, 'resolution_code' => $resolutionCode,
                'resolved_at' => now(),
            ]);

            return $locked->fresh('virtualAccount');
        });
    }

    public function legacyReview(NiumVirtualAccountOperation $operation, string $reason, ?WebhookEvent $webhook = null): NiumVirtualAccountOperation
    {
        return DB::transaction(function () use ($operation, $reason, $webhook): NiumVirtualAccountOperation {
            $account = UserProviderAccount::query()->lockForUpdate()->findOrFail($operation->user_provider_account_id);
            $locked = NiumVirtualAccountOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($this->enrolledRequest($locked) !== null
                || (int) $locked->user_provider_account_id !== (int) $operation->user_provider_account_id) {
                throw new RuntimeException('Legacy review cannot mutate enrolled history.');
            }
            if ($webhook !== null) {
                app(NiumVirtualAccountWebhookRecovery::class)->assertClaim($webhook);
                $identity = app(NiumLegacyVirtualAccountEvidence::class)->target($locked, $account);
                $payload = NiumVirtualAccountWebhookContract::payload($webhook);
                if (! NiumVirtualAccountWebhookContract::permitsAssignment($webhook, $identity, $payload['uniquePaymentId'] ?? '')) {
                    throw new RuntimeException('Legacy review requires a matching authenticated receipt.');
                }
            }

            return $this->markLegacyReview($locked, $reason);
        });
    }

    private function markLegacyReview(NiumVirtualAccountOperation $operation, string $reason): NiumVirtualAccountOperation
    {
        if (! in_array($operation->status, [NiumVirtualAccountOperation::ASSIGNED, NiumVirtualAccountOperation::FAILED], true)) {
            $operation->update(['status' => NiumVirtualAccountOperation::MANUAL_REVIEW, 'resolution_code' => $reason]);
        }

        return $operation->fresh('virtualAccount');
    }

    private function enrolledRequest(NiumVirtualAccountOperation $operation): ?NiumVirtualAccountRequest
    {
        return NiumVirtualAccountRequest::query()->find($operation->id);
    }

    private function reconcileEnrolled(NiumVirtualAccountRequest $request, ?WebhookEvent $webhook = null): NiumVirtualAccountOperation
    {
        if (NiumVirtualAccountOperationState::isTerminal($request->operation_state)
            || ! $request->dispatchAttempt()->exists()) {
            // A prepared request is not permission to dispatch or to fabricate consumption.
            return $request->operation()->firstOrFail();
        }
        $ledger = app(NiumVirtualAccountSafetyLedger::class);
        $snapshot = $request->identity()->snapshot();
        try {
            $account = $request->operation->providerAccount()->with('user')->firstOrFail();
            $request->identity()->assertCurrentTarget($account);
            if ((int) $account->user_id !== $snapshot['user_id'] || (int) $account->provider_id !== $snapshot['provider_id']) {
                throw new RuntimeException('The assignment account ownership changed.');
            }
            $evidence = $this->postEvidence($request);
            if ($evidence['review'] !== null) {
                return $this->review($request, $evidence['review'], $webhook);
            }
            $successes = $evidence['successes'];
            if (count($successes) === 1) {
                if ($webhook !== null && ! NiumVirtualAccountWebhookContract::permitsAssignment($webhook, $request->identity(), (string) array_key_first($successes))) {
                    return $this->unresolved($request, 'webhook_without_exact_post_agreement');
                }
                $ledger->resolveFromPostEvidence($request, $request->state_version, array_values($successes)[0], attachVirtualAccount: true, webhookEvidence: $webhook);

                return $request->operation()->firstOrFail();
            }

            if ($webhook !== null) {
                return $this->unresolved($request, 'webhook_without_causal_post_evidence');
            }
            $result = $this->fetch($request->operation, $request);
            if (! $result['complete']) {
                return $this->unresolved($request, 'incomplete_provider_listing');
            }
            $ids = [];
            foreach ($result['items'] as $item) {
                if (! NiumPaymentIdValidator::isValid($item['uniquePaymentId'] ?? null)) {
                    return $this->unresolved($request, 'malformed_listing_payment_id');
                }
                if (! NiumVirtualAccountAssignmentResponse::matchesTarget($item, $request->identity())) {
                    return $this->review($request, 'contradictory_listing_dimensions');
                }
                $ids[$item['uniquePaymentId']] = true;
            }
            if ($ids !== []) {
                // Neither response tags nor a GET request's local operation label prove creation.
                // No operation-tagged GET contract is demonstrably supported in this repository.
                return $this->review($request, count($ids) > 1 ? 'ambiguous_payment_ids' : 'insufficient_operation_correlation');
            }

            return $this->unresolved($request, 'no_payment_id_observed');
        } catch (Throwable $exception) {
            // Includes stale workers, transport failures and rolled-back resolution writes.
            // Never overwrite a terminal winner or change state merely because reads failed.
            Log::notice('Nium VA reconciliation remains unresolved.', [
                'operation_id' => $request->operation_id, 'failure_type' => $exception::class,
            ]);

            $current = $request->operation()->firstOrFail();
            if ($webhook !== null && ! NiumVirtualAccountOperationState::isTerminal($current->status)) {
                throw new RuntimeException('Nium VA webhook resolution failed.', previous: $exception);
            }

            return $current;
        }
    }

    /** Same exact POST evidence arbitration for polling and authenticated webhook triggers. */
    private function postEvidence(NiumVirtualAccountRequest $request): array
    {
        $snapshot = $request->identity()->snapshot();
        // Inspect every potentially bound exchange; never choose the first/latest ID.
        $logs = ApiRequestLog::query()->where('provider_id', $snapshot['provider_id'])
            ->where('user_id', $snapshot['user_id'])->where('operation', 'assign_payment_id')
            ->where('external_reference', $request->operation->operation_id)
            ->whereHas('niumExchangeEvidence')->get();
        $successes = [];
        foreach ($logs as $log) {
            try {
                $response = app(NiumVirtualAccountSafetyLedger::class)->verifiedPostResponse($request, $log);
            } catch (Throwable) {
                // A key alone is not correlation. Wrong UUID/target/hash/payload is unrelated.
                continue;
            }
            if ($log->response_status !== 200) {
                continue;
            }
            if (! NiumVirtualAccountAssignmentResponse::matchesTarget($response, $request->identity())
                || isset($response['errors'])) {
                return ['successes' => [], 'review' => 'contradictory_post_evidence'];
            }
            if (NiumVirtualAccountAssignmentResponse::isDelayed($response, $request->identity(), 200)) {
                continue;
            }
            if (! NiumPaymentIdValidator::isValid($response['uniquePaymentId'] ?? null)) {
                return ['successes' => [], 'review' => 'malformed_post_payment_id'];
            }
            $successes[$response['uniquePaymentId']] = $log;
        }

        return ['successes' => $successes, 'review' => count($successes) > 1 ? 'conflicting_post_payment_ids' : null];
    }

    public function hasCorrelatedPostPaymentId(NiumVirtualAccountRequest $request, string $paymentId): bool
    {
        $result = $this->postEvidence($request);

        return $result['review'] === null && count($result['successes']) === 1 && isset($result['successes'][$paymentId]);
    }

    /** No GET or POST: a webhook may trigger only the existing direct-evidence resolver. */
    public function reconcileWebhook(NiumVirtualAccountRequest $request, WebhookEvent $event): NiumVirtualAccountOperation
    {
        return $this->reconcileEnrolled($request->fresh(), $event);
    }

    private function review(NiumVirtualAccountRequest $request, string $reason, ?WebhookEvent $webhook = null): NiumVirtualAccountOperation
    {
        if ($request->operation_state !== NiumVirtualAccountOperation::MANUAL_REVIEW) {
            app(NiumVirtualAccountSafetyLedger::class)->transition($request, $request->state_version, NiumVirtualAccountOperation::MANUAL_REVIEW, $webhook);
        }

        return $this->unresolved($request, $reason);
    }

    private function unresolved(NiumVirtualAccountRequest $request, string $reason): NiumVirtualAccountOperation
    {
        // The immutable snapshot/evidence stay private. Unresolved evidence remains in existing
        // API logs/exchanges under the operation key; terminal references are ledger-protected.
        Log::notice('Nium VA reconciliation requires authoritative evidence.', [
            'operation_id' => $request->operation_id, 'reason' => $reason,
        ]);

        return $request->operation()->firstOrFail();
    }

    /** Strict enrolled GET V2 page contract. No short-page or unknown-envelope fallback. */
    private function enrolledPage(array $data, int $page, ?int &$totalPages): array
    {
        if (! array_key_exists('content', $data) || ! is_array($data['content']) || ! array_is_list($data['content'])
            || count($data['content']) > self::PAGE_SIZE || isset($data['errors'])
            || array_key_exists('data', $data) || array_key_exists('paymentIds', $data) || array_key_exists('page', $data)) {
            throw new RuntimeException('Unexpected payment ID listing schema.');
        }
        foreach ($data['content'] as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new RuntimeException('Malformed payment ID listing item.');
            }
        }
        foreach (['number' => $page, 'size' => self::PAGE_SIZE, 'numberOfElements' => count($data['content']),
            'empty' => $data['content'] === [], 'first' => $page === 0] as $key => $expected) {
            if (array_key_exists($key, $data) && $data[$key] !== $expected) {
                throw new RuntimeException('Unexpected payment ID page identity.');
            }
        }
        if (array_key_exists('totalPages', $data)) {
            if (! is_int($data['totalPages']) || $data['totalPages'] < 0
                || ($totalPages !== null && $data['totalPages'] !== $totalPages)
                || ($data['totalPages'] === 0 && ($page !== 0 || $data['content'] !== []))
                || ($data['totalPages'] > 0 && $page >= $data['totalPages'])) {
                throw new RuntimeException('Invalid or changing pagination total.');
            }
            $totalPages = $data['totalPages'];
        } elseif ($totalPages !== null) {
            throw new RuntimeException('Missing pagination total.');
        }
        if (array_key_exists('last', $data) && ! is_bool($data['last'])) {
            throw new RuntimeException('Invalid pagination last flag.');
        }
        if ($totalPages === null && ! array_key_exists('last', $data)) {
            throw new RuntimeException('Pagination completeness is unverified.');
        }
        $complete = $totalPages !== null ? $page + 1 >= $totalPages : $data['last'];
        if ((array_key_exists('last', $data) && $data['last'] !== $complete)
            || (! $complete && count($data['content']) !== self::PAGE_SIZE)) {
            throw new RuntimeException('Contradictory or partial payment ID page.');
        }

        return ['items' => $data['content'], 'complete' => $complete];
    }

    /** @return array{items: list<array>, complete: bool} */
    private function fetch(NiumVirtualAccountOperation $operation, ?NiumVirtualAccountRequest $request = null): array
    {
        $account = $operation->providerAccount()->with('user')->firstOrFail();
        $items = [];
        $complete = false;
        $totalPagesSeen = null;
        $seenIds = [];
        $totalElements = null;
        $client = null;
        if ($request !== null) {
            $snapshot = $request->identity()->snapshot();
            $request->identity()->assertCurrentTarget($account);
            if ((int) $account->user_id !== $snapshot['user_id'] || (int) $account->provider_id !== $snapshot['provider_id']
                || config('services.nium.payment_ids_endpoint') !== '/api/v2/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentIds') {
                throw new RuntimeException('The supported read-only assignment target cannot be verified.');
            }
            $this->niumService->assertConfigured();
            $client = new ProviderHttpClient(
                provider: $account->provider, serviceConfigKey: 'nium',
                headers: ['x-request-id' => (string) Str::uuid()],
                operationalContext: ['operation' => 'reconcile_virtual_account_assignment',
                    'external_reference' => $operation->operation_id, 'client_hash_id' => $snapshot['client_id']],
                readOnlyVirtualAccountReconciliation: true,
            );
        }

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            if ($request !== null) {
                // V1 POSTs use the existing V2 read contract; no V1 GET contract is invented.
                $path = '/api/v2/client/'.rawurlencode($snapshot['client_id']).'/customer/'
                    .rawurlencode($snapshot['customer_id']).'/wallet/'.rawurlencode($snapshot['wallet_id']).'/paymentIds';
                $response = $client->get($snapshot['provider_origin'].$path, [
                    'currencyCode' => $request->identity()->currency(), 'status' => 'Active',
                    'page' => $page, 'size' => self::PAGE_SIZE, 'order' => 'DESC', 'property' => 'createdAt',
                ], $account->user);
            } else {
                $identity = app(NiumLegacyVirtualAccountEvidence::class)->target($operation, $account);
                $legacy = $identity->snapshot();
                $this->niumService->assertConfigured();
                $legacyClient = new ProviderHttpClient(
                    provider: $account->provider, serviceConfigKey: 'nium',
                    headers: ['x-request-id' => (string) Str::uuid()],
                    operationalContext: ['operation' => 'reconcile_virtual_account_assignment',
                        'external_reference' => $operation->operation_id, 'client_hash_id' => $legacy['client_id']],
                    readOnlyVirtualAccountReconciliation: true,
                );
                $path = '/api/v2/client/'.rawurlencode($legacy['client_id']).'/customer/'
                    .rawurlencode($legacy['customer_id']).'/wallet/'.rawurlencode($legacy['wallet_id']).'/paymentIds';
                $response = $legacyClient->get($legacy['provider_origin'].$path, [
                    'currencyCode' => $operation->currency, 'status' => 'Active', 'page' => $page,
                    'size' => self::PAGE_SIZE, 'order' => 'DESC', 'property' => 'createdAt',
                ], $account->user);
            }

            if (! $response->successful()) {
                throw new RuntimeException('Failed fetching Nium payment IDs for reconciliation.');
            }

            if ($request !== null) {
                $data = $response->json();
                if ($response->status() !== 200 || ! is_array($data) || array_is_list($data)) {
                    throw new RuntimeException('Invalid payment ID listing response.');
                }
                $parsed = $this->enrolledPage($data, $page, $totalPagesSeen);
                if (array_key_exists('totalElements', $data)) {
                    if (! is_int($data['totalElements']) || $data['totalElements'] < 0
                        || ($totalElements !== null && $totalElements !== $data['totalElements'])) {
                        throw new RuntimeException('Invalid or changing pagination count.');
                    }
                    $totalElements = $data['totalElements'];
                } elseif ($totalElements !== null) {
                    throw new RuntimeException('Missing pagination count.');
                }
                foreach ($parsed['items'] as $item) {
                    $id = $item['uniquePaymentId'] ?? null;
                    if (! NiumPaymentIdValidator::isValid($id) || isset($seenIds[$id])) {
                        throw new RuntimeException('Malformed or repeated payment ID listing.');
                    }
                    $seenIds[$id] = true;
                }
                array_push($items, ...$parsed['items']);
                $complete = $parsed['complete'];
                if ($complete) {
                    if ($totalElements !== null && count($items) !== $totalElements) {
                        throw new RuntimeException('Partial payment ID listing.');
                    }
                    break;
                }

                continue;
            }

            $data = (array) ($response->json() ?? []);
            $pageItems = $this->items($data);
            array_push($items, ...$pageItems);

            $totalPages = data_get($data, 'totalPages', data_get($data, 'page.totalPages'));
            $last = data_get($data, 'last', data_get($data, 'page.last'));

            if (is_numeric($totalPages)) {
                $complete = $page + 1 >= (int) $totalPages;
            } elseif (is_bool($last)) {
                $complete = $last;
            } else {
                $complete = count($pageItems) < self::PAGE_SIZE;
            }

            if ($complete) {
                break;
            }
        }

        return ['items' => $items, 'complete' => $complete];
    }

    /** @return list<array> */
    private function items(array $data): array
    {
        $items = $data['content'] ?? $data['paymentIds'] ?? data_get($data, 'data.content') ?? $data['data'] ?? [];

        return is_array($items)
            ? array_values(array_filter($items, static fn ($item): bool => is_array($item)))
            : [];
    }

    private function paymentId(array $item): ?string
    {
        $paymentId = $item['uniquePaymentId'] ?? $item['paymentId'] ?? $item['virtualAccountNumber'] ?? null;

        return is_string($paymentId) && $paymentId !== '' ? $paymentId : null;
    }
}
