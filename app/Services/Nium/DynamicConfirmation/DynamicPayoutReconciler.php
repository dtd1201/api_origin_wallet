<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Models\Balance;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

final class DynamicPayoutReconciler
{
    public function __construct(private readonly DynamicPayoutDispatcher $dispatcher,
        private readonly DynamicPayoutTransport $transport, private readonly DynamicPayoutAccounting $accounting,
        private readonly DynamicReservationLedger $ledger) {}

    public function reconcile(string $id): void
    {
        if (config('nium_dynamic_payouts.reconciliation_enabled', false) !== true) {
            return;
        }
        $token = (string) Str::uuid();
        $claimed = $this->dispatcher->locked($id, function ($intent, $outbox, $reservation) use ($token): array|false {
            if ($outbox->attempts !== 1 || ! in_array($outbox->state, ['submitted', 'ambiguous', 'exception', 'matched'], true)
                || $outbox->next_reconciliation_at === null || $outbox->next_reconciliation_at->isFuture()
                || ($outbox->reconciliation_claimed_at !== null
                    && $outbox->reconciliation_claimed_at->addSeconds(120)->isFuture())) {
                return false;
            }
            $postMatch = $outbox->state === 'matched';
            if ($postMatch) {
                $receipt = $outbox->financial_evidence ?? [];
                $recovery = $receipt['post_match_recovery'] ?? null;
                $delays = $this->postMatchDelays();
                if ($reservation->state !== 'consumed' || $outbox->financial_state !== 'matched'
                    || ! is_array($recovery) || ! is_int($recovery['attempts'] ?? null)
                    || ! is_string($recovery['expires_at'] ?? null)
                    || CarbonImmutable::parse($recovery['expires_at'])->isPast()
                    || $recovery['attempts'] >= count($delays)) {
                    DynamicPayoutDispatcher::persist($outbox->forceFill(['next_reconciliation_at' => null]));
                    return false;
                }
                $receipt['post_match_recovery']['attempts']++;
                $outbox->financial_evidence = $receipt;
            }
            if (! $postMatch && $outbox->reconciliation_attempts >= max(1, (int) config('nium_dynamic_payouts.max_reconciliation_attempts', 8))) {
                $outbox->financial_state = 'unavailable';
                DynamicPayoutDispatcher::exception($outbox, 'dynamic_reconciliation_exhausted');

                return false;
            }
            DynamicPayoutDispatcher::persist($outbox->forceFill(['reconciliation_token' => $token, 'reconciliation_claimed_at' => now(),
                'reconciliation_attempts' => $outbox->reconciliation_attempts + ($postMatch ? 0 : 1)]));

            return ['post_match' => $postMatch];
        });
        if (! $claimed) {
            return;
        }
        $outbox = NiumDynamicPayoutOutbox::query()->findOrFail($id);
        $intent = NiumDynamicIntent::query()->findOrFail($outbox->intent_id);
        try {
            $batch = $this->transport->transactions($intent);
            $result = $this->accounting->evaluate($intent, $outbox, $batch);
        } catch (Throwable) {
            $result = ['state' => 'unavailable', 'reason' => 'dynamic_authenticated_accounting_unavailable'];
        }
        try {
            $this->dispatcher->locked($id, function ($intent, $current, $reservation) use ($token, $result, $claimed): void {
                if ($current->reconciliation_token !== $token) {
                    return;
                }
                $current->forceFill(['reconciliation_token' => null, 'reconciliation_claimed_at' => null,
                    'reconciled_at' => now()]);
                if (isset($result['provider_reference'])) {
                    if ($current->provider_reference !== null && $current->provider_reference !== $result['provider_reference']) {
                        DynamicPayoutDispatcher::exception($current, 'dynamic_provider_reference_conflict');

                        return;
                    }
                    $current->provider_reference = $result['provider_reference'];
                }
                // Webhook exceptions cannot be cleared by a stale in-flight read.
                if (in_array($current->provider_status, ['RETURNED', 'REJECTED', 'FAILED', 'CANCELLED'], true)) {
                    DynamicPayoutDispatcher::exception($current, 'dynamic_provider_terminal_exception');

                    return;
                }
                if (isset($result['provider_status'])) {
                    $current->provider_status = $result['provider_status'];
                }
                if ($claimed['post_match']) {
                    // A later read never consumes the reservation again, erases the
                    // original receipt, or turns a returned payout into a local refund.
                    $receipt = $current->financial_evidence;
                    $receipt['post_match_recovery']['last_result'] = $result['state'];
                    $receipt['post_match_recovery']['last_reason'] = $result['reason'];
                    $receipt['post_match_recovery']['last_checked_at'] = now()->toISOString();
                    if (isset($result['receipt'])) {
                        $receipt['post_match_recovery']['last_receipt'] = $result['receipt'];
                    }
                    $current->financial_evidence = $receipt;
                    if (in_array($result['state'], ['exception', 'mismatched'], true)) {
                        $current->financial_risk_state = 'PROVIDER_EXCEPTION_REQUIRES_REVIEW';
                        DynamicPayoutDispatcher::exception($current, $result['reason']);
                    } else {
                        $this->schedulePostMatch($current);
                    }
                    return;
                }
                $current->financial_state = $result['state'];
                if (isset($result['receipt'])) {
                    $current->financial_evidence = $result['receipt'];
                }
                if ($result['state'] === 'matched') {
                    $this->ledger->consume($intent, $reservation, Balance::query()->lockForUpdate()->findOrFail($intent->balance_id));
                    $receipt = $current->financial_evidence;
                    $receipt['post_match_recovery'] = ['attempts' => 0,
                        'expires_at' => now()->addSeconds(max(60, min(604800, (int) config('nium_dynamic_payouts.post_match_reconciliation_window_seconds', 604800))))->toISOString()];
                    $current->financial_evidence = $receipt;
                    DynamicPayoutDispatcher::persist($current->forceFill(['state' => 'matched', 'financial_risk_state' => 'OBSERVED_PROVIDER_ACCOUNTING_MATCHED',
                        'exception_code' => null, 'next_reconciliation_at' => null]));
                    $this->schedulePostMatch($current);
                } elseif (in_array($result['state'], ['mismatched', 'exception'], true)) {
                    if ($result['state'] === 'mismatched' && isset($result['receipt'])) {
                        // Settled authenticated provider observations establish the
                        // operation, not a new customer price. Consume only consent.
                        $this->ledger->consume($intent, $reservation, Balance::query()->lockForUpdate()->findOrFail($intent->balance_id));
                        $current->financial_risk_state = 'PROVIDER_ACCOUNTING_DISCREPANCY_REQUIRES_REVIEW';
                    }
                    if ($result['state'] === 'exception') {
                        $current->financial_risk_state = 'PROVIDER_EXCEPTION_REQUIRES_REVIEW';
                    }
                    DynamicPayoutDispatcher::exception($current, $result['reason']);
                } else {
                    $delays = config('nium_dynamic_payouts.reconciliation_delays_seconds', [60]);
                    $delay = max(30, min(86400, (int) ($delays[min($current->reconciliation_attempts - 1, count($delays) - 1)] ?? 60)));
                    if ($current->reconciliation_attempts >= max(1, (int) config('nium_dynamic_payouts.max_reconciliation_attempts', 8))) {
                        $current->financial_state = 'unavailable';
                        DynamicPayoutDispatcher::exception($current, 'dynamic_reconciliation_exhausted');
                    } else {
                        DynamicPayoutDispatcher::persist($current->forceFill(['exception_code' => $result['reason'],
                            'next_reconciliation_at' => now()->addSeconds($delay)]));
                    }
                }
            });
        } catch (Throwable) {
            $this->dispatcher->locked($id, function ($intent, $current) use ($token): void {
                if ($current->reconciliation_token === $token) {
                    DynamicPayoutDispatcher::exception($current, 'dynamic_financial_settlement_requires_review');
                }
            });
        }
    }

    private function postMatchDelays(): array
    {
        return array_slice(array_values((array) config('nium_dynamic_payouts.post_match_reconciliation_delays_seconds',
            [3600, 21600, 86400, 259200])), 0, 8);
    }

    private function schedulePostMatch(NiumDynamicPayoutOutbox $outbox): void
    {
        $recovery = $outbox->financial_evidence['post_match_recovery'];
        $delays = $this->postMatchDelays();
        $next = isset($delays[$recovery['attempts']])
            ? now()->addSeconds(max(60, min(259200, (int) $delays[$recovery['attempts']]))) : null;
        if ($next !== null && $next->greaterThan(CarbonImmutable::parse($recovery['expires_at']))) {
            $next = null;
        }
        DynamicPayoutDispatcher::persist($outbox->forceFill(['next_reconciliation_at' => $next]));
    }
}
