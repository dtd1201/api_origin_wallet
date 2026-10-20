<?php

namespace App\Console\Commands;

use App\Models\NiumVirtualAccountOperation;
use App\Services\Nium\NiumVirtualAccountReconciliationService;
use Illuminate\Console\Command;

class ReconcileNiumPaymentIdOperation extends Command
{
    protected $signature = 'nium:reconcile-payment-id-operation {operation}';

    protected $description = 'Reconcile one Nium virtual-account assignment operation using GET requests only';

    public function handle(NiumVirtualAccountReconciliationService $service): int
    {
        $operation = NiumVirtualAccountOperation::query()->findOrFail((int) $this->argument('operation'));
        $operation = $service->reconcile($operation);

        $this->info("Operation {$operation->id} reconciliation status: {$operation->status}.");

        return self::SUCCESS;
    }
}
