<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\NiumVirtualAccount;
use App\Models\NiumVirtualAccountOperation as Operation;
use App\Models\NiumVirtualAccountRequest;
use App\Models\UserProviderAccount;
use App\Services\Integrations\ProviderHttpClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Inactive Phase 2 orchestrator. Never reconcile or recover by repeating a POST. */
final class NiumVirtualAccountAssignmentService
{
    public function __construct(private readonly NiumVirtualAccountSafetyLedger $ledger) {}

    public function assign(UserProviderAccount $account, string $operationId, string $currency, string $category, string $type, ?string $bank, string $apiVersion = 'v2'): NiumVirtualAccount|Operation
    {
        $eligible = app(NiumProviderAccountStateService::class)->assertEligible($account->user);
        if ((int) $eligible->id !== (int) $account->id) {
            throw new RuntimeException('The requested Nium provider account is not the current eligible account.');
        }
        $account = $eligible;
        $existing = NiumVirtualAccountRequest::query()->whereIn('operation_id', Operation::query()
            ->where('user_provider_account_id', $account->id)->where('operation_id', $operationId)->select('id'))->first();
        $effectiveBank = $bank ?? ($existing !== null
            ? ($existing->identity()->snapshot()['payload']['bankName'] ?? null)
            : app(NiumPaymentIdBankResolver::class)->resolve($account->user, $currency));
        $payload = NiumVirtualAccountAssignmentContract::payload($apiVersion, $currency, $category, $type, $effectiveBank);
        $identity = NiumVirtualAccountRequestIdentity::forAccount($account, $payload, $apiVersion, $apiVersion === 'v1' ? $type : null);
        if ($existing !== null && ! $existing->matchesIdentity($identity)) {
            throw new RuntimeException('The operation identifier is already bound to another immutable assignment request.');
        }
        // Replays return state, including after a crash or disabling the gate. A claimed replay
        // is not a recovery dispatcher: only this call's freshly prepared operation may send.
        if ($existing !== null) {
            return $this->result($existing);
        }
        $this->assertEnabled($account, $apiVersion === 'v1'
            && NiumVirtualAccountAssignmentContract::isConfirmedV1Corridor($currency, $category, $type, $effectiveBank));
        app(NiumService::class)->assertConfigured();
        $request = $this->ledger->prepare($account, $operationId, $identity);
        // Concurrent prepare may return the winner's row. Consumption arbitrates both workers.
        $snapshot = $request->identity()->snapshot();
        $client = new ProviderHttpClient(
            provider: $account->provider, serviceConfigKey: 'nium',
            headers: ['x-request-id' => $request->provider_request_id],
            operationalContext: ['operation' => 'assign_payment_id', 'external_reference' => $operationId, 'client_hash_id' => $snapshot['client_id']],
            singleDispatchAssignment: true,
        );
        $attempt = $this->ledger->consumeDispatchAuthorization($request, 0);
        if ($attempt === null) {
            return $this->result($request->fresh());
        }
        // This is the only HTTP dispatch site. The ledger returned only after a top-level,
        // durable commit. Never load an old attempt as permission, and never retry this block.
        try {
            $response = $client->post($snapshot['provider_origin'].$snapshot['endpoint'], $snapshot['payload'], $account->user);
            $data = $response->json();
            $matchesTarget = is_array($data) && NiumVirtualAccountAssignmentResponse::matchesTarget($data, $request->identity());
            if ($response->status() === 200 && is_array($data) && ! isset($data['errors'])
                && $matchesTarget
                && NiumPaymentIdValidator::isValid($data['uniquePaymentId'] ?? null)) {
                $evidence = ApiRequestLog::query()->where('provider_id', $snapshot['provider_id'])
                    ->where('user_id', $snapshot['user_id'])->where('operation', 'assign_payment_id')
                    ->where('external_reference', $operationId)
                    ->whereHas('niumExchangeEvidence')->latest('id')->firstOrFail();
                $this->ledger->resolveFromPostEvidence($request, 1, $evidence, attachVirtualAccount: true);
            } else {
                $initialized = is_array($data) && NiumVirtualAccountAssignmentResponse::isDelayed($data, $request->identity(), $response->status());
                $this->ledger->transition($request, 1, $initialized ? Operation::PENDING : Operation::UNKNOWN);
            }
        } catch (Throwable $exception) {
            Log::warning('Nium VA assignment outcome requires review.', [
                'provider_request_id' => $request->provider_request_id, 'failure_type' => $exception::class,
            ]);
            // Acceptance or even successful local commit may already have occurred. Preserve
            // terminal winners; on persistence failure the durable submitting state still holds.
            $current = $request->fresh();
            if ($current->operation_state === Operation::SUBMITTING && $current->state_version === 1) {
                try {
                    $this->ledger->transition($current, 1, Operation::UNKNOWN);
                } catch (Throwable) {
                    // No database outcome here can restore dispatch authorization.
                }
            }
        }

        return $this->result($request->fresh());
    }

    /** Allocation admission only. Receipt, state reads and GET recovery do not call this. */
    public static function allocationBlockReason(): ?string
    {
        if (config('services.nium.va_single_dispatch.allocation_paused') !== false) {
            return 'allocation_paused';
        }
        if (! app()->environment('testing')) {
            return 'allocation_testing_only';
        }
        if (config('services.nium.va_single_dispatch.enabled') !== true) {
            return 'allocation_unavailable';
        }
        if (config('services.nium.va_single_dispatch.old_workers_fenced') !== true) {
            return 'allocation_cutover_required';
        }

        return null;
    }

    private function assertEnabled(UserProviderAccount $account, bool $confirmedCorridor): void
    {
        $reason = self::allocationBlockReason();
        if ($reason === 'allocation_paused') {
            throw new RuntimeException('Nium VA single dispatch is inactive: new allocation is paused; saved requests remain available for status recovery.');
        }
        if ($reason !== null || (! $confirmedCorridor
            && ! in_array((int) $account->id, config('services.nium.va_single_dispatch.account_ids', []), true))) {
            throw new RuntimeException('Nium VA single dispatch is inactive: isolated tests only until Phase 3 reconciliation and fenced cutover.');
        }
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('Nium VA dispatch requires a top-level committed transaction.');
        }
    }

    private function result(NiumVirtualAccountRequest $request): NiumVirtualAccount|Operation
    {
        $operation = $request->operation()->firstOrFail();

        return $operation->status === Operation::ASSIGNED && $operation->nium_virtual_account_id !== null
            ? $operation->virtualAccount()->firstOrFail() : $operation;
    }
}
