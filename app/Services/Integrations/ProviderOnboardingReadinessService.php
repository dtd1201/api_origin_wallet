<?php

namespace App\Services\Integrations;

use App\Models\AuditLog;
use App\Models\IntegrationProvider;
use App\Models\KycProviderSubmission;
use App\Models\NiumRfiCase;
use App\Models\User;
use App\Support\PrimaryProvider;
use Illuminate\Support\Str;

class ProviderOnboardingReadinessService
{
    public function assertReady(IntegrationProvider $provider, User $user): KycProviderSubmission
    {
        $submission = KycProviderSubmission::query()
            ->where('user_id', $user->id)
            ->where('provider_id', $provider->id)
            ->first();

        if (! PrimaryProvider::isPrimary($provider)) {
            if (! in_array(strtolower((string) $user->kyc_status), ['approved', 'verified'], true)) {
                $this->reject($provider, $user, $submission, 'kyc_not_approved', 'internal_kyc', 'User internal KYC must be verified before provider onboarding.');
            }

            if ($submission === null || ! in_array($submission->status, ['approved', 'submitted'], true)) {
                $this->reject($provider, $user, $submission, 'provider_release_not_approved', 'provider_release', 'Provider KYC submission must be approved internally before sending to this provider.');
            }

            return $submission;
        }

        $profile = $user->kycProfile()
            ->with([
                'documents',
                'relatedPersons.documents',
                'requirements',
                'amlScreenings' => fn ($query) => $query->whereNull('superseded_at'),
            ])->first();

        if ($profile === null
            || ! in_array(strtolower((string) $profile->status), ['approved', 'verified'], true)
            || ! in_array(strtolower((string) $user->kyc_status), ['approved', 'verified'], true)) {
            $this->reject($provider, $user, $submission, 'kyc_not_approved', 'internal_kyc', 'User internal KYC must be verified before provider onboarding.');
        }

        if ($submission === null) {
            $this->reject($provider, $user, null, 'nium_submission_not_ready', 'internal_kyc_bridge', 'Nium submission tracking was not prepared from the approved internal KYC profile.');
        }

        if (! in_array($submission->status, ['pending', 'submitted'], true)) {
            $this->reject($provider, $user, $submission, 'nium_submission_invalidated', 'internal_kyc_bridge', 'Nium submission tracking was invalidated by a later compliance change.');
        }

        if ((int) $submission->kyc_profile_id !== (int) $profile->id) {
            $this->reject($provider, $user, $submission, 'nium_submission_wrong_profile', 'current_kyc_profile', 'The Nium submission does not belong to the current KYC profile.');
        }

        if ($profile->amlScreenings->isEmpty()
            || $profile->amlScreenings->contains(fn ($screening) => $screening->status !== 'completed' || $screening->compliance_decision !== 'clear')) {
            $this->reject($provider, $user, $submission, 'aml_not_clear', 'aml', 'All active AML screenings must be completed and clear before Nium onboarding.');
        }

        if ($profile->documents->isEmpty()
            || $profile->documents->contains(fn ($document) => ! in_array(strtolower((string) $document->status), ['approved', 'verified'], true))) {
            $this->reject($provider, $user, $submission, 'documents_not_approved', 'kyc_documents', 'Required KYC documents must be approved before Nium onboarding.');
        }

        $account = $user->providerAccounts()->where('provider_id', $provider->id)->latest('id')->first();

        if ($account !== null) {
            $providerStatus = strtolower((string) $account->provider_status);
            $providerSubStatus = strtolower((string) $account->provider_sub_status);
            $complianceStatus = strtolower((string) $account->compliance_status);
            $rfiStatus = strtolower((string) $account->rfi_status);
            $hasBlockingRfi = NiumRfiCase::query()
                ->where('provider_id', $provider->id)
                ->where('user_provider_account_id', $account->id)
                ->whereIn('status', ['provisional', 'requested', 'responded_under_review'])
                ->exists();

            if (in_array($providerStatus, ['blocked', 'closed', 'failed', 'rejected', 'suspended', 'terminated'], true)
                || in_array($providerSubStatus, ['blocked', 'failed', 'rejected', 'rfi_requested'], true)
                || in_array($complianceStatus, ['blocked', 'failed', 'rejected'], true)
                || in_array($rfiStatus, ['action_required', 'requested', 'responded', 'responded_under_review'], true)
                || $account->security_conflict_at !== null
                || filled($account->security_conflict_reason)
                || $hasBlockingRfi) {
                $this->reject($provider, $user, $submission, 'compliance_state_blocking', 'provider_compliance', 'Blocking provider compliance or RFI state prevents Nium onboarding.');
            }
        }

        return $submission;
    }

    private function reject(
        IntegrationProvider $provider,
        User $user,
        ?KycProviderSubmission $submission,
        string $reasonCode,
        string $failedGate,
        string $message,
    ): never {
        $context = [
            'reason_code' => $reasonCode,
            'failed_gate' => $failedGate,
            'customer_id' => $user->id,
            'provider_id' => $provider->id,
            'provider_submission_id' => $submission?->id,
            'kyc_profile_id' => $user->kycProfile?->id,
        ];

        AuditLog::query()->create([
            'user_id' => auth()->id(),
            'action' => 'provider_onboarding.blocked',
            'entity_type' => 'kyc_provider_submission',
            'entity_id' => (string) ($submission?->id ?? $user->id),
            'old_data' => null,
            'new_data' => $context,
            'ip_address' => request()?->ip(),
            'user_agent' => Str::limit((string) request()?->userAgent(), 1000, ''),
        ]);

        throw new ProviderOnboardingEligibilityException(
            reasonCode: $reasonCode,
            failedGate: $failedGate,
            customerId: $user->id,
            providerId: $provider->id,
            providerSubmissionId: $submission?->id,
            message: $message,
        );
    }
}
