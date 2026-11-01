<?php

namespace App\Services\Nium;

use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use RuntimeException;

/** Backend-only maker attribution and checker authorization; no provider I/O. */
final class NiumSwiftPayoutAuthorization
{
    public function applies(Transfer $transfer): bool
    {
        $transfer->loadMissing('provider');

        return strtolower((string) $transfer->provider?->code) === 'nium'
            && $transfer->target_currency === 'USD';
    }

    public static function origin(User $maker, User $owner, IntegrationProvider $provider): array
    {
        return ['version' => 1, 'maker_user_id' => $maker->id,
            'owner_user_id' => $owner->id, 'provider_id' => $provider->id];
    }

    public function assertMaker(Transfer $transfer): int
    {
        $origin = $transfer->raw_data['nium_approval'] ?? [];
        $maker = $origin['maker_user_id'] ?? null;
        if (($origin['version'] ?? null) !== 1 || ! is_int($maker) || $maker <= 0
            || ($origin['owner_user_id'] ?? null) !== $transfer->user_id
            || ($origin['provider_id'] ?? null) !== $transfer->provider_id
            || ! User::query()->whereKey($maker)->exists()
            || ! $transfer->user()->exists()) {
            throw new RuntimeException('Verified payment maker attribution is required; legacy or conflicting records cannot authorize a new payout.');
        }

        return $maker;
    }

    public function assertChecker(Transfer $transfer, User $checker): void
    {
        $maker = $this->assertMaker($transfer);
        // Refresh permission/status even for direct service calls with a stale User model.
        $current = User::query()->find($checker->id);
        if ($checker->id === $maker || $checker->id === $transfer->user_id) {
            throw new RuntimeException('The payment maker cannot approve their own controlled payout.');
        }
        if ($current === null || $current->status !== 'active' || ! $current->hasPermission('transfers.approve')) {
            throw new RuntimeException('An active authorized independent checker is required.');
        }
    }

    public function assertApproved(Transfer $transfer): void
    {
        $this->assertMaker($transfer);
        $approvals = $transfer->approvals()->orderBy('id')->get();
        if ($approvals->contains('action', 'rejected')) {
            throw new RuntimeException('This transfer has been rejected and cannot be submitted.');
        }
        $approved = $approvals->where('action', 'approved');
        if ($approved->count() !== 1) {
            throw new RuntimeException('Admin approval is required before submitting this transfer.');
        }
        $approval = $approved->first();
        $checker = User::query()->find($approval->approver_user_id);
        if ($checker === null) {
            throw new RuntimeException('An active authorized independent checker is required.');
        }
        $this->assertChecker($transfer, $checker);
    }

    public function canApprove(Transfer $transfer, ?User $checker): bool
    {
        // SWIFT has no approved-before-dispatch resume action. Preserve that contract.
        if ($checker === null || ! in_array($transfer->status, ['draft', 'approval_required'], true)
            || $transfer->provider_operation_key !== null || filled($transfer->external_transfer_id)
            || filled($transfer->external_payment_id) || $transfer->approvals()->exists()) {
            return false;
        }
        try {
            $this->assertChecker($transfer, $checker);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
