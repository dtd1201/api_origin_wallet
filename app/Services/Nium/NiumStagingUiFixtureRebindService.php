<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\UserProviderAccount;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NiumStagingUiFixtureRebindService
{
    public const ACCOUNT_ID = 7;

    public const USER_ID = 9;

    public const PROVIDER_ID = 7;

    public const CUSTOMER_HASH_ID = '3bb08685-06ad-4d1d-9823-983c45a3d1c5';

    public const WALLET_HASH_ID = '095bbbb7-f1e5-42ec-80ff-2a6e579c1847';

    public const APPROVAL = 'REBIND_STAGING_NIUM_UI_FLOW_FIXTURE_ACCOUNT_7';

    public const AUDIT_ACTION = 'provider_account.nium_staging_ui_fixture_rebind';

    public const EVIDENCE_OPERATION = 'staging_ui_fixture_rebind_customer_get';

    private const REASON = 'staging_ui_flow_test_fixture_rebind';

    public function __construct(
        private readonly NiumService $niumService,
        private readonly NiumProviderAccountStateService $stateService,
        private readonly NiumAuthenticatedStateProjector $stateProjector,
        private readonly NiumSafeValueProjector $safeValues,
    ) {}

    public function rebind(string $approval, string $operatorContext): UserProviderAccount
    {
        if (! app()->environment('staging')) {
            throw new RuntimeException('Nium UI fixture rebind is staging-only.');
        }

        if (! hash_equals(self::APPROVAL, $approval)) {
            throw new RuntimeException('Nium UI fixture rebind approval marker is invalid.');
        }

        $operatorContext = trim($operatorContext);

        if ($operatorContext === '') {
            throw new RuntimeException('Nium UI fixture rebind requires operator and ticket context.');
        }

        if ($this->auditExists()) {
            throw new RuntimeException('Nium UI fixture rebind was already executed.');
        }

        $account = $this->fixtureAccount();
        $evidenceFloorId = (int) (ApiRequestLog::query()->max('id') ?? 0);
        $response = $this->niumService->get(
            path: $this->niumService->path(
                (string) config('services.nium.customer_get_endpoint'),
                [
                    'client' => $this->niumService->clientId(),
                    'customer' => self::CUSTOMER_HASH_ID,
                ],
            ),
            user: $account->user,
            operation: self::EVIDENCE_OPERATION,
        );
        $payload = $response->json() ?? [];
        $evidence = ApiRequestLog::query()
            ->where('provider_id', self::PROVIDER_ID)
            ->where('user_id', self::USER_ID)
            ->where('operation', self::EVIDENCE_OPERATION)
            ->where('id', '>', $evidenceFloorId)
            ->latest('id')
            ->first();

        $this->assertAuthenticatedResponse($response->status(), $payload, $evidence);

        return DB::transaction(function () use ($approval, $evidence, $operatorContext, $payload): UserProviderAccount {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->first();
            $this->assertFixtureAccount($account);
            $this->assertLockedFixtureState($account);

            if ($this->auditExists()) {
                throw new RuntimeException('Nium UI fixture rebind was already executed.');
            }

            $externalId = trim((string) $payload['externalId']);

            if (hash_equals($externalId, (string) $account->external_reference)) {
                throw new RuntimeException('Authenticated Nium externalId must differ from Account 7 current external reference.');
            }

            $this->assertIdentifierOwnership($externalId);
            $oldState = $this->lifecycleState($account);

            $account->forceFill([
                'external_reference' => $externalId,
                'external_customer_id' => self::CUSTOMER_HASH_ID,
                'external_account_id' => self::WALLET_HASH_ID,
            ])->save();

            $projected = $this->stateService->applyAuthenticatedState(
                $account->fresh(),
                $payload,
                self::REASON,
            )->fresh();

            $this->assertPostcondition($projected);
            $executedAt = now();

            AuditLog::query()->create([
                'user_id' => self::USER_ID,
                'action' => self::AUDIT_ACTION,
                'entity_type' => 'user_provider_account',
                'entity_id' => (string) self::ACCOUNT_ID,
                'old_data' => $oldState,
                'new_data' => [
                    ...$this->lifecycleState($projected),
                    'approval_marker' => $approval,
                    'operator_context' => $operatorContext,
                    'authenticated_get_evidence' => [
                        'api_request_log_id' => $evidence->id,
                        'operation' => $evidence->operation,
                        'request_method' => $evidence->request_method,
                        'response_status' => $evidence->response_status,
                        'transport_outcome' => $evidence->transport_outcome,
                        'request_started_at' => $evidence->request_started_at?->toISOString(),
                        'request_finished_at' => $evidence->request_finished_at?->toISOString(),
                    ],
                    'timestamp' => $executedAt->toISOString(),
                    'reason' => self::REASON,
                ],
                'ip_address' => null,
                'user_agent' => 'artisan:nium:rebind-staging-ui-fixture',
            ]);

            return $projected;
        });
    }

    private function fixtureAccount(): UserProviderAccount
    {
        $account = UserProviderAccount::query()->with('user')->find(self::ACCOUNT_ID);
        $this->assertFixtureAccount($account);

        return $account;
    }

    private function assertFixtureAccount(?UserProviderAccount $account): void
    {
        if (! $account instanceof UserProviderAccount
            || (int) $account->user_id !== self::USER_ID
            || (int) $account->provider_id !== self::PROVIDER_ID) {
            throw new RuntimeException('Nium UI fixture Account 7/User 9/Provider 7 hard guard failed.');
        }
    }

    private function assertAuthenticatedResponse(int $status, array $payload, ?ApiRequestLog $evidence): void
    {
        $walletHashId = $this->walletHashId($payload);

        if ($status !== 200
            || strtolower(trim((string) ($payload['status'] ?? ''))) !== 'clear'
            || ! array_key_exists('subStatus', $payload)
            || $payload['subStatus'] !== null
            || ! hash_equals(self::CUSTOMER_HASH_ID, (string) ($payload['customerHashId'] ?? ''))
            || $walletHashId === null
            || ! hash_equals(self::WALLET_HASH_ID, $walletHashId)
            || trim((string) ($payload['externalId'] ?? '')) === ''
            || ! $evidence instanceof ApiRequestLog
            || (int) $evidence->provider_id !== self::PROVIDER_ID
            || (int) $evidence->user_id !== self::USER_ID
            || $evidence->operation !== self::EVIDENCE_OPERATION
            || strtoupper((string) $evidence->request_method) !== 'GET'
            || (int) $evidence->response_status !== 200
            || $evidence->is_success !== true
            || $evidence->transport_outcome !== 'response_received'
            || ! hash_equals(
                (string) $this->safeValues->fingerprint(self::CUSTOMER_HASH_ID),
                (string) Arr::get((array) $evidence->response_body, 'customer_id_fingerprint'),
            )
            || ! hash_equals(
                (string) $this->safeValues->fingerprint(self::WALLET_HASH_ID),
                (string) Arr::get((array) $evidence->response_body, 'wallet_id_fingerprint'),
            )) {
            throw new RuntimeException('Authenticated Nium customer GET did not match the approved clear fixture.');
        }
    }

    private function walletHashId(array $payload): ?string
    {
        $walletHashId = $payload['walletHashId']
            ?? Arr::get($payload, 'wallets.0.walletHashId')
            ?? Arr::get($payload, 'walletHashIds.0');

        return filled($walletHashId) ? (string) $walletHashId : null;
    }

    private function assertLockedFixtureState(UserProviderAccount $account): void
    {
        if (! hash_equals(self::CUSTOMER_HASH_ID, (string) $account->external_customer_id)
            || ! hash_equals(self::WALLET_HASH_ID, (string) $account->external_account_id)
            || $account->status !== 'blocked'
            || $account->reconciliation_status !== 'quarantined'
            || $account->reconciliation_error !== 'verified_identifier_mismatch') {
            throw new RuntimeException('Nium UI fixture Account 7 locked pre-mutation state guard failed.');
        }
    }

    private function assertIdentifierOwnership(string $externalId): void
    {
        $collision = UserProviderAccount::query()
            ->where('provider_id', self::PROVIDER_ID)
            ->where('id', '!=', self::ACCOUNT_ID)
            ->where(function ($query) use ($externalId): void {
                $query->where('external_reference', $externalId)
                    ->orWhere('external_customer_id', self::CUSTOMER_HASH_ID)
                    ->orWhere('external_account_id', self::WALLET_HASH_ID);
            })
            ->exists();

        if ($collision) {
            throw new RuntimeException('Approved Nium fixture identifiers are owned by another provider account.');
        }
    }

    private function assertPostcondition(UserProviderAccount $account): void
    {
        if ($account->status !== 'active'
            || $account->provider_status !== 'clear'
            || $account->provider_sub_status !== null
            || $account->reconciliation_status !== 'reconciled'
            || $account->reconciliation_error !== null
            || $account->security_conflict_at !== null
            || $account->security_conflict_reason !== null) {
            throw new RuntimeException('Nium UI fixture rebind postcondition failed; transaction rolled back.');
        }
    }

    private function lifecycleState(UserProviderAccount $account): array
    {
        return [
            ...$this->stateProjector->auditState($account),
            'reconciliation_error' => $account->reconciliation_error,
            'security_conflict_reason' => $account->security_conflict_reason,
        ];
    }

    private function auditExists(): bool
    {
        return AuditLog::query()
            ->where('action', self::AUDIT_ACTION)
            ->where('entity_type', 'user_provider_account')
            ->where('entity_id', (string) self::ACCOUNT_ID)
            ->exists();
    }
}
