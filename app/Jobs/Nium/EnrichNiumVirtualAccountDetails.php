<?php

namespace App\Jobs\Nium;

use App\Services\Nium\NiumVirtualAccountDetailEnrichmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EnrichNiumVirtualAccountDetails implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 60;

    public array $backoff = [60, 300, 900, 3600, 14400];

    // Only a local durable task ID is serialized into jobs / failed_jobs.
    public function __construct(public readonly int $enrichmentId) {}

    public function handle(NiumVirtualAccountDetailEnrichmentService $service): void
    {
        $service->run($this->enrichmentId);
    }
}
