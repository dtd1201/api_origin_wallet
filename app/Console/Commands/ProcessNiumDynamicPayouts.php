<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NiumDynamicPayoutOutbox;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutDispatcher;
use App\Services\Nium\DynamicConfirmation\DynamicPayoutReconciler;
use Illuminate\Console\Command;
use Throwable;

final class ProcessNiumDynamicPayouts extends Command
{
    protected $signature = 'nium:process-dynamic-payouts {--limit=50}';

    protected $description = 'Process guarded dynamic outbox claims and bounded read-only financial reconciliation';

    public function handle(DynamicPayoutDispatcher $dispatcher, DynamicPayoutReconciler $reconciler): int
    {
        $dispatch = config('nium_dynamic_confirmation.automatic_dispatch_enabled', false) === true;
        $reconcile = config('nium_dynamic_payouts.reconciliation_enabled', false) === true;
        if (! $dispatch && ! $reconcile) {
            $this->info('Nium dynamic payout processing is disabled.');

            return self::SUCCESS;
        }
        $limit = max(1, min(200, (int) $this->option('limit')));
        $failed = false;
        foreach (NiumDynamicPayoutOutbox::query()->where(function ($q): void {
            $q->where('state', 'claimed')->orWhere(function ($q): void {
                $q->where('state', 'exception')->where('attempts', 0)->whereExists(function ($q): void {
                    $q->selectRaw('1')->from('nium_dynamic_reservations as r')
                        ->whereColumn('r.id', 'nium_dynamic_payout_outbox.reservation_id')->where('r.state', 'held');
                });
            })->orWhere(function ($q): void {
                $q->where('state', 'held')->where('attempts', 0)->whereExists(function ($q): void {
                    $q->selectRaw('1')->from('nium_dynamic_intents as i')
                        ->whereColumn('i.id', 'nium_dynamic_payout_outbox.intent_id')
                        ->where('i.quote_expires_at', '<=', now()->format('Y-m-d H:i:s.uP'));
                });
            });
        })->orderBy('created_at')->limit($limit)->get() as $row) {
            try {
                $dispatcher->recoverInterrupted($row->id);
            } catch (Throwable) {
                $failed = true;
                $this->error('Dynamic payout '.$row->id.' reservation recovery requires review.');
            }
        }
        if ($reconcile) {
            foreach (NiumDynamicPayoutOutbox::query()->where('attempts', 1)
                // Query bindings use the connection's format, not the model's dateFormat.
                ->where(function ($q): void {
                    $q->where('next_reconciliation_at', '<=', now()->format('Y-m-d H:i:s.uP'))
                        ->orWhere(fn ($q) => $q->where('state', 'exception')->whereNull('next_reconciliation_at'));
                })
                ->orderBy('next_reconciliation_at')->limit($limit)->get() as $row) {
                try {
                    $reconciler->reconcile($row->id);
                } catch (Throwable) {
                    $failed = true;
                    $this->error('Dynamic payout '.$row->id.' requires reconciliation review.');
                }
            }
        }
        if ($dispatch) {
            foreach (NiumDynamicPayoutOutbox::query()->where('state', 'held')->orderBy('created_at')->limit($limit)->get() as $row) {
                try {
                    $dispatcher->dispatch($row->id);
                } catch (Throwable) {
                    $failed = true;
                    $this->error('Dynamic payout '.$row->id.' dispatch is blocked.');
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
