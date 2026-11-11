<?php

namespace App\Services\Wallet;

use App\Models\Transfer;
use App\Models\TransferApproval;
use App\Models\User;
use App\Services\Integrations\ProviderTransferManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransferApprovalService
{
    public function requiresApproval(Transfer $transfer): bool
    {
        if (\App\Services\Nium\NiumTransferReviewService::applies($transfer)) {
            return true;
        }
        if (! (bool) config('wallet.transfer_controls.require_admin_approval', true)) {
            return false;
        }

        $threshold = config('wallet.transfer_controls.approval_threshold_amount', 0);

        return $this->compare($transfer->source_amount, $threshold) >= 0;
    }

    public function initialStatusFor(Transfer $transfer): string
    {
        return $this->requiresApproval($transfer) ? 'approval_required' : 'draft';
    }

    public function ensureApprovedForSubmission(Transfer $transfer): void
    {
        if (\App\Services\Nium\NiumTransferReviewService::applies($transfer)) {
            throw new RuntimeException(\App\Services\Nium\NiumSgdAdmissionFence::DISPATCH_HOLD);
        }
        if (! $this->requiresApproval($transfer)) {
            return;
        }

        $transfer->loadMissing('approvals');

        $hasRejection = $transfer->approvals->contains(
            fn (TransferApproval $approval): bool => $approval->action === 'rejected'
        );

        if ($hasRejection) {
            throw new RuntimeException('This transfer has been rejected and cannot be submitted.');
        }

        $hasApproval = $transfer->approvals->contains(
            fn (TransferApproval $approval): bool => $approval->action === 'approved'
                && (! \App\Services\Nium\NiumTransferReviewService::applies($transfer)
                    || $approval->approver_user_id !== $transfer->user_id)
        );

        if (! $hasApproval) {
            throw new RuntimeException('Admin approval is required before submitting this transfer.');
        }
    }

    public function approve(Transfer $transfer, User $approver, ?string $note = null, ?ProviderTransferManager $manager = null): Transfer
    {
        $sgdLocal = \App\Services\Nium\NiumTransferReviewService::applies($transfer);
        $admissionLease = $sgdLocal ? app(\App\Services\Nium\NiumSgdAdmissionFence::class)->acquire() : null;
        if ($sgdLocal) {
            if ($approver->id === $transfer->user_id || $approver->status !== 'active' || ! $approver->isAdmin()
                || ! $approver->hasPermission('transfers.approve')) {
                throw new RuntimeException('An active independent Admin with transfer approval permission is required.');
            }
        }
        $shouldAutoSubmit = false;
        $approved = DB::transaction(function () use ($transfer, $approver, $note, $sgdLocal, &$shouldAutoSubmit): Transfer {
            $transfer = Transfer::query()
                ->whereKey($transfer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transfer->status === 'approved') {
                if ($sgdLocal) {
                    app(\App\Services\Nium\NiumSgdLocalPayoutAuthorization::class)->assertApproved($transfer);
                }

                return $transfer->fresh(['approvals.approver', 'beneficiary', 'sourceBankAccount']);
            }

            if (in_array($transfer->status, ['pending', 'submitting', 'submission_unknown', 'failed'], true)) {
                return $transfer->fresh(['approvals.approver', 'beneficiary', 'sourceBankAccount']);
            }

            if (! in_array($transfer->status, ['draft', 'approval_required'], true)) {
                throw new RuntimeException('This transfer cannot be approved in its current status.');
            }

            $snapshot = null;
            if ($sgdLocal) {
                if ($approver->id === $transfer->user_id || $approver->status !== 'active'
                    || ! $approver->isAdmin() || ! $approver->hasPermission('transfers.approve')
                    || $transfer->provider_operation_key !== null
                    || filled($transfer->external_transfer_id) || filled($transfer->external_payment_id)) {
                    throw new RuntimeException('An undispatched instruction and an active independent Admin are required.');
                }
                $snapshot = app(\App\Services\Nium\NiumSgdLocalPayoutWorkflow::class)->assertRequestedReview($transfer);
            }

            $approval = $transfer->approvals()->create([
                'approver_user_id' => $approver->id,
                'action' => 'approved',
                'note' => $note,
            ]);

            $shouldAutoSubmit = ! $sgdLocal && $transfer->status === 'approval_required';
            $fields = ['status' => 'approved'];
            if ($sgdLocal) {
                $raw = $transfer->raw_data;
                $raw['sgd_local_approval'] = $snapshot;
                $raw['sgd_local_workflow']['approval_id'] = $approval->id;
                $raw['sgd_local_workflow']['state'] = 'approved';
                $fields['raw_data'] = $raw;
            }
            $transfer->update($fields);

            return $transfer->fresh(['approvals.approver', 'beneficiary', 'sourceBankAccount']);
        });

        $approved->loadMissing('provider');
        if ($shouldAutoSubmit && $manager !== null && strtolower((string) $approved->provider?->code) === 'nium') {
            return $manager->submitTransfer($approved->provider, $approved->load(['user', 'beneficiary', 'sourceBankAccount']));
        }

        return $approved;
    }

    public function reject(Transfer $transfer, User $approver, ?string $note = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $approver, $note): Transfer {
            $transfer = Transfer::query()
                ->whereKey($transfer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($transfer->status, ['draft', 'approval_required', 'approved'], true)) {
                throw new RuntimeException('This transfer cannot be rejected in its current status.');
            }

            $transfer->approvals()->create([
                'approver_user_id' => $approver->id,
                'action' => 'rejected',
                'note' => $note,
            ]);

            $transfer->update([
                'status' => 'rejected',
                'failure_code' => 'admin_rejected',
                'failure_reason' => $note ?: 'Transfer rejected by admin.',
                'completed_at' => now(),
            ]);

            return $transfer->fresh(['approvals.approver', 'beneficiary', 'sourceBankAccount']);
        });
    }

    private function compare(mixed $left, mixed $right): int
    {
        $left = $this->decimal($left);
        $right = $this->decimal($right);

        if (function_exists('bccomp')) {
            return bccomp($left, $right, 8);
        }

        return (float) $left <=> (float) $right;
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 8, '.', '');
    }
}
