<?php

namespace App\Console\Commands;

use App\Services\Nium\NiumApprovedDiagnosticClearStateProjectionService;
use Illuminate\Console\Command;
use Throwable;

final class ProjectApprovedNiumDiagnosticClearState extends Command
{
    protected $signature = 'nium:project-approved-diagnostic-clear-state
        {--approve= : Exact human approval marker}
        {--operator= : Operator or ticket context recorded in the audit log}';

    protected $description = 'Project the single Su-approved diagnostic Nium customer clear state on staging Account 7';

    public function handle(NiumApprovedDiagnosticClearStateProjectionService $service): int
    {
        try {
            $account = $service->project(
                (string) $this->option('approve'),
                (string) $this->option('operator'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Approved diagnostic clear state is projected for Nium provider Account {$account->id}.");

        return self::SUCCESS;
    }
}
