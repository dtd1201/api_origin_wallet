<?php

namespace App\Console\Commands;

use App\Services\Nium\NiumApprovedDiagnosticCustomerBindingService;
use Illuminate\Console\Command;
use Throwable;

final class BindApprovedNiumDiagnosticCustomer extends Command
{
    protected $signature = 'nium:bind-approved-diagnostic-customer
        {customer-hash-id}
        {wallet-hash-id}
        {--approve= : Exact human approval marker}
        {--operator= : Operator or ticket context recorded in the audit log}';

    protected $description = 'Bind the single Su-approved diagnostic Nium customer to staging Account 7';

    public function handle(NiumApprovedDiagnosticCustomerBindingService $service): int
    {
        $operator = trim((string) $this->option('operator'));

        if ($operator === '') {
            $this->error('A non-empty --operator context is required.');

            return self::FAILURE;
        }

        try {
            $account = $service->bind(
                (string) $this->argument('customer-hash-id'),
                (string) $this->argument('wallet-hash-id'),
                (string) $this->option('approve'),
                $operator,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Approved diagnostic customer is bound to Nium provider Account {$account->id}.");

        return self::SUCCESS;
    }
}
