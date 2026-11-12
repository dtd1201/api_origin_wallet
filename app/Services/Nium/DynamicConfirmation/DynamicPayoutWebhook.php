<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Models\IntegrationProvider;
use App\Models\NiumDynamicPayoutOutbox;
use RuntimeException;

/** Called only inside the existing authenticated, deduplicated Nium webhook infrastructure. */
final class DynamicPayoutWebhook
{
    public function __construct(private readonly DynamicPayoutDispatcher $dispatcher) {}

    public function observe(IntegrationProvider $provider, array $payload, array $resource): bool
    {
        if (config('nium_dynamic_payouts.reconciliation_enabled', false) !== true
            && config('nium_dynamic_confirmation.automatic_dispatch_enabled', false) !== true) {
            return false;
        }
        $data = array_merge($payload, $resource);
        $reference = $data['systemReferenceNumber'] ?? $data['system_reference_number'] ?? $data['authCode'] ?? null;
        $external = $data['externalId'] ?? null;
        if (! is_string($reference) && ! is_string($external)) {
            return false;
        }
        $ids = NiumDynamicPayoutOutbox::query()->join('nium_dynamic_intents as i', 'i.id', '=', 'nium_dynamic_payout_outbox.intent_id')
            ->where('i.provider_id', $provider->id)->where('attempts', 1)
            ->where(function ($q) use ($reference, $external): void {
                if (is_string($reference)) {
                    $q->where('provider_reference', $reference);
                }
                if (is_string($external)) {
                    $q->orWhere('operation_key', $external);
                }
            })->select('nium_dynamic_payout_outbox.id')->get();
        if ($ids->isEmpty()) {
            return false;
        }
        if ($ids->count() !== 1) {
            throw new RuntimeException('dynamic_webhook_reference_conflict');
        }
        $this->dispatcher->locked($ids->sole()->id, function ($intent, $outbox) use ($data, $reference, $external): void {
            OwnerFirst::current([$intent->user_id]);
            $c = $intent->confirmed_terms['context']['corridor'];
            if (($data['clientHashId'] ?? null) !== $c['client'] || ($data['customerHashId'] ?? null) !== $intent->customer
                || ($data['walletHashId'] ?? null) !== $intent->source_wallet
                || (is_string($external) && $external !== $outbox->operation_key)
                || (is_string($reference) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $reference) !== 1)
                || (is_string($reference) && $outbox->provider_reference !== null && $reference !== $outbox->provider_reference)) {
                throw new RuntimeException('dynamic_webhook_scope_unverified');
            }
            if (is_string($reference)) {
                // A callback can beat the POST response, including one that times out.
                $outbox->provider_reference = $reference;
            }
            $status = strtoupper(trim((string) ($data['status'] ?? $data['subStatus'] ?? '')));
            $status = match (strtoupper((string) ($data['template'] ?? ''))) {
                'REMIT_TRANSACTION_RETURNED_WEBHOOK' => 'RETURNED',
                'REMIT_TRANSACTION_REJECTED_WEBHOOK' => 'REJECTED',
                'REMIT_TRANSACTION_PAID_WEBHOOK' => 'PAID',
                default => $status,
            };
            $status = match ($status) {
                'DECLINED' => 'REJECTED',
                'ERROR', 'BLOCKED' => 'FAILED',
                default => $status,
            };
            if (in_array($status, ['RETURNED', 'REJECTED', 'FAILED', 'CANCELLED'], true)) {
                // A late failure must not downgrade an already observed return.
                $outbox->provider_status = $outbox->provider_status === 'RETURNED' ? 'RETURNED' : $status;
                $outbox->financial_risk_state = 'PROVIDER_EXCEPTION_REQUIRES_REVIEW';
                DynamicPayoutDispatcher::exception($outbox, 'dynamic_provider_terminal_exception');
                // Preserve any consumed debit. A return is never authority to repost or refund locally.
            } else {
                if (! in_array($outbox->provider_status, ['RETURNED', 'REJECTED', 'FAILED', 'CANCELLED'], true)) {
                    if ($status === 'PAID') {
                        $outbox->provider_status = 'PAID';
                    }
                    if ($outbox->state !== 'matched' && $outbox->state !== 'exception') {
                        $outbox->next_reconciliation_at = now();
                    }
                }
                DynamicPayoutDispatcher::persist($outbox);
            }
            // Browser/webhook amounts never mark accounting matched; only the authenticated GET does.
        });

        return true;
    }
}
