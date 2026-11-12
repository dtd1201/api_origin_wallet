<?php

declare(strict_types=1);

namespace App\Services\Nium\DynamicConfirmation;

use App\Services\Wallet\Concurrency\OwnerFirst;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\NiumDynamicIntent;
use App\Models\NiumDynamicPayoutOutbox;
use App\Models\NiumDynamicReservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class DynamicPayoutDispatcher
{
    public function __construct(private readonly DynamicTransferConfirmationService $confirmation,
        private readonly DynamicPayoutTransport $transport) {}

    public function dispatch(string $id): void
    {
        OwnerFirst::outside();
        DynamicPayoutGate::assertDispatchEnabled();
        try {
            $pending = NiumDynamicPayoutOutbox::query()->findOrFail($id);
            if ($pending->state !== 'held' || $pending->attempts !== 0) return;
            $this->confirmation->refreshForExecution(NiumDynamicIntent::query()->findOrFail($pending->intent_id));
            $claimed = $this->locked($id, function ($intent, $outbox, $reservation): bool {
                if ($outbox->state !== 'held' || $outbox->attempts !== 0 || $outbox->claim_token !== null
                    || $outbox->submission_started_at !== null || $outbox->provider_reference !== null) {
                    return false;
                }
                $this->confirmation->revalidateForExecution($intent);
                $this->assertReservation($intent, $outbox, $reservation);
                $key = $intent->confirmed_terms['execution_operation_key'] ?? (string) Str::uuid();
                $json = DynamicPayoutRequest::json($intent, $key);
                DynamicPayoutDispatcher::persist($outbox->forceFill(['state' => 'claimed', 'claimed_at' => now(),
                    'claim_token' => (string) Str::uuid(), 'operation_key' => $key,
                    'request_json' => $json, 'request_sha256' => hash('sha256', $json)]));

                return true;
            });
        } catch (Throwable $exception) {
            // Another intent for this wallet is in flight. Keep this unsent intent held;
            // turning it into a wallet exception would also obstruct the active sender.
            if ($exception->getMessage() === 'dynamic_wallet_has_unresolved_payout') {
                return;
            }
            $this->locked($id, function ($intent, $outbox) use ($exception): void {
                if ($outbox->state === 'held') {
                    self::exception($outbox, self::reason($exception));
                }
            });

            return;
        }
        if (! $claimed) {
            return;
        }
        $outbox = NiumDynamicPayoutOutbox::query()->findOrFail($id);
        $intent = NiumDynamicIntent::query()->findOrFail($outbox->intent_id);
        try {
            // Never inside a retryable transaction. No POST recovery path exists.
            $result = $this->transport->submit($intent, $outbox,
                new DynamicPayoutWireClaim($this, $id, $outbox->claim_token));
        } catch (Throwable) {
            $result = ['http_status' => null, 'provider_reference' => null];
        }
        $this->locked($id, function ($intent, $current) use ($outbox, $result): void {
            if ($current->claim_token !== $outbox->claim_token || $current->state !== 'claimed') {
                return;
            }
            if ($current->attempts !== 1 || $current->submission_started_at === null) {
                self::exception($current, 'dynamic_submission_not_started');

                return;
            }
            $http = $result['http_status'];
            $reference = $result['provider_reference'];
            if (is_string($reference) && $current->provider_reference !== null
                && $reference !== $current->provider_reference) {
                self::exception($current, 'dynamic_provider_reference_conflict');

                return;
            }
            $known = is_int($http) && $http >= 200 && $http < 300
                && is_string($reference) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $reference) === 1;
            DynamicPayoutDispatcher::persist($current->forceFill(['state' => $known ? 'submitted' : 'ambiguous',
                'provider_reference' => is_string($reference) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $reference) === 1
                    ? $reference : $current->provider_reference, 'next_reconciliation_at' => now(),
                'exception_code' => $known ? null : 'dynamic_provider_submission_unknown']));
            if (! $known) {
                self::alert($current, 'dynamic_provider_submission_unknown');
            }
        });
    }

    /** Called immediately before the sole wire attempt, after authentication preparation. */
    public function beginWire(string $id, string $token): void
    {
        OwnerFirst::outside();
        DynamicPayoutGate::assertDispatchEnabled();
        $pending = NiumDynamicPayoutOutbox::query()->findOrFail($id);
        $this->confirmation->refreshForExecution(NiumDynamicIntent::query()->findOrFail($pending->intent_id));
        $this->locked($id, function ($intent, $outbox, $reservation) use ($token): void {
            if ($outbox->state !== 'claimed' || $outbox->claim_token !== $token
                || $outbox->attempts !== 0 || $outbox->submission_started_at !== null) {
                throw new RuntimeException('dynamic_submission_already_claimed');
            }
            $this->confirmation->revalidateForExecution($intent);
            $this->assertReservation($intent, $outbox, $reservation);
            if ($outbox->request_json !== DynamicPayoutRequest::json($intent, $outbox->operation_key)) {
                throw new RuntimeException('dynamic_payout_request_changed');
            }
            DynamicPayoutDispatcher::persist($outbox->forceFill(['attempts' => 1, 'submission_started_at' => now(),
                'next_reconciliation_at' => now()->addSeconds(120)]));
        });
    }

    /** A lost worker may have sent the POST. Stale claims are never returned to held. */
    public function recoverInterrupted(string $id): void
    {
        $this->locked($id, function ($intent, $outbox): void {
            if ($outbox->state !== 'claimed' || $outbox->claimed_at === null
                || $outbox->claimed_at->addSeconds(max(60, (int) config('nium_dynamic_payouts.claim_timeout_seconds', 120)))->isFuture()) {
                return;
            }
            if ($outbox->attempts === 1) {
                DynamicPayoutDispatcher::persist($outbox->forceFill(['state' => 'ambiguous', 'next_reconciliation_at' => now(),
                    'exception_code' => 'dynamic_submission_worker_interrupted']));
                self::alert($outbox, 'dynamic_submission_worker_interrupted');
            } else {
                self::exception($outbox, 'dynamic_claim_interrupted_before_submission');
            }
        });
    }

    private function assertReservation(NiumDynamicIntent $intent, NiumDynamicPayoutOutbox $outbox, NiumDynamicReservation $reservation): void
    {
        $hold = LedgerEntry::query()->lockForUpdate()->findOrFail($reservation->hold_entry_id);
        if ($reservation->intent_id !== $intent->id || $outbox->reservation_id !== $reservation->id
            || $outbox->terms_fingerprint !== $intent->terms_fingerprint || $reservation->state !== 'held'
            || $reservation->release_entry_id !== null || $reservation->debit_entry_id !== null
            || (string) $reservation->balance_id !== (string) $intent->balance_id
            || $reservation->currency !== 'USD' || $intent->source_currency !== 'USD'
            || DynamicReservationLedger::storage($reservation->amount) !== DynamicReservationLedger::storage($intent->total_debit)
            || $hold->source_type !== 'nium_dynamic_reservation' || $hold->source_id !== $reservation->id
            || $hold->reference !== 'dynamic:'.$reservation->id.':hold' || $hold->entry_type !== 'hold'
            || $hold->status !== 'posted' || (string) $hold->balance_id !== (string) $intent->balance_id
            || (string) $hold->user_id !== (string) $intent->user_id || (string) $hold->provider_id !== (string) $intent->provider_id
            || $hold->currency !== 'USD' || ! \Brick\Math\BigDecimal::of($hold->amount)->negated()->isEqualTo($intent->total_debit)
            || ($hold->raw_data['terms_fingerprint'] ?? null) !== $intent->terms_fingerprint) {
            throw new RuntimeException('dynamic_execution_reservation_unverified');
        }
    }

    /** Shared owner-first lock order with confirmation/cancellation. Callback must not perform I/O. */
    public function locked(string $id, callable $work): mixed
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('dynamic_execution_requires_postgresql');
        }
        $found = NiumDynamicPayoutOutbox::query()->findOrFail($id);
        $owner = NiumDynamicIntent::query()->findOrFail($found->intent_id)->user_id;

        return OwnerFirst::users([$owner], function ($context) use ($owner, $found, $id, $work) {
            User::query()->lockForUpdate()->findOrFail($owner);
            $intent = NiumDynamicIntent::query()->lockForUpdate()->findOrFail($found->intent_id);
            $reservation = NiumDynamicReservation::query()->where('intent_id', $intent->id)->lockForUpdate()->sole();
            $outbox = NiumDynamicPayoutOutbox::query()->lockForUpdate()->findOrFail($id);

            if ((string) $intent->user_id !== (string) $owner || $outbox->intent_id !== $intent->id
                || $outbox->reservation_id !== $reservation->id || $reservation->intent_id !== $intent->id) {
                throw new \App\Services\Wallet\Concurrency\OwnerBindingChanged('Dynamic execution binding changed.');
            }

            return $work($intent, $outbox, $reservation, $context);
        }, 3);
    }

    public static function exception(NiumDynamicPayoutOutbox $outbox, string $code): void
    {
        $same = $outbox->state === 'exception' && $outbox->exception_code === $code;
        DynamicPayoutDispatcher::persist($outbox->forceFill(['state' => 'exception', 'exception_code' => $code, 'next_reconciliation_at' => null]));
        if (! $same) {
            self::alert($outbox, $code);
        }
    }

    private static function alert(NiumDynamicPayoutOutbox $outbox, string $code): void
    {
        $audit = AuditLog::query()->create(['user_id' => null, 'action' => 'nium_dynamic_financial_exception',
            'entity_type' => 'nium_dynamic_payout', 'entity_id' => $outbox->id,
            'new_data' => ['intent_id' => $outbox->intent_id, 'reason' => $code,
                'financial_state' => $outbox->financial_state, 'risk_state' => $outbox->financial_risk_state]]);
        if (! $audit->exists) {
            throw new RuntimeException('dynamic_exception_alert_not_persisted');
        }
        DB::afterCommit(static fn () => Log::critical('Nium dynamic payout requires Admin reconciliation.',
            ['outbox_id' => $outbox->id, 'reason' => $code]));
    }

    private static function reason(Throwable $exception): string
    {
        $reason = $exception->getMessage();

        return preg_match('/^dynamic_[a-z_]{1,85}$/D', $reason) === 1 ? $reason : 'dynamic_execution_evidence_unavailable';
    }

    public static function persist(\Illuminate\Database\Eloquent\Model $record): void
    {
        if (! $record->save()) {
            throw new RuntimeException('dynamic_execution_state_not_persisted');
        }
    }
}
