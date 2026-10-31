<?php

namespace App\Services\Nium;

use App\Models\Transfer;
use Illuminate\Support\Str;
use RuntimeException;

final class NiumSgdLocalPayoutAuthorization
{
    public function __construct(private readonly NiumPayoutFxLockService $locks) {}

    /** Local evidence only. Approval never creates or refreshes a provider hold. */
    public function snapshot(Transfer $transfer, ?string $consumingOperation = null): array
    {
        $transfer->loadMissing(['provider', 'user', 'beneficiary', 'fxQuote']);
        app(NiumSgdLocalCugScope::class)->assertTransfer($transfer);
        if (! filled($transfer->purpose_code)) {
            throw new RuntimeException('A selected payment purpose is required.');
        }
        if ($transfer->fxQuote === null || $transfer->fx_quote_id !== $transfer->fxQuote->id) {
            throw new RuntimeException('Nium SGD approval requires a fresh attached FX lock through an explicit authorized workflow.');
        }
        if (($transfer->raw_data['sgd_local_workflow']['version'] ?? null) === 1) {
            app(NiumSgdLocalPayoutWorkflow::class)->assertIntent($transfer);
        }
        $context = $this->locks->context($transfer->provider, $transfer->user);
        NiumPayoutFxLockContract::assertMatchesTransfer($transfer->fxQuote, $transfer, $context, $consumingOperation);

        return [
            'user_id' => $transfer->user_id, 'provider_id' => $transfer->provider_id,
            'provider_context' => $context, 'beneficiary_id' => $transfer->beneficiary_id,
            'external_beneficiary_id' => $transfer->beneficiary->external_beneficiary_id,
            'source_currency' => $transfer->source_currency, 'destination_currency' => $transfer->target_currency,
            'source_amount' => NiumSgdLocalCugScope::principal($transfer->source_amount),
            'purpose_code' => $transfer->purpose_code, 'fx_quote_id' => $transfer->fx_quote_id,
            'audit_id' => (string) NiumPayoutAuditId::fromQuote($transfer->fxQuote),
            'rate' => $transfer->fxQuote->raw_data['payout_fx_lock_contract']['rate'],
            'expires_at' => $transfer->fxQuote->expires_at->toISOString(),
        ];
    }

    public function assertApproved(Transfer $transfer): void
    {
        if ($transfer->status !== 'approved' || ! $transfer->approvals()->where('action', 'approved')
            ->where('approver_user_id', '!=', $transfer->user_id)->exists()) {
            throw new RuntimeException('Nium SGD payout requires Admin Approval by an independent checker.');
        }
        $this->assertAuthorization($transfer, 'approved');
    }

    public function reviewSnapshot(Transfer $transfer, ?string $consumingOperation = null): array
    {
        app(NiumSgdLocalPayoutWorkflow::class)->assertCustomerAuthorization($transfer, $consumingOperation);
        $snapshot = $this->snapshot($transfer, $consumingOperation);
        $terms = NiumSgdLocalPayoutGate::assertSubmissionAllowed($transfer);
        app(NiumSgdLocalPayoutWorkflow::class)->assertFunding($transfer);
        $revision = $transfer->raw_data['sgd_local_workflow']['review_revision'] ?? null;
        if (! is_string($revision) || ! Str::isUuid($revision)) {
            throw new RuntimeException('A current maker review is required.');
        }

        return [...$snapshot, 'review_revision' => $revision, 'financial_terms' => $terms];
    }

    /** Exact customer instruction, including local receiving-account changes. No provider I/O. */
    public function customerInstruction(Transfer $transfer, ?string $consumingOperation = null): array
    {
        $snapshot = $this->snapshot($transfer, $consumingOperation);
        $beneficiary = $transfer->beneficiary;
        $account = $beneficiary->only(['full_name', 'company_name', 'account_number', 'iban', 'bank_name',
            'bank_code', 'branch_code', 'swift_bic', 'country_code', 'currency', 'payout_method']);

        return [...$snapshot, 'transfer_id' => $transfer->id, 'transfer_no' => $transfer->transfer_no,
            'client_reference' => $transfer->client_reference, 'payout_method' => 'LOCAL',
            // Preserve existing null-reference receipts; bind every actual payment reference.
            ...($transfer->reference_text === null ? [] : ['reference_text' => $transfer->reference_text]),
            'receiving_account' => ['name' => $beneficiary->full_name, 'bank' => $beneficiary->bank_name,
                'last_four' => substr((string) ($beneficiary->account_number ?: $beneficiary->iban), -4),
                'sha256' => hash('sha256', json_encode($account, JSON_THROW_ON_ERROR))],
            'financial_terms' => NiumSgdLocalPayoutGate::assertSubmissionAllowed($transfer)];
    }

    public function assertDispatchSnapshot(Transfer $transfer): void
    {
        $this->assertAuthorization($transfer, 'submitting', $transfer->provider_operation_key);
    }

    private function assertAuthorization(Transfer $transfer, string $status, ?string $consumingOperation = null): void
    {
        $snapshot = $this->reviewSnapshot($transfer, $consumingOperation);
        $workflow = $transfer->raw_data['sgd_local_workflow'];
        $approval = $transfer->approvals()->whereKey($workflow['approval_id'] ?? null)->where('action', 'approved')->first();
        $checker = $approval?->approver;
        if ($transfer->status !== $status || $checker === null || $checker->id === $transfer->user_id
            || $checker->status !== 'active' || ! $checker->hasPermission('transfers.approve')
            || ! NiumPayoutFxLockContract::sameBinding($workflow['review']['snapshot'] ?? null, $snapshot)
            || ! NiumPayoutFxLockContract::sameBinding($transfer->raw_data['sgd_local_approval'] ?? null, $snapshot)) {
            throw new RuntimeException('Nium SGD payout requires Admin Approval matching the current beneficiary, purpose, principal and FX lock.');
        }
    }
}
