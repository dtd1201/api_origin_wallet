<?php

namespace App\Services\Nium;

use App\Models\UserProviderAccount;
use Illuminate\Support\Arr;

class NiumAuthenticatedStateProjector
{
    public function __construct(
        private readonly NiumSafeValueProjector $safeValues,
    ) {}

    public function accountUpdates(
        UserProviderAccount $providerAccount,
        array $payload,
        string $source,
    ): array {
        $customerHashId = $this->stringValue($payload, ['customerHashId']);
        $walletHashId = $this->walletHashId($payload);
        $customerExternalReference = strtoupper((string) ($payload['template'] ?? ''))
            === 'CUSTOMER_ENTITY_KYC_STATUS'
                ? null
                : (filled($payload['externalId'] ?? null) ? (string) $payload['externalId'] : null);

        $this->assertIdentifierDoesNotConflict($providerAccount, 'external_customer_id', $customerHashId);
        $this->assertIdentifierDoesNotConflict($providerAccount, 'external_account_id', $walletHashId);
        $this->assertIdentifierDoesNotConflict(
            $providerAccount,
            'external_reference',
            $customerExternalReference,
        );

        $providerStatus = $this->providerStatus($payload, $providerAccount);
        $providerSubStatus = $this->providerSubStatus($payload, $providerAccount);
        $complianceStatus = $this->complianceStatus($payload, $providerAccount);
        $oddStatus = $this->oddStatus($payload, $providerAccount);
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

        $entityStates = (array) Arr::get(
            (array) $providerAccount->metadata,
            'nium_entity_kyc_states',
            [],
        );

        if (isset($payload['kycStatus'])) {
            $entityKey = (string) (
                $payload['referenceId']
                ?? $payload['externalId']
                ?? $payload['entityType']
                ?? 'customer'
            );
            $entityStates['ref_'.$this->safeValues->fingerprint($entityKey)] = array_filter([
                'kyc_status' => $this->safeValues->kycStatus($payload['kycStatus']),
                'kyc_mode' => $this->safeValues->kycMode($payload['kycMode'] ?? null),
                'entity_type' => $this->safeValues->entityType($payload['entityType'] ?? null),
                'updated_at' => now()->toISOString(),
            ], static fn ($value) => $value !== null && $value !== '');
        }

        $metadata = $this->safeValues->accountMetadata(
            $providerStatus,
            $providerSubStatus,
            $source,
            now()->toISOString(),
            $payload['isResubmissionAllowed']
                ?? Arr::get((array) $providerAccount->metadata, 'is_resubmission_allowed'),
            $entityStates,
        );

        return [
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
        ];
    }

    public function submissionUpdates(UserProviderAccount $providerAccount): array
    {
        $status = match ($providerAccount->status) {
            'active' => 'approved',
            'rejected', 'blocked' => 'rejected',
            'failed' => 'failed',
            default => 'submitted',
        };

        return [
            'provider_account_id' => $providerAccount->id,
            'status' => $status,
            'approved_at' => $status === 'approved' ? now() : null,
            'rejected_at' => $status === 'rejected' ? now() : null,
            'failure_reason' => $status === 'failed'
                ? 'Nium onboarding returned an error state.'
                : null,
            'metadata' => $this->safeValues->submissionMetadata(
                $providerAccount->provider_status,
                $providerAccount->provider_sub_status,
                $providerAccount->compliance_status,
                $providerAccount->rfi_status,
                $providerAccount->odd_status,
            ),
        ];
    }

    public function auditState(UserProviderAccount $providerAccount): array
    {
        return $this->safeValues->auditState([
            'external_customer_id' => $providerAccount->external_customer_id,
            'external_account_id' => $providerAccount->external_account_id,
            'external_reference' => $providerAccount->external_reference,
            'status' => $providerAccount->status,
            'provider_status' => $providerAccount->provider_status,
            'provider_sub_status' => $providerAccount->provider_sub_status,
            'compliance_status' => $providerAccount->compliance_status,
            'rfi_status' => $providerAccount->rfi_status,
            'odd_status' => $providerAccount->odd_status,
            'customer_id_verified_at' => $providerAccount->customer_id_verified_at,
            'wallet_id_verified_at' => $providerAccount->wallet_id_verified_at,
            'provider_ids_verified_at' => $providerAccount->provider_ids_verified_at,
            'security_conflict_at' => $providerAccount->security_conflict_at,
            'reconciliation_status' => $providerAccount->reconciliation_status,
            'integration_status' => Arr::get(
                (array) $providerAccount->metadata,
                'integration_status',
            ),
        ]);
    }

    private function internalStatus(
        ?string $status,
        ?string $subStatus,
        ?string $complianceStatus,
        bool $idsVerified,
    ): string {
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

    private function assertIdentifierDoesNotConflict(
        UserProviderAccount $account,
        string $field,
        ?string $incoming,
    ): void {
        if (
            $incoming !== null
            && filled($account->{$field})
            && ! hash_equals((string) $account->{$field}, $incoming)
        ) {
            throw new NiumProviderIdConflictException(
                $field,
                $this->safeValues->fingerprint((string) $account->{$field}) ?? 'missing',
                $this->safeValues->fingerprint($incoming) ?? 'missing',
            );
        }
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

    private function providerStatus(array $payload, UserProviderAccount $account): ?string
    {
        $incoming = $this->stringValue($payload, ['status']);

        return $this->safeValues->providerStatus($incoming ?? $account->provider_status);
    }

    private function providerSubStatus(array $payload, UserProviderAccount $account): ?string
    {
        if (array_key_exists('subStatus', $payload)) {
            return $this->safeValues->providerSubStatus(
                $this->stringValue($payload, ['subStatus']),
            );
        }

        return $this->safeValues->providerSubStatus($account->provider_sub_status);
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
