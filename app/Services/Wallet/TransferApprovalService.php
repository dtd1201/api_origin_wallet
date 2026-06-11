<?php

namespace App\Services\Wallet;

use App\Models\Transfer;
use App\Models\TransferApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransferApprovalService
{
    public function requiresApproval(Transfer $transfer): bool
    {
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
        );

        if (! $hasApproval) {
            throw new RuntimeException('Admin approval is required before submitting this transfer.');
        }
    }

    public function approve(Transfer $transfer, User $approver, ?string $note = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $approver, $note): Transfer {
            $transfer = Transfer::query()
                ->whereKey($transfer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transfer->status === 'approved') {
                return $transfer->fresh(['approvals.approver', 'beneficiary', 'sourceBankAccount']);
            }

            if (! in_array($transfer->status, ['draft', 'approval_required'], true)) {
                throw new RuntimeException('This transfer cannot be approved in its current status.');
            }

            $transfer->approvals()->create([
                'approver_user_id' => $approver->id,
                'action' => 'approved',
                'note' => $note,
            ]);

            $transfer->update(['status' => 'approved']);

            return $transfer->fresh(['approvals.approver', 'beneficiary', 'sourceBankAccount']);
        });
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
