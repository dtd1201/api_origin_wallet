<?php

namespace App\Console\Commands;

use App\Models\IntegrationProvider;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Services\Integrations\ProviderTransferManager;
use App\Services\Nium\NiumPayoutDebitReconciliation;
use App\Services\Nium\NiumSgdLocalOutcomeRecovery;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Console\Command;
use Throwable;

class ReconcileNiumTransfers extends Command
{
    protected $signature = 'nium:reconcile-transfers
        {--limit= : Maximum number of Nium transfers to reconcile}';

    protected $description = 'Reconcile Nium payout status and authenticated SGD source-wallet debits';

    public function handle(
        ProviderTransferManager $manager,
        SensitiveDataSanitizer $sanitizer,
        NiumSgdLocalOutcomeRecovery $recovery,
    ): int {
        if (! (bool) config('services.nium.transfer_reconciliation_enabled', false)) {
            $this->info('Nium transfer reconciliation is disabled.');

            return self::SUCCESS;
        }

        $provider = IntegrationProvider::query()
            ->whereRaw('LOWER(code) = ?', ['nium'])
            ->first();

        if ($provider === null) {
            $this->info('No Nium provider is configured.');

            return self::SUCCESS;
        }

        $configuredLimit = (int) config(
            'services.nium.transfer_reconciliation_limit',
            50
        );

        $optionLimit = $this->option('limit');

        $limit = is_numeric($optionLimit)
            ? (int) $optionLimit
            : $configuredLimit;

        $limit = max(1, min($limit, 200));

        $transfers = Transfer::query()
            ->where('provider_id', $provider->id)
            ->where(function ($query): void {
                $query->whereIn('status', [
                    'pending',
                    'submission_unknown',
                    'submitting',
                ])->orWhere(function ($terminal): void {
                    // Keep observing terminal payouts for delayed financial evidence and separate fees.
                    $terminal->where('source_currency', 'USD')->where('target_currency', 'SGD')
                        ->whereNotNull('provider_operation_key')->whereIn('status', ['completed', 'failed', 'cancelled', 'rejected']);
                });
            })
            ->where(function ($references): void {
                $references->where(function ($known): void {
                    $known->whereNotNull('external_transfer_id')->where('external_transfer_id', '<>', '');
                })->orWhere(function ($missing): void {
                    // A captured successful POST may survive a crash before reference persistence.
                    // Only the existing verifier can recover it; never issue another POST.
                    $missing->where('source_currency', 'USD')->where('target_currency', 'SGD')
                        ->whereIn('status', ['submitting', 'submission_unknown'])
                        ->whereNotNull('provider_operation_key')
                        ->whereNotNull('raw_data->nium_execution->dispatch_started_at')
                        ->where(function ($empty): void {
                            $empty->whereNull('external_transfer_id')->orWhere('external_transfer_id', '');
                        });
                });
            })
            ->with([
                'user',
                'beneficiary',
                'sourceBankAccount',
            ])
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $reconciled = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($transfers as $transfer) {
            $hasTerminalLedgerEntry = LedgerEntry::query()
                ->where('source_type', 'transfer')
                ->where('source_id', (string) $transfer->id)
                ->whereIn('entry_type', ['debit', 'release'])
                ->exists();

            if ($hasTerminalLedgerEntry && ! NiumPayoutDebitReconciliation::applies($transfer)) {
                $skipped++;

                $this->warn(
                    "Transfer {$transfer->id} skipped: non-terminal transfer already has a terminal ledger entry."
                );

                continue;
            }

            $recoveringReference = ! filled($transfer->external_transfer_id);
            try {
                if ($recoveringReference) {
                    $transfer = $recovery->recover($transfer);
                }
                $updated = $manager->syncTransferStatus(
                    $provider,
                    $transfer
                );

                $reconciled++;

                $this->line(
                    "Transfer {$updated->id}: {$updated->status}"
                );
            } catch (Throwable $exception) {
                $failed++;

                if ($recoveringReference) {
                    // Rotate unresolved captures through the bounded batch without changing
                    // their financial state or letting them permanently starve other payouts.
                    Transfer::query()->whereKey($transfer->id)
                        ->where('provider_operation_key', $transfer->provider_operation_key)
                        ->update(['updated_at' => now()]);
                }

                $message = (string) $sanitizer->sanitize(
                    $exception->getMessage()
                );

                $this->error(
                    "Transfer {$transfer->id} reconciliation failed: {$message}"
                );
            }
        }

        $this->info(
            'Nium reconciliation complete: '.
            "{$reconciled} reconciled, ".
            "{$skipped} skipped, ".
            "{$failed} failed."
        );

        return $failed > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
