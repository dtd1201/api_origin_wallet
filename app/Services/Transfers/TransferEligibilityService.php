<?php

namespace App\Services\Transfers;

use App\Models\Balance;
use App\Models\IntegrationProvider;
use App\Models\Transfer;
use App\Models\User;
use RuntimeException;

class TransferEligibilityService
{
    public function ensureUserCanCreateForProvider(User $user, IntegrationProvider $provider): void
    {
        $providerAccount = $user->providerAccounts()
            ->where('provider_id', $provider->id)
            ->first();

        if ($providerAccount === null) {
            throw new RuntimeException('User has not linked an account with this provider yet.');
        }

        if (! in_array($providerAccount->status, ['submitted', 'under_review', 'active'], true)) {
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

        if (! in_array($transfer->status, ['draft', 'pending'], true)) {
            throw new RuntimeException('Only draft or pending transfers can be submitted.');
        }

        $balance = Balance::query()
            ->where('user_id', $user->id)
            ->where('provider_id', $provider->id)
            ->where('currency', $transfer->source_currency)
            ->orderByDesc('as_of')
            ->first();

        if ($balance !== null && (float) $balance->available_balance < (float) $transfer->source_amount) {
            throw new RuntimeException('Insufficient available balance for this transfer.');
        }
    }
}
