<?php

namespace App\Services\Kyc;

use App\Models\AuditLog;
use App\Models\KycProfile;
use App\Models\KycProfileCorrection;
use App\Models\User;
use App\Models\UserProviderAccount;
use App\Support\PrimaryProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class AdminKycCorrectionReviewService
{
    public function __construct(
        private readonly KycApplicantCorrectionService $correctionService,
        private readonly KycCorrectionSyncPolicy $syncPolicy,
        private readonly KycCorrectionApprovedSource $approvedSource,
    ) {}

    public function approve(
        User $customer,
        KycProfileCorrection $correction,
        User $reviewer,
        int $expectedVersion,
        string $expectedSourceFingerprint,
        ?string $reviewNote,
        ?string $ip,
        ?string $userAgent,
    ): array {
        return $this->review(
            $customer,
            $correction,
            $reviewer,
            $expectedVersion,
            $expectedSourceFingerprint,
            'approved',
            $reviewNote,
            $ip,
            $userAgent,
        );
    }

    public function requestChanges(
        User $customer,
        KycProfileCorrection $correction,
        User $reviewer,
        int $expectedVersion,
        string $expectedSourceFingerprint,
        string $reason,
        ?string $ip,
        ?string $userAgent,
    ): array {
        return $this->review(
            $customer,
            $correction,
            $reviewer,
            $expectedVersion,
            $expectedSourceFingerprint,
            'needs_more_info',
            $reason,
            $ip,
            $userAgent,
        );
    }

    private function review(
        User $customer,
        KycProfileCorrection $correction,
        User $reviewer,
        int $expectedVersion,
        string $expectedSourceFingerprint,
        string $targetStatus,
        ?string $reviewNote,
        ?string $ip,
        ?string $userAgent,
    ): array {
        return DB::transaction(function () use ($customer, $correction, $reviewer, $expectedVersion, $expectedSourceFingerprint, $targetStatus, $reviewNote, $ip, $userAgent): array {
            $profile = KycProfile::query()->where('user_id', $customer->id)->lockForUpdate()->firstOrFail();
            $accounts = UserProviderAccount::query()
                ->where('user_id', $customer->id)
                ->whereHas('provider', fn ($query) => $query->whereRaw('LOWER(code) = ?', [PrimaryProvider::code()]))
                ->whereNotNull('external_customer_id')->lockForUpdate()->get();
            if ($accounts->count() !== 1) {
                throw new RuntimeException('The customer does not have exactly one existing Nium customer account.');
            }
            $locked = KycProfileCorrection::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->kyc_profile_id !== (int) $profile->id) {
                abort(404);
            }

            if ($locked->status === $targetStatus
                && $locked->correction_version === $expectedVersion
                && hash_equals($locked->source_fingerprint, $expectedSourceFingerprint)) {
                return ['correction' => $locked, 'idempotent' => true];
            }
            if ($locked->status !== 'submitted' || $locked->active_slot !== 1) {
                throw ValidationException::withMessages([
                    'correction' => 'This KYC correction is no longer pending review.',
                ]);
            }
            if ($locked->correction_version !== $expectedVersion
                || ! hash_equals($locked->source_fingerprint, $expectedSourceFingerprint)) {
                throw ValidationException::withMessages([
                    'correction_version' => 'The KYC correction review is stale. Refresh before continuing.',
                ]);
            }

            $context = $this->correctionService->resolveContext($customer, $profile, $accounts->sole(), false);
            $currentFingerprint = $this->correctionService->sourceFingerprint($context);
            if (! hash_equals($locked->source_fingerprint, $currentFingerprint)
                || (int) $locked->applicant_related_person_id !== (int) $context['applicant']->id
                || (int) $locked->user_provider_account_id !== (int) $context['account']->id
                || ! hash_equals((string) $locked->customer_hash_id, $context['customer_hash_id'])
                || ! hash_equals((string) $locked->applicant_reference_id, $context['applicant_reference_id'])
                || ! hash_equals((string) $locked->applicant_external_id, $context['external_id'])
                || ! hash_equals($locked->customer_hash_fingerprint, hash('sha256', $context['customer_hash_id']))
                || ! hash_equals($locked->applicant_reference_fingerprint, hash('sha256', $context['applicant_reference_id']))
                || ! hash_equals($locked->applicant_external_fingerprint, hash('sha256', $context['external_id']))) {
                throw ValidationException::withMessages([
                    'correction_version' => 'The approved KYC source or immutable Nium binding has changed. Refresh and investigate before continuing.',
                ]);
            }

            $approvedSourceSnapshot = null;
            $approvedSourceFingerprint = null;
            if ($targetStatus === 'approved') {
                $policy = $this->syncPolicy->evaluate($accounts->sole());
                if (! $policy['allowed']) {
                    throw ValidationException::withMessages(['correction' => $policy['reason']]);
                }
                $approvedSourceSnapshot = $this->approvedSource->snapshot($profile);
                $approvedSourceFingerprint = $this->approvedSource->fingerprint($approvedSourceSnapshot);
            }

            $locked->update([
                'status' => $targetStatus,
                'active_slot' => $targetStatus === 'approved' ? 1 : null,
                'reviewed_by_user_id' => $reviewer->id,
                'review_note' => $reviewNote,
                'reviewed_at' => now(),
                'resolved_at' => $targetStatus === 'needs_more_info' ? now() : null,
                'approved_source_snapshot' => $approvedSourceSnapshot,
                'approved_source_fingerprint' => $approvedSourceFingerprint,
            ]);

            if ($targetStatus === 'needs_more_info' && $locked->request_origin === 'admin') {
                $profile->corrections()->create([
                    'applicant_related_person_id' => $locked->applicant_related_person_id,
                    'user_provider_account_id' => $locked->user_provider_account_id,
                    'correction_version' => ((int) $profile->corrections()->max('correction_version')) + 1,
                    'source_fingerprint' => $locked->source_fingerprint,
                    'customer_hash_id' => $locked->customer_hash_id,
                    'applicant_reference_id' => $locked->applicant_reference_id,
                    'applicant_external_id' => $locked->applicant_external_id,
                    'customer_hash_fingerprint' => $locked->customer_hash_fingerprint,
                    'applicant_reference_fingerprint' => $locked->applicant_reference_fingerprint,
                    'applicant_external_fingerprint' => $locked->applicant_external_fingerprint,
                    'before_snapshot' => $locked->before_snapshot,
                    'proposed_values' => $locked->before_snapshot,
                    'requested_fields' => $locked->requested_fields,
                    'requested_document_types' => $locked->requested_document_types,
                    'request_instruction' => $reviewNote,
                    'request_origin' => 'admin',
                    'status' => 'requested',
                    'active_slot' => 1,
                    'created_by_user_id' => $reviewer->id,
                    'requested_by_user_id' => $reviewer->id,
                    'submitted_at' => now(),
                ]);
            }

            AuditLog::query()->create([
                'user_id' => $reviewer->id,
                'action' => $targetStatus === 'approved' ? 'kyc.correction_approved' : 'kyc.correction_changes_requested',
                'entity_type' => 'kyc_profile_correction',
                'entity_id' => (string) $locked->id,
                'old_data' => ['status' => 'submitted'],
                'new_data' => array_filter([
                    'status' => $targetStatus,
                    'kyc_profile_id' => $profile->id,
                    'applicant_related_person_id' => $locked->applicant_related_person_id,
                    'correction_version' => $locked->correction_version,
                    'source_fingerprint' => $locked->source_fingerprint,
                    'customer_hash_fingerprint' => $locked->customer_hash_fingerprint,
                    'applicant_reference_fingerprint' => $locked->applicant_reference_fingerprint,
                    'review_reason_recorded' => $targetStatus === 'needs_more_info' ? true : null,
                ], static fn ($value): bool => $value !== null),
                'ip_address' => $ip,
                'user_agent' => Str::limit((string) $userAgent, 1000, ''),
            ]);

            return ['correction' => $locked->fresh(), 'idempotent' => false];
        }, 3);
    }
}
