<?php

namespace App\Console\Commands;

use App\Models\IntegrationProvider;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Services\Integrations\ProviderTransferManager;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Console\Command;
use Throwable;

class ReconcileNiumTransfers extends Command
{
    protected $signature = 'nium:reconcile-transfers
        {--limit= : Maximum number of non-terminal Nium transfers to reconcile}';

    protected $description = 'Reconcile non-terminal Nium transfers from the authoritative remittance audit endpoint';

    public function handle(
        ProviderTransferManager $manager,
        SensitiveDataSanitizer $sanitizer,
    ): int {
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
            ->whereIn('status', [
                'pending',
                'submission_unknown',
            ])
            ->whereNotNull('external_transfer_id')
            ->where('external_transfer_id', '<>', '')
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

            if ($hasTerminalLedgerEntry) {
                $skipped++;

                $this->warn(
                    "Transfer {$transfer->id} skipped: non-terminal transfer already has a terminal ledger entry."
                );

                continue;
            }

            try {
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

                $message = (string) $sanitizer->sanitize(
                    $exception->getMessage()
                );

                $this->error(
                    "Transfer {$transfer->id} reconciliation failed: {$message}"
                );
            }
        }

        $this->info(
            "Nium reconciliation complete: ".
            "{$reconciled} reconciled, ".
            "{$skipped} skipped, ".
            "{$failed} failed."
        );

        return $failed > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
