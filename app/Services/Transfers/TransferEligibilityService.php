<?php

namespace App\Services\Transfers;

use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Wallet\TransferApprovalService;
use RuntimeException;

class TransferEligibilityService
{
    public function __construct(
        private readonly TransferApprovalService $approvalService,
    ) {}

    public function ensureUserCanCreateForProvider(User $user, IntegrationProvider $provider): void
    {
        if (! in_array(strtolower((string) $user->kyc_status), ['verified', 'approved'], true)) {
            throw new RuntimeException('User KYC/KYB must be verified before creating transfers.');
        }

        $providerAccount = $user->providerAccounts()
            ->where('provider_id', $provider->id)
            ->first();

        if ($providerAccount === null) {
            throw new RuntimeException('User has not linked an account with this provider yet.');
        }

        $allowedStatuses = (array) config('wallet.transfer_controls.allowed_provider_account_statuses', ['active']);

        if ($allowedStatuses === []) {
            $allowedStatuses = ['active'];
        }

        if (! in_array($providerAccount->status, $allowedStatuses, true)) {
            throw new RuntimeException('Provider account is not ready for transfers yet.');
        }
    }

    public function ensureTransferCanBeSubmitted(Transfer $transfer): void
    {
        $provider = $transfer->provider;
        $user = $transfer->user;

        if ($provider === null || $user === null) {
            throw new RuntimeException('Transfer is missing provider or user.');
        }

        $this->ensureUserCanCreateForProvider($user, $provider);

        if ($transfer->beneficiary_id === null || $transfer->beneficiary === null) {
            throw new RuntimeException('Transfer beneficiary is required before submission.');
        }

        if ($transfer->beneficiary->provider_id !== $provider->id) {
            throw new RuntimeException('Beneficiary provider does not match transfer provider.');
        }

        if (! in_array($transfer->status, ['draft', 'approval_required', 'approved'], true)) {
            throw new RuntimeException('Only draft, approval required, or approved transfers can be submitted before provider submission.');
        }

        $this->approvalService->ensureApprovedForSubmission($transfer);

        $balance = Balance::query()
            ->where('user_id', $user->id)
            ->where('provider_id', $provider->id)
            ->where('currency', $transfer->source_currency)
            ->orderByDesc('as_of')
            ->first();

        $wallet = (array) (($transfer->raw_data ?? [])['wallet'] ?? []);
        $hasExistingHold = filled($wallet['hold_reference'] ?? null);

        if ($balance === null && (bool) config('wallet.ledger.require_synced_balance', true)) {
            throw new RuntimeException('A synced wallet balance is required before submitting this transfer.');
        }

        if (! $hasExistingHold && $balance !== null && $this->compare($balance->available_balance, $transfer->source_amount) < 0) {
            throw new RuntimeException('Insufficient available balance for this transfer.');
        }
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
