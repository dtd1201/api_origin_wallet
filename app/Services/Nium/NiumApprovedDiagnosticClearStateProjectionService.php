<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\UserProviderAccount;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NiumApprovedDiagnosticClearStateProjectionService
{
    public const ACCOUNT_ID = 7;

    public const USER_ID = 9;

    public const PROVIDER_ID = 7;

    public const EVIDENCE_LOG_ID = 186;

    public const EVIDENCE_OPERATION = 'customer_get_diagnostic_su_authorized_4';

    public const CUSTOMER_HASH_ID = '3bb08685-06ad-4d1d-9823-983c45a3d1c5';

    public const WALLET_HASH_ID = '095bbbb7-f1e5-42ec-80ff-2a6e579c1847';

    public const APPROVAL = 'PROJECT_SU_APPROVED_DIAGNOSTIC_CLEAR_STATE_4';

    private const AUDIT_ACTION = 'provider_account.nium_approved_diagnostic_clear_projection';

    public function __construct(
        private readonly NiumProviderAccountStateService $stateService,
    ) {}

    public function project(string $approval, string $operatorContext): UserProviderAccount
    {
        if (! app()->environment('staging')) {
            throw new RuntimeException('Approved diagnostic clear-state projection is staging-only.');
        }

        if (! hash_equals(self::APPROVAL, $approval)) {
            throw new RuntimeException('Approved diagnostic clear-state projection approval marker is invalid.');
        }

        if (trim($operatorContext) === '') {
            throw new RuntimeException('Approved diagnostic clear-state projection requires operator context.');
        }

        return DB::transaction(function () use ($approval, $operatorContext): UserProviderAccount {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->first();

            if (! $account instanceof UserProviderAccount
                || (int) $account->user_id !== self::USER_ID
                || (int) $account->provider_id !== self::PROVIDER_ID) {
                throw new RuntimeException('Approved diagnostic clear-state Account 7 fixture context is unavailable.');
            }

            if (! hash_equals(self::CUSTOMER_HASH_ID, (string) $account->external_customer_id)
                || ! hash_equals(self::WALLET_HASH_ID, (string) $account->external_account_id)) {
                throw new RuntimeException('Approved diagnostic clear-state Account 7 identifiers do not match.');
            }

            if ($this->projectionAuditExists()) {
                throw new RuntimeException('Approved diagnostic clear-state projection was already executed.');
            }

            if ($account->status === 'active'
                && $account->provider_status === 'clear'
                && $account->provider_sub_status === null) {
                throw new RuntimeException('Account 7 is already clear without approved projection audit evidence; manual review is required.');
            }

            $this->assertDiagnosticEvidence();

            $projectedAt = now();
            $oldState = $this->lifecycleState($account);
            $projected = $this->stateService->applyAuthenticatedState(
                $account,
                [
                    'customerHashId' => self::CUSTOMER_HASH_ID,
                    'walletHashId' => self::WALLET_HASH_ID,
                    'status' => 'clear',
                    'subStatus' => null,
                ],
                'approved_diagnostic_customer_clear_projection',
            );

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
                    'evidence_log_id' => self::EVIDENCE_LOG_ID,
                    'timestamp' => $projectedAt->toISOString(),
                ],
                'ip_address' => null,
                'user_agent' => 'artisan:nium:project-approved-diagnostic-clear-state',
            ]);

            return $projected->fresh();
        });
    }

    private function projectionAuditExists(): bool
    {
        return AuditLog::query()
            ->where('action', self::AUDIT_ACTION)
            ->where('entity_type', 'user_provider_account')
            ->where('entity_id', (string) self::ACCOUNT_ID)
            ->exists();
    }

    private function assertDiagnosticEvidence(): void
    {
        $evidence = ApiRequestLog::query()->whereKey(self::EVIDENCE_LOG_ID)->first();
        $body = (array) ($evidence?->response_body ?? []);

        if (! $evidence instanceof ApiRequestLog
            || (int) $evidence->provider_id !== self::PROVIDER_ID
            || (int) $evidence->user_id !== self::USER_ID
            || $evidence->operation !== self::EVIDENCE_OPERATION
            || (int) $evidence->response_status !== 200
            || $evidence->is_success !== true
            || strtolower((string) Arr::get($body, 'status')) !== 'clear'
            || ! $this->bodyContainsExactValue($body, self::CUSTOMER_HASH_ID, ['customer_hash_id', 'customerHashId'])
            || ! $this->bodyContainsExactValue($body, self::WALLET_HASH_ID, ['wallet_hash_id', 'walletHashId'])) {
            throw new RuntimeException('Approved diagnostic clear-state evidence is unavailable or does not match.');
        }
    }

    private function bodyContainsExactValue(array $body, string $expected, array $keys): bool
    {
        foreach ($keys as $key) {
            if (hash_equals($expected, (string) Arr::get($body, $key))) {
                return true;
            }
        }

        foreach ($body as $value) {
            if (is_array($value) && $this->bodyContainsExactValue($value, $expected, $keys)) {
                return true;
            }
        }

        return false;
    }

    private function lifecycleState(UserProviderAccount $account): array
    {
        return [
            'status' => $account->status,
            'provider_status' => $account->provider_status,
            'provider_sub_status' => $account->provider_sub_status,
            'compliance_status' => $account->compliance_status,
            'reconciliation_status' => $account->reconciliation_status,
            'reconciliation_error' => $account->reconciliation_error,
        ];
    }
}
