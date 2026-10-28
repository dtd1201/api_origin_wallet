<?php

namespace App\Console\Commands;

use App\Services\Nium\NiumVirtualAccountDetailEnrichmentService;
use Illuminate\Console\Command;

class RecoverNiumVirtualAccountDetails extends Command
{
    protected $signature = 'nium:recover-va-details {--limit=100} {--operation= : Limit recovery to one enrolled operation ID}';

    protected $description = 'Requeue due read-only VA details continuations without allocating accounts';

    public function handle(NiumVirtualAccountDetailEnrichmentService $service): int
    {
        $operation = $this->option('operation');
        $count = $service->recover((int) $this->option('limit'), $operation === null ? null : (int) $operation);
        $this->info("Queued {$count} VA detail continuations.");

        return self::SUCCESS;
    }
}
