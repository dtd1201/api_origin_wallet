<?php

namespace App\Services\Nium;

use App\Models\AuditLog;
use App\Models\KycProviderSubmission;
use App\Models\User;
use App\Models\UserProviderAccount;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class NiumProviderAccountStateService
{
    public function applyAuthenticatedState(
        UserProviderAccount $providerAccount,
        array $payload,
        string $source,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $requestId = null,
    ): UserProviderAccount {
        try {
            return DB::transaction(function () use ($providerAccount, $payload, $source, $ipAddress, $userAgent): UserProviderAccount {
                $providerAccount = UserProviderAccount::query()->lockForUpdate()->findOrFail($providerAccount->id);
                $before = $this->auditState($providerAccount);
                $customerHashId = $this->stringValue($payload, ['customerHashId']);
                $walletHashId = $this->walletHashId($payload);
                $customerExternalReference = strtoupper((string) ($payload['template'] ?? '')) === 'CUSTOMER_ENTITY_KYC_STATUS'
                    ? null
                    : (filled($payload['externalId'] ?? null) ? (string) $payload['externalId'] : null);

                $this->assertIdentifierDoesNotConflict($providerAccount, 'external_customer_id', $customerHashId);
                $this->assertIdentifierDoesNotConflict($providerAccount, 'external_account_id', $walletHashId);
                $this->assertIdentifierDoesNotConflict(
                    $providerAccount,
                    'external_reference',
                    $customerExternalReference,
                );

                $providerStatus = $this->normalize($this->stringValue($payload, ['status']))
                    ?? $providerAccount->provider_status;
                $providerSubStatus = $this->normalize($this->stringValue($payload, ['subStatus']))
                    ?? ($this->payloadContains($payload, 'subStatus') ? null : $providerAccount->provider_sub_status);
                $complianceStatus = $this->normalize($this->stringValue($payload, ['complianceStatus']))
                    ?? $providerAccount->compliance_status;
                $oddStatus = $this->normalize($this->stringValue($payload, ['oddStatus']))
                    ?? $providerAccount->odd_status;
                $rfiStatus = $this->rfiStatus($providerSubStatus, $providerAccount->rfi_status);
                $customerVerifiedAt = $customerHashId !== null
                    ? ($providerAccount->customer_id_verified_at ?? now())
                    : $providerAccount->customer_id_verified_at;
                $walletVerifiedAt = $walletHashId !== null
                    ? ($providerAccount->wallet_id_verified_at ?? now())
                    : $providerAccount->wallet_id_verified_at;
                $idsVerified = filled($customerHashId ?: $providerAccount->external_customer_id)
                    && filled($walletHashId ?: $providerAccount->external_account_id)
                    && $customerVerifiedAt !== null
                    && $walletVerifiedAt !== null;
                $internalStatus = $this->internalStatus(
                    $providerStatus,
                    $providerSubStatus,
                    $complianceStatus,
                    $idsVerified,
                );

                $metadata = array_merge((array) ($providerAccount->metadata ?? []), [
                    'integration_status' => $this->integrationStatus($providerStatus, $providerSubStatus),
                    'nium_last_state_source' => $source,
                    'nium_last_state_at' => now()->toISOString(),
                    'is_resubmission_allowed' => $payload['isResubmissionAllowed'] ?? Arr::get($providerAccount->metadata, 'is_resubmission_allowed'),
                ]);

                if (isset($payload['kycStatus'])) {
                    $entityKey = (string) ($payload['referenceId'] ?? $payload['externalId'] ?? $payload['entityType'] ?? 'customer');
                    $entityStates = (array) ($metadata['nium_entity_kyc_states'] ?? []);
                    $entityStates[$entityKey] = array_filter([
                        'kycStatus' => $payload['kycStatus'],
                        'kycMode' => $payload['kycMode'] ?? null,
                        'entityType' => $payload['entityType'] ?? null,
                        'updatedAt' => now()->toISOString(),
                    ], static fn ($value) => $value !== null && $value !== '');
                    $metadata['nium_entity_kyc_states'] = $entityStates;
                }

                $providerAccount->update([
                    'external_customer_id' => $customerHashId ?: $providerAccount->external_customer_id,
                    'external_account_id' => $walletHashId ?: $providerAccount->external_account_id,
                    'external_reference' => $customerExternalReference ?? $providerAccount->external_reference,
                    'status' => $internalStatus,
                    'provider_status' => $providerStatus,
                    'provider_sub_status' => $providerSubStatus,
                    'compliance_status' => $complianceStatus,
                    'rfi_status' => $rfiStatus,
                    'odd_status' => $oddStatus,
                    'customer_id_verified_at' => $customerVerifiedAt,
                    'wallet_id_verified_at' => $walletVerifiedAt,
                    'provider_ids_verified_at' => $idsVerified
                        ? ($providerAccount->provider_ids_verified_at ?? now())
                        : null,
                    'provider_status_updated_at' => now(),
                    'security_conflict_at' => null,
                    'security_conflict_reason' => null,
                    'reconciliation_status' => 'reconciled',
                    'reconciliation_error' => null,
                    'reconciled_at' => now(),
                    'metadata' => $metadata,
                ]);

                $providerAccount = $providerAccount->fresh();
                $this->syncSubmission($providerAccount);
                $after = $this->auditState($providerAccount);

                if ($before !== $after) {
                    AuditLog::query()->create([
                        'user_id' => $providerAccount->user_id,
                        'action' => 'provider_account.nium_state_changed',
                        'entity_type' => 'user_provider_account',
                        'entity_id' => (string) $providerAccount->id,
                        'old_data' => $before,
                        'new_data' => [...$after, 'source' => $source],
                        'ip_address' => $ipAddress,
                        'user_agent' => $userAgent !== null ? Str::limit($userAgent, 1000, '') : null,
                    ]);
                }

                return $providerAccount;
            });
        } catch (NiumProviderIdConflictException $exception) {
            $this->quarantineConflict($providerAccount, $exception, $source, $ipAddress, $userAgent, $requestId);

            throw $exception;
        }
    }

    public function applyRestrictiveNotification(
        UserProviderAccount $providerAccount,
        array $payload,
        string $source,
    ): UserProviderAccount {
        return DB::transaction(function () use ($providerAccount, $payload, $source): UserProviderAccount {
            $account = UserProviderAccount::query()->lockForUpdate()->findOrFail($providerAccount->id);
            $status = $this->normalize($this->stringValue($payload, ['status']));
            $subStatus = $this->normalize($this->stringValue($payload, ['subStatus']));

            if (! $this->isRestrictive($status, $subStatus)) {
                return $account;
            }

            $before = $this->auditState($account);
            $account->update([
                'status' => $this->internalStatus($status, $subStatus, $account->compliance_status, false),
                'provider_status' => $status ?? $account->provider_status,
                'provider_sub_status' => $subStatus ?? $account->provider_sub_status,
                'rfi_status' => $this->rfiStatus($subStatus, $account->rfi_status),
                'provider_status_updated_at' => now(),
                'reconciliation_status' => 'pending',
                'reconciliation_error' => null,
                'reconciliation_requested_at' => now(),
                'metadata' => array_merge((array) $account->metadata, [
                    'integration_status' => $this->integrationStatus($status, $subStatus),
                    'nium_last_state_source' => $source,
                    'nium_last_state_at' => now()->toISOString(),
                ]),
            ]);
            $account = $account->fresh();
            $this->writeStateAudit($account, $before, $source);

            return $account;
        });
    }

    public function markReconciliationFailure(
        UserProviderAccount $providerAccount,
        string $reason,
        string $source,
        ?string $requestId = null,
    ): UserProviderAccount {
        return DB::transaction(function () use ($providerAccount, $reason, $source, $requestId): UserProviderAccount {
            $account = UserProviderAccount::query()->lockForUpdate()->findOrFail($providerAccount->id);
            $before = $this->auditState($account);
            $safeReason = Str::limit(preg_replace('/[^a-zA-Z0-9_.:\- ]/', '', $reason) ?: 'reconciliation_failed', 500, '');
            $account->update([
                'status' => $account->status === 'active' ? 'under_review' : $account->status,
                'reconciliation_status' => 'failed',
                'reconciliation_error' => $safeReason,
                'reconciliation_requested_at' => now(),
            ]);
            $account = $account->fresh();

            AuditLog::query()->create([
                'user_id' => $account->user_id,
                'action' => 'provider_account.nium_reconciliation_failed',
                'entity_type' => 'user_provider_account',
                'entity_id' => (string) $account->id,
                'old_data' => $before,
                'new_data' => [
                    ...$this->auditState($account),
                    'source' => $source,
                    'request_id' => $requestId,
                    'reason' => $safeReason,
                ],
            ]);

            return $account;
        });
    }

    public function quarantineIdentifierConflict(
        UserProviderAccount $providerAccount,
        string $field,
        string $current,
        string $incoming,
        string $source,
        ?string $requestId = null,
    ): UserProviderAccount {
        $exception = new NiumProviderIdConflictException(
            $field,
            $this->fingerprint($current) ?? 'missing',
            $this->fingerprint($incoming) ?? 'missing',
        );
        $this->quarantineConflict($providerAccount, $exception, $source, null, null, $requestId);

        return $providerAccount->fresh();
    }

    public function recordVerifiedNotificationDetails(
        UserProviderAccount $providerAccount,
        array $payload,
        string $source,
    ): UserProviderAccount {
        return DB::transaction(function () use ($providerAccount, $payload, $source): UserProviderAccount {
            $account = UserProviderAccount::query()->lockForUpdate()->findOrFail($providerAccount->id);
            $before = $this->auditState($account);
            $metadata = (array) $account->metadata;

            if (isset($payload['kycStatus'])) {
                $entityKey = (string) ($payload['referenceId'] ?? $payload['entityType'] ?? 'customer');
                $states = (array) ($metadata['nium_entity_kyc_states'] ?? []);
                $states[$entityKey] = array_filter([
                    'kycStatus' => $payload['kycStatus'],
                    'kycMode' => $payload['kycMode'] ?? null,
                    'entityType' => $payload['entityType'] ?? null,
                    'updatedAt' => now()->toISOString(),
                ], static fn ($value) => $value !== null && $value !== '');
                $metadata['nium_entity_kyc_states'] = $states;
            }

            $account->update([
                'compliance_status' => $this->normalize($this->stringValue($payload, ['complianceStatus']))
                    ?? $account->compliance_status,
                'odd_status' => $this->normalize($this->stringValue($payload, ['oddStatus']))
                    ?? $account->odd_status,
                'metadata' => $metadata,
            ]);
            $account = $account->fresh();
            $this->writeStateAudit($account, $before, $source);

            return $account;
        });
    }

    public function assertEligible(User $user, bool $requireWallet = true): UserProviderAccount
    {
        $providerAccount = $user->providerAccounts()
            ->whereHas('provider', fn ($query) => $query->whereRaw('LOWER(code) = ?', ['nium']))
            ->latest('id')
            ->first();

        if ($providerAccount === null) {
            throw new RuntimeException('Nium customer onboarding has not been started.');
        }

        $eligible = $providerAccount->status === 'active'
            && $this->normalize($providerAccount->provider_status) === 'clear'
            && $this->normalize($providerAccount->provider_sub_status) === null
            && filled($providerAccount->external_customer_id)
            && $providerAccount->customer_id_verified_at !== null
            && (! $requireWallet || (
                filled($providerAccount->external_account_id)
                && $providerAccount->wallet_id_verified_at !== null
            ))
            && $providerAccount->security_conflict_at === null;

        if (! $eligible) {
            $state = collect([$providerAccount->provider_status, $providerAccount->provider_sub_status])
                ->filter()
                ->implode('/');

            throw new RuntimeException('Nium customer and wallet are not eligible yet'.($state !== '' ? " ({$state})." : '.'));
        }

        return $providerAccount;
    }

    public function isCustomerLifecycleTemplate(?string $template): bool
    {
        return in_array(strtoupper((string) $template), [
            'CUSTOMER_STATUS_WEBHOOK',
            'CUSTOMER_ENTITY_KYC_STATUS',
            'CUSTOMER_COMPLIANCE_STATUS',
            'CUSTOMER_ODD_STATUS_WEBHOOK',
            'CARD_CUSTOMER_REGISTRATION_WEBHOOK',
        ], true);
    }

    private function internalStatus(?string $status, ?string $subStatus, ?string $complianceStatus, bool $idsVerified): string
    {
        if (in_array($status, ['closed', 'terminated', 'suspended', 'blocked'], true)) {
            return 'blocked';
        }

        if (in_array($status, ['rejected', 'failed'], true)) {
            return 'rejected';
        }

        if ($status === 'error' || $complianceStatus === 'failed') {
            return 'failed';
        }

        if ($status === 'clear' && $subStatus === null && $idsVerified) {
            return 'active';
        }

        return in_array($subStatus, ['under_review', 'rfi_requested', 'awaiting_kyc'], true)
            ? 'under_review'
            : 'submitted';
    }

    private function integrationStatus(?string $status, ?string $subStatus): string
    {
        return collect(['nium', $status, $subStatus])->filter()->implode('_');
    }

    private function rfiStatus(?string $subStatus, ?string $current): ?string
    {
        if ($subStatus === 'rfi_requested') {
            return 'requested';
        }

        if ($subStatus === null && $current === 'requested') {
            return 'cleared';
        }

        return $current;
    }

    private function syncSubmission(UserProviderAccount $providerAccount): void
    {
        $status = match ($providerAccount->status) {
            'active' => 'approved',
            'rejected', 'blocked' => 'rejected',
            'failed' => 'failed',
            default => 'submitted',
        };

        $submission = KycProviderSubmission::query()
            ->where('user_id', $providerAccount->user_id)
            ->where('provider_id', $providerAccount->provider_id)
            ->first();

        if ($submission === null) {
            return;
        }

        $submission->update([
            'provider_account_id' => $providerAccount->id,
            'status' => $status,
            'approved_at' => $status === 'approved' ? now() : null,
            'rejected_at' => $status === 'rejected' ? now() : null,
            'failure_reason' => $status === 'failed' ? 'Nium onboarding returned an error state.' : null,
            'metadata' => array_merge((array) ($submission->metadata ?? []), [
                'provider_status' => $providerAccount->provider_status,
                'provider_sub_status' => $providerAccount->provider_sub_status,
                'compliance_status' => $providerAccount->compliance_status,
                'rfi_status' => $providerAccount->rfi_status,
                'odd_status' => $providerAccount->odd_status,
            ]),
        ]);
    }

    private function auditState(UserProviderAccount $providerAccount): array
    {
        return [
            'external_customer_id_fingerprint' => $this->fingerprint($providerAccount->external_customer_id),
            'external_account_id_fingerprint' => $this->fingerprint($providerAccount->external_account_id),
            'external_reference_fingerprint' => $this->fingerprint($providerAccount->external_reference),
            'status' => $providerAccount->status,
            'provider_status' => $providerAccount->provider_status,
            'provider_sub_status' => $providerAccount->provider_sub_status,
            'compliance_status' => $providerAccount->compliance_status,
            'rfi_status' => $providerAccount->rfi_status,
            'odd_status' => $providerAccount->odd_status,
            'customer_id_verified_at' => $providerAccount->customer_id_verified_at?->toISOString(),
            'wallet_id_verified_at' => $providerAccount->wallet_id_verified_at?->toISOString(),
            'provider_ids_verified_at' => $providerAccount->provider_ids_verified_at?->toISOString(),
            'security_conflict_at' => $providerAccount->security_conflict_at?->toISOString(),
            'security_conflict_reason' => $providerAccount->security_conflict_reason,
            'reconciliation_status' => $providerAccount->reconciliation_status,
            'integration_status' => Arr::get((array) $providerAccount->metadata, 'integration_status'),
        ];
    }

    private function walletHashId(array $payload): ?string
    {
        $wallet = $this->stringValue($payload, ['walletHashId']);

        if ($wallet !== null) {
            return $wallet;
        }

        $wallets = (array) ($payload['wallets'] ?? []);
        $wallet = Arr::get($wallets, '0.walletHashId');

        if (filled($wallet)) {
            return (string) $wallet;
        }

        $walletHashIds = (array) ($payload['walletHashIds'] ?? []);

        return filled($walletHashIds[0] ?? null) ? (string) $walletHashIds[0] : null;
    }

    private function assertIdentifierDoesNotConflict(UserProviderAccount $account, string $field, ?string $incoming): void
    {
        if ($incoming !== null && filled($account->{$field}) && ! hash_equals((string) $account->{$field}, $incoming)) {
            throw new NiumProviderIdConflictException(
                $field,
                $this->fingerprint((string) $account->{$field}) ?? 'missing',
                $this->fingerprint($incoming) ?? 'missing',
            );
        }
    }

    private function quarantineConflict(
        UserProviderAccount $providerAccount,
        NiumProviderIdConflictException $exception,
        string $source,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): void {
        DB::transaction(function () use ($providerAccount, $exception, $source, $ipAddress, $userAgent, $requestId): void {
            $account = UserProviderAccount::query()->lockForUpdate()->findOrFail($providerAccount->id);
            $before = $this->auditState($account);
            $account->update([
                'status' => 'blocked',
                'security_conflict_at' => now(),
                'security_conflict_reason' => $exception->field.'_mismatch',
                'reconciliation_status' => 'quarantined',
                'reconciliation_error' => 'verified_identifier_mismatch',
                'reconciliation_requested_at' => now(),
                'metadata' => array_merge((array) $account->metadata, [
                    'integration_status' => 'nium_security_conflict',
                    'nium_last_state_source' => $source,
                ]),
            ]);

            AuditLog::query()->create([
                'user_id' => $account->user_id,
                'action' => 'provider_account.nium_security_conflict',
                'entity_type' => 'user_provider_account',
                'entity_id' => (string) $account->id,
                'old_data' => $before,
                'new_data' => [
                    ...$this->auditState($account->fresh()),
                    'source' => $source,
                    'request_id' => $requestId,
                    'conflicting_field' => $exception->field,
                    'current_fingerprint' => $exception->currentFingerprint,
                    'incoming_fingerprint' => $exception->incomingFingerprint,
                ],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent !== null ? Str::limit($userAgent, 1000, '') : null,
            ]);
        });
    }

    private function writeStateAudit(UserProviderAccount $account, array $before, string $source): void
    {
        $after = $this->auditState($account);

        if ($before === $after) {
            return;
        }

        AuditLog::query()->create([
            'user_id' => $account->user_id,
            'action' => 'provider_account.nium_state_changed',
            'entity_type' => 'user_provider_account',
            'entity_id' => (string) $account->id,
            'old_data' => $before,
            'new_data' => [...$after, 'source' => $source],
        ]);
    }

    private function isRestrictive(?string $status, ?string $subStatus): bool
    {
        return in_array($status, ['suspended', 'closed', 'terminated', 'blocked', 'rejected', 'failed'], true)
            || in_array($subStatus, ['awaiting_kyc', 'rfi_requested', 'under_review'], true);
    }

    private function fingerprint(?string $value): ?string
    {
        return filled($value) ? substr(hash('sha256', (string) $value), 0, 16) : null;
    }

    private function stringValue(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = Arr::get($payload, $path);

            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function payloadContains(array $payload, string $key): bool
    {
        return array_key_exists($key, $payload);
    }

    private function normalize(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }
}
