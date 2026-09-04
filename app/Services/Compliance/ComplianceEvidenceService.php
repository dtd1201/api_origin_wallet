<?php

namespace App\Services\Compliance;

use App\Models\AuditLog;
use App\Models\IntegrationProvider;
use App\Models\KycProfile;
use App\Models\KycProviderSubmission;
use App\Models\NiumRfiCase;
use App\Support\KycAuditProjection;
use App\Support\PrimaryProvider;
use Illuminate\Support\Str;
use RuntimeException;

class ComplianceEvidenceService
{
    public function prepareNiumSubmission(
        KycProfile $profile,
        int $reviewerUserId,
        ?string $reviewNote,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ?KycProviderSubmission {
        $provider = $this->niumProvider();

        if ($provider === null) {
            return null;
        }

        $submission = KycProviderSubmission::query()->firstOrNew([
            'user_id' => $profile->user_id,
            'provider_id' => $provider->id,
        ]);
        $previousStatus = $submission->exists ? $submission->status : null;

        $submission->fill([
            'kyc_profile_id' => $profile->id,
            'status' => 'pending',
            'reviewed_by_user_id' => $reviewerUserId,
            'reviewed_at' => now(),
            'approved_at' => null,
            'submitted_at' => null,
            'rejected_at' => null,
            'review_note' => $reviewNote,
            'rejection_reason' => null,
            'failure_reason' => null,
            'metadata' => [
                ...((array) $submission->metadata),
                'submission_source' => 'internal_kyc_approval',
                'kyc_profile_id' => $profile->id,
            ],
        ])->save();

        $this->audit($submission, $reviewerUserId, 'kyc_provider_submission.prepared_from_kyc', 'internal_kyc_approved', $previousStatus, $ipAddress, $userAgent);

        return $submission->fresh();
    }

    public function invalidateNiumSubmission(
        KycProfile $profile,
        string $reason,
        ?int $actorUserId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ?KycProviderSubmission {
        $provider = $this->niumProvider();
        $submission = $provider === null ? null : KycProviderSubmission::query()
            ->where('user_id', $profile->user_id)
            ->where('provider_id', $provider->id)
            ->first();

        if ($submission === null) {
            return $submission;
        }

        $previousStatus = $submission->status;
        $metadata = (array) $submission->metadata;
        $metadata['previous_submission'] = [
            'reviewed_by_user_id' => $submission->reviewed_by_user_id,
            'reviewed_at' => $submission->reviewed_at?->toISOString(),
            'approved_at' => $submission->approved_at?->toISOString(),
            'submitted_at' => $submission->submitted_at?->toISOString(),
            'provider_account_id' => $submission->provider_account_id,
            'invalidated_reason' => $reason,
            'invalidated_at' => now()->toISOString(),
        ];

        $submission->update([
            'kyc_profile_id' => $profile->id,
            'status' => 'failed',
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'approved_at' => null,
            'submitted_at' => null,
            'rejected_at' => null,
            'review_note' => null,
            'rejection_reason' => null,
            'failure_reason' => $reason,
            'metadata' => $metadata,
        ]);

        $this->audit($submission, $actorUserId, 'kyc_provider_submission.invalidated', $reason, $previousStatus, $ipAddress, $userAgent);

        return $submission->fresh();
    }

    public function markNiumSubmissionSubmitted(KycProviderSubmission $submission, int $providerAccountId): KycProviderSubmission
    {
        $submission->update([
            'status' => 'submitted',
            'provider_account_id' => $providerAccountId,
            'submitted_at' => now(),
            'approved_at' => null,
            'failure_reason' => null,
        ]);

        return $submission->fresh();
    }

    public function markNiumSubmissionPendingDocuments(KycProviderSubmission $submission, int $providerAccountId): KycProviderSubmission
    {
        $submission->refresh();
        $submission->update([
            'status' => 'pending',
            'provider_account_id' => $providerAccountId,
            'submitted_at' => null,
            'approved_at' => null,
            'failure_reason' => null,
        ]);

        return $submission->fresh();
    }

    public function markNiumSubmissionFailed(KycProviderSubmission $submission, string $reasonCode): KycProviderSubmission
    {
        $submission->update([
            'status' => 'failed',
            'failure_reason' => $reasonCode,
        ]);

        return $submission->fresh();
    }

    public function assertNoBlockingNiumCompliance(int $userId): void
    {
        $provider = $this->niumProvider();

        if ($provider === null) {
            return;
        }

        $account = $provider->userAccounts()->where('user_id', $userId)->latest('id')->first();

        if ($account === null) {
            return;
        }

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
            throw new RuntimeException('Blocking provider compliance or RFI state prevents KYC approval for Nium onboarding.');
        }
    }

    private function niumProvider(): ?IntegrationProvider
    {
        return IntegrationProvider::query()->where('code', PrimaryProvider::code())->first();
    }

    private function audit(
        KycProviderSubmission $submission,
        ?int $actorUserId,
        string $action,
        string $reason,
        ?string $previousStatus,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        AuditLog::query()->create([
            'user_id' => $actorUserId,
            'action' => $action,
            'entity_type' => 'kyc_provider_submission',
            'entity_id' => (string) $submission->id,
            'old_data' => null,
            'new_data' => KycAuditProjection::providerSubmission($submission, $previousStatus, $reason),
            'ip_address' => $ipAddress,
            'user_agent' => Str::limit((string) $userAgent, 1000, ''),
        ]);
    }
}
