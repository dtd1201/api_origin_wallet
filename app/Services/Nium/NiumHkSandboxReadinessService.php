<?php

namespace App\Services\Nium;

use App\Models\UserProviderAccount;

final class NiumHkSandboxReadinessService
{
    public function report(UserProviderAccount $account): array
    {
        $account->loadMissing('user');
        $metadata = (array) ($account->metadata ?? []);
        $applicantState = data_get($metadata, 'nium_submit_kyc_attempts.ref_'.substr(hash('sha256', 'c620e0e9-ab0a-43bd-aa10-44f573db723a'), 0, 16).'.state');
        $stakeholderState = data_get($metadata, 'nium_submit_kyc_attempts.ref_'.substr(hash('sha256', '7609d9d1-9d37-4e08-9197-602d792f7a2e'), 0, 16).'.state');
        $customerCreated = filled($account->external_customer_id) && $account->customer_id_verified_at !== null;
        $rfiStarted = in_array($account->rfi_status, ['requested', 'action_required', 'resolved'], true);
        $overallKycComplete = $account->user?->kyc_status === 'verified'
            && $account->status === 'active'
            && $account->provider_status === 'clear'
            && $account->provider_sub_status === null;

        return [
            'customer_onboarding' => $this->stage($customerCreated, 'customer_not_verified'),
            'applicant_kyc' => $this->state(false, $applicantState !== null, $applicantState === 'provider_accepted_200_sandbox_review' ? 'provider_sandbox_review' : 'applicant_not_verified'),
            'stakeholder_kyc' => $this->state(false, $stakeholderState !== null, 'provider_confirmation_required'),
            'customer_kyc' => $this->state($overallKycComplete, $customerCreated, 'overall_kyc_not_authoritatively_completed'),
            'customer_compliance' => $this->state($account->compliance_status === 'completed' && $account->provider_status === 'clear', filled($account->compliance_status), 'customer_not_clear'),
            'customer_rfi' => $rfiStarted ? $this->state($account->rfi_status === 'resolved', true, 'outstanding_or_unresolved') : ['status' => 'NOT_STARTED', 'blockers' => []],
            'van' => ['status' => 'NOT_STARTED', 'blockers' => ['kyc_and_allocation_confirmation_required']],
            'supported_corridors' => ['status' => 'NOT_STARTED', 'blockers' => ['payout_not_started']],
            'beneficiary_requirements' => ['status' => 'NOT_STARTED', 'blockers' => ['corridor_not_selected']],
            'beneficiary' => ['status' => 'NOT_STARTED', 'blockers' => ['requirements_not_validated']],
            'transfer' => ['status' => 'NOT_STARTED', 'blockers' => ['beneficiary_not_validated']],
            'transaction_compliance' => ['status' => 'NOT_STARTED', 'blockers' => ['transfer_not_started']],
            'transaction_lifecycle' => ['status' => 'NOT_STARTED', 'blockers' => ['transfer_not_started']],
        ];
    }

    private function stage(bool $pass, string $blocker): array
    {
        return ['status' => $pass ? 'PASS' : 'HOLD', 'blockers' => $pass ? [] : [$blocker]];
    }

    private function state(bool $pass, bool $started, string $blocker): array
    {
        return $started ? $this->stage($pass, $blocker) : ['status' => 'NOT_STARTED', 'blockers' => []];
    }
}
