<?php

namespace App\Console\Commands;

use App\Services\Nium\NiumCustomerReconciliationService;
use Illuminate\Console\Command;

final class ReconcileNiumCustomers extends Command
{
    protected $signature = 'nium:reconcile-customers {--limit= : Maximum number of eligible Nium customers to reconcile}';

    protected $description = 'Reconcile pending Nium Customer/KYC state through the authoritative GET Customer V5 endpoint';

    public function handle(NiumCustomerReconciliationService $service): int
    {
        if (! (bool) config('services.nium.customer_reconciliation_enabled', false)) {
            $this->info('Nium customer reconciliation is disabled.');
            return self::SUCCESS;
        }

        $option = $this->option('limit');
        $limit = is_numeric($option) ? (int) $option : null;
        $summary = $service->reconcile($limit);

        $this->info(sprintf(
            'Nium customer reconciliation complete: %d selected, %d reconciled, %d skipped, %d failed.',
            $summary['selected'], $summary['reconciled'], $summary['skipped'], $summary['failed'],
        ));

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
