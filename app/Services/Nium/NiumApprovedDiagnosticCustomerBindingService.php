<?php

namespace App\Services\Nium;

use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\UserProviderAccount;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NiumApprovedDiagnosticCustomerBindingService
{
    public const ACCOUNT_ID = 7;

    public const USER_ID = 9;

    public const PROVIDER_ID = 7;

    public const EVIDENCE_LOG_ID = 143;

    public const CUSTOMER_HASH_ID = '3bb08685-06ad-4d1d-9823-983c45a3d1c5';

    public const WALLET_HASH_ID = '095bbb7b-f1e5-42ec-80ff-2a6e579c1847';

    private const PREVIOUS_CUSTOMER_HASH_ID = 'b4e39b04-08dc-4f03-810a-b96b60950ee1';

    private const PREVIOUS_WALLET_HASH_ID = 'b005d6ca-ba6c-41d5-b379-d90d2b9be6bb';

    public const APPROVAL = 'BIND_SU_APPROVED_DIAGNOSTIC_CUSTOMER_4';

    public function bind(string $customerHashId, string $walletHashId, string $approval, string $operatorContext): UserProviderAccount
    {
        if (! app()->environment('staging')) {
            throw new RuntimeException('Approved diagnostic customer binding is staging-only.');
        }

        if (! hash_equals(self::CUSTOMER_HASH_ID, $customerHashId)
            || ! hash_equals(self::WALLET_HASH_ID, $walletHashId)
            || ! hash_equals(self::APPROVAL, $approval)) {
            throw new RuntimeException('Approved diagnostic customer binding arguments do not match the locked allowlist.');
        }

        return DB::transaction(function () use ($customerHashId, $walletHashId, $approval, $operatorContext): UserProviderAccount {
            $account = UserProviderAccount::query()->whereKey(self::ACCOUNT_ID)->lockForUpdate()->first();

            if (! $account instanceof UserProviderAccount
                || (int) $account->user_id !== self::USER_ID
                || (int) $account->provider_id !== self::PROVIDER_ID) {
                throw new RuntimeException('Approved diagnostic Account 7 fixture context is unavailable.');
            }

            if (hash_equals($customerHashId, (string) $account->external_customer_id)
                && hash_equals($walletHashId, (string) $account->external_account_id)) {
                if ($this->bindingAuditExists()) {
                    throw new RuntimeException('Approved diagnostic Account 7 binding was already executed.');
                }

                throw new RuntimeException('Account 7 contains the approved diagnostic identifiers without binding audit evidence; manual review is required.');
            }

            if (! hash_equals(self::PREVIOUS_CUSTOMER_HASH_ID, (string) $account->external_customer_id)
                || ! hash_equals(self::PREVIOUS_WALLET_HASH_ID, (string) $account->external_account_id)
                || $account->customer_id_verified_at === null
                || $account->wallet_id_verified_at === null
                || $account->provider_ids_verified_at === null
                || $account->security_conflict_at !== null) {
                throw new RuntimeException('Account 7 does not match the locked pre-transition fixture state.');
            }

            $this->assertDiagnosticEvidence();

            if ($this->bindingAuditExists()) {
                throw new RuntimeException('Approved diagnostic Account 7 transition evidence already exists.');
            }

            $transitionedAt = now();
            $oldIdentifiers = [
                'external_customer_id' => (string) $account->external_customer_id,
                'external_account_id' => (string) $account->external_account_id,
            ];
            $metadata = (array) $account->metadata;
            $metadata['approved_diagnostic_customer_binding'] = [
                'evidence_api_request_log_id' => self::EVIDENCE_LOG_ID,
                'approval_marker' => $approval,
                'reason' => 'su_authorized_sandbox_van_continuation',
                'operator_context' => $operatorContext,
                'transitioned_at' => $transitionedAt->toISOString(),
            ];

            // This exact fixture transition is intentionally separate from production identifier projection.
            $account->forceFill([
                'external_customer_id' => $customerHashId,
                'external_account_id' => $walletHashId,
                'customer_id_verified_at' => $transitionedAt,
                'wallet_id_verified_at' => $transitionedAt,
                'provider_ids_verified_at' => $transitionedAt,
                'reconciliation_status' => 'reconciled',
                'reconciliation_error' => null,
                'reconciled_at' => $transitionedAt,
                'metadata' => $metadata,
            ])->save();

            AuditLog::query()->create([
                'user_id' => self::USER_ID,
                'action' => 'provider_account.nium_approved_diagnostic_binding',
                'entity_type' => 'user_provider_account',
                'entity_id' => (string) self::ACCOUNT_ID,
                'old_data' => [
                    'old_customer_id' => $oldIdentifiers['external_customer_id'],
                    'old_wallet_id' => $oldIdentifiers['external_account_id'],
                ],
                'new_data' => [
                    'approval_marker' => $approval,
                    'operator_context' => $operatorContext,
                    'old_customer_id' => $oldIdentifiers['external_customer_id'],
                    'old_wallet_id' => $oldIdentifiers['external_account_id'],
                    'new_customer_id' => $customerHashId,
                    'new_wallet_id' => $walletHashId,
                    'evidence_log_id' => self::EVIDENCE_LOG_ID,
                    'timestamp' => $transitionedAt->toISOString(),
                    'reason' => 'su_authorized_sandbox_van_continuation',
                ],
                'ip_address' => null,
                'user_agent' => 'artisan:nium:bind-approved-diagnostic-customer',
            ]);

            return $account->fresh();
        });
    }

    private function bindingAuditExists(): bool
    {
        return AuditLog::query()
            ->where('action', 'provider_account.nium_approved_diagnostic_binding')
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
            || $evidence->operation !== 'customer_create_diagnostic_su_authorized_4'
            || (int) $evidence->response_status !== 200
            || $evidence->is_success !== true
            || strtolower((string) Arr::get($body, 'status')) !== 'pending'
            || ! $this->bodyContainsExactValue($body, self::CUSTOMER_HASH_ID, ['customer_hash_id', 'customerHashId'])
            || ! $this->bodyContainsExactValue($body, self::WALLET_HASH_ID, ['wallet_hash_id', 'walletHashId'])) {
            throw new RuntimeException('Approved diagnostic customer creation evidence is unavailable or does not match.');
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
}
