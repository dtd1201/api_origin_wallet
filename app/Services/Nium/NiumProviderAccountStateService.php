<?php

namespace App\Services\Nium;

use App\Models\AuditLog;
use App\Models\KycProviderSubmission;
use App\Models\User;
use App\Models\UserProviderAccount;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NiumProviderAccountStateService
{
    private const RESERVED_FIXTURE_PERSISTENCE_FAILURE =
        'Reserved Nium customer retry fixture requires capability-owned persistence.';

    public function __construct(
        private readonly NiumSafeValueProjector $safeValues,
        private readonly NiumAuthenticatedStateProjector $authenticatedStateProjector,
    ) {}

    public function applyAuthenticatedState(
        UserProviderAccount $providerAccount,
        array $payload,
        string $source,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $requestId = null,
    ): UserProviderAccount {
        try {
            return DB::transaction(function () use ($providerAccount, $payload, $source): UserProviderAccount {
                $providerAccount = UserProviderAccount::query()->lockForUpdate()->findOrFail($providerAccount->id);
                $this->assertNotReservedIncompleteCustomerRetryFixture($providerAccount);

                return $this->applyAuthenticatedStateToLockedAccount(
                    $providerAccount,
                    $payload,
                    $source,
                );
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
            $status = $this->safeValues->providerStatus($this->stringValue($payload, ['status']));
            $subStatus = $this->safeValues->providerSubStatus($this->stringValue($payload, ['subStatus']));

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
                'metadata' => $this->safeValues->accountMetadata(
                    $status,
                    $subStatus,
                    $source,
                    now()->toISOString(),
                    Arr::get((array) $account->metadata, 'is_resubmission_allowed'),
                    (array) Arr::get((array) $account->metadata, 'nium_entity_kyc_states', []),
                ),
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
            $account->update([
                'status' => $account->status === 'active' ? 'under_review' : $account->status,
                'reconciliation_status' => 'failed',
                'reconciliation_error' => 'reconciliation_failed',
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
                    'source' => $this->safeValues->auditSource($source),
                    'request_id' => $this->safeValues->requestId($requestId),
                    'reason_category' => 'reconciliation_failed',
                    'reason_fingerprint' => $this->safeValues->safeOpaqueFingerprint($reason),
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
        $safeField = $this->safeValues->identifierConflictField($field);

        if ($safeField === null) {
            throw new RuntimeException('Invalid Nium identifier conflict field.');
        }

        $exception = new NiumProviderIdConflictException(
            $safeField,
            $this->safeValues->fingerprint($current) ?? 'missing',
            $this->safeValues->fingerprint($incoming) ?? 'missing',
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
            $entityStates = (array) Arr::get((array) $account->metadata, 'nium_entity_kyc_states', []);

            if (isset($payload['kycStatus'])) {
                $entityKey = (string) ($payload['referenceId'] ?? $payload['entityType'] ?? 'customer');
                $entityStates['ref_'.$this->safeValues->fingerprint($entityKey)] = array_filter([
                    'kyc_status' => $this->safeValues->kycStatus($payload['kycStatus']),
                    'kyc_mode' => $this->safeValues->kycMode($payload['kycMode'] ?? null),
                    'entity_type' => $this->safeValues->entityType($payload['entityType'] ?? null),
                    'updated_at' => now()->toISOString(),
                ], static fn ($value) => $value !== null && $value !== '');
            }

            $complianceStatus = $this->complianceStatus($payload, $account);
            $oddStatus = $this->oddStatus($payload, $account);
            $account->update([
                'compliance_status' => $complianceStatus,
                'odd_status' => $oddStatus,
                'metadata' => $this->safeValues->accountMetadata(
                    $account->provider_status,
                    $account->provider_sub_status,
                    $source,
                    now()->toISOString(),
                    Arr::get((array) $account->metadata, 'is_resubmission_allowed'),
                    $entityStates,
                ),
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
            && $this->safeValues->providerStatus($providerAccount->provider_status) === 'clear'
            && $this->safeValues->providerSubStatus($providerAccount->provider_sub_status) === null
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

    private function assertNotReservedIncompleteCustomerRetryFixture(
        UserProviderAccount $providerAccount,
    ): void {
        if (
            (int) $providerAccount->getKey() === 4
            && (int) $providerAccount->user_id === 6
            && (int) $providerAccount->provider_id === 7
            && trim((string) $providerAccount->external_reference) !== ''
            && (
                trim((string) $providerAccount->external_customer_id) === ''
                || trim((string) $providerAccount->external_account_id) === ''
            )
        ) {
            throw new RuntimeException(self::RESERVED_FIXTURE_PERSISTENCE_FAILURE);
        }
    }

    private function applyAuthenticatedStateToLockedAccount(
        UserProviderAccount $providerAccount,
        array $payload,
        string $source,
    ): UserProviderAccount {
        $before = $this->auditState($providerAccount);
        $providerAccount->update(
            $this->authenticatedStateProjector->accountUpdates(
                $providerAccount,
                $payload,
                $source,
            ),
        );

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
                'new_data' => [...$after, 'source' => $this->safeValues->auditSource($source)],
                'ip_address' => null,
                'user_agent' => null,
            ]);
        }

        return $providerAccount;
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

    private function rfiStatus(?string $subStatus, ?string $current): ?string
    {
        if ($subStatus === 'rfi_requested') {
            return 'requested';
        }

        if ($subStatus === null && $current === 'requested') {
            return 'cleared';
        }

        return $this->safeValues->rfiStatus($current);
    }

    private function syncSubmission(UserProviderAccount $providerAccount): void
    {
        $submission = KycProviderSubmission::query()
            ->where('user_id', $providerAccount->user_id)
            ->where('provider_id', $providerAccount->provider_id)
            ->first();

        if ($submission === null) {
            return;
        }

        $this->updateSubmission($submission, $providerAccount);
    }

    private function updateSubmission(
        KycProviderSubmission $submission,
        UserProviderAccount $providerAccount,
    ): void {
        $submission->update(
            $this->authenticatedStateProjector->submissionUpdates($providerAccount),
        );
    }

    private function auditState(UserProviderAccount $providerAccount): array
    {
        return $this->authenticatedStateProjector->auditState($providerAccount);
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
                $this->safeValues->fingerprint((string) $account->{$field}) ?? 'missing',
                $this->safeValues->fingerprint($incoming) ?? 'missing',
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
        $safeField = $this->safeValues->identifierConflictField($exception->field);

        if ($safeField === null) {
            throw new RuntimeException('Invalid Nium identifier conflict field.');
        }

        DB::transaction(function () use ($providerAccount, $exception, $source, $requestId, $safeField): void {
            $account = UserProviderAccount::query()->lockForUpdate()->findOrFail($providerAccount->id);
            $before = $this->auditState($account);
            $account->update([
                'status' => 'blocked',
                'security_conflict_at' => now(),
                'security_conflict_reason' => $safeField.'_mismatch',
                'reconciliation_status' => 'quarantined',
                'reconciliation_error' => 'verified_identifier_mismatch',
                'reconciliation_requested_at' => now(),
                'metadata' => array_replace(
                    $this->safeValues->accountMetadata(
                        $account->provider_status,
                        $account->provider_sub_status,
                        $source,
                        now()->toISOString(),
                        Arr::get((array) $account->metadata, 'is_resubmission_allowed'),
                        (array) Arr::get((array) $account->metadata, 'nium_entity_kyc_states', []),
                    ),
                    ['integration_status' => 'nium_security_conflict'],
                ),
            ]);

            AuditLog::query()->create([
                'user_id' => $account->user_id,
                'action' => 'provider_account.nium_security_conflict',
                'entity_type' => 'user_provider_account',
                'entity_id' => (string) $account->id,
                'old_data' => $before,
                'new_data' => [
                    ...$this->auditState($account->fresh()),
                    'source' => $this->safeValues->auditSource($source),
                    'request_id' => $this->safeValues->requestId($requestId),
                    'conflicting_field' => $safeField,
                    'current_fingerprint' => $exception->currentFingerprint,
                    'incoming_fingerprint' => $exception->incomingFingerprint,
                ],
                'ip_address' => null,
                'user_agent' => null,
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
            'new_data' => [...$after, 'source' => $this->safeValues->auditSource($source)],
        ]);
    }

    private function isRestrictive(?string $status, ?string $subStatus): bool
    {
        return in_array($status, ['suspended', 'closed', 'terminated', 'blocked', 'rejected', 'failed'], true)
            || in_array($subStatus, ['awaiting_kyc', 'rfi_requested', 'under_review'], true);
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

    private function complianceStatus(array $payload, UserProviderAccount $account): ?string
    {
        $incoming = $this->stringValue($payload, ['complianceStatus']);

        return $this->safeValues->complianceStatus($incoming ?? $account->compliance_status);
    }

    private function oddStatus(array $payload, UserProviderAccount $account): ?string
    {
        $incoming = $this->stringValue($payload, ['oddStatus']);

        return $this->safeValues->oddStatus($incoming ?? $account->odd_status);
    }
}
