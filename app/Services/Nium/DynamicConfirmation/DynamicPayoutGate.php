<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Models\NiumDynamicPayoutOutbox;
use RuntimeException;

final class DynamicPayoutGate
{
    public static function assertDispatchEnabled(): void
    {
        if (config('nium_dynamic_confirmation.automatic_dispatch_enabled', false) !== true
            || config('nium_dynamic_confirmation.enabled', false) !== true
            || config('nium_dynamic_quotes.quote_contract_enabled', false) !== true
            || config('nium_dynamic_quotes.confirmation_contract_enabled', false) !== true) {
            throw new RuntimeException('dynamic_automatic_dispatch_disabled');
        }
        $environment = DynamicPayoutEnvironment::configured();
        if ($environment->identity === 'production') {
            $reference = config('nium_dynamic_payouts.operator_authorization_reference');
            if (config('nium_dynamic_payouts.live_automatic_dispatch_authorized', false) !== true
                || ! is_string($reference) || trim($reference) === '' || strlen($reference) > 255
                || preg_match('/[\x00-\x1F\x7F]/', $reference)) {
                throw new RuntimeException('dynamic_live_dispatch_requires_operator_authorization');
            }
        }
        if (config('nium_dynamic_payouts.reconciliation_enabled', false) !== true) {
            throw new RuntimeException('dynamic_dispatch_requires_reconciliation');
        }
        $environment->assertContract('remittance');
        // A supported POST must not admit funds while its GET recovery contract
        // is known to be unverified, even when the reconciliation flag is enabled.
        $environment->assertContract('transactions');
    }

    public static function assertWalletClear(int $providerId, string $wallet, ?string $exceptIntent = null): void
    {
        if (NiumDynamicPayoutOutbox::query()->join('nium_dynamic_intents as i', 'i.id', '=', 'nium_dynamic_payout_outbox.intent_id')
            ->where('i.provider_id', $providerId)->where('i.source_wallet', $wallet)
            ->when($exceptIntent !== null, fn ($q) => $q->where('i.id', '!=', $exceptIntent))
            ->where(function ($q): void {
                $q->whereIn('nium_dynamic_payout_outbox.state', ['claimed', 'submitted', 'ambiguous'])
                    ->orWhere(fn ($exceptions) => $exceptions->where('nium_dynamic_payout_outbox.state', 'exception')
                        ->where('nium_dynamic_payout_outbox.attempts', 1));
            })
            ->exists()) {
            throw new RuntimeException('dynamic_wallet_has_unresolved_payout');
        }
    }
}
